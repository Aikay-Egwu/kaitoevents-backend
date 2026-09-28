<?php

namespace App\Http\Requests\Shop;

// CreateShopOrderRequest — public POST order validation.
//   R-7  : UK postcode regex, UK phone 10..18 chars, email RFC, items[].product exists+active
//   R-7  : TOTAL_MISMATCH 422 when grand_total drifts > 1p from server recomputation
//   R-8  : KE-YYYYMMDD-XXXX reference (5 collision retries) via controller store()

use App\Models\Inventory;
use App\Models\ShopOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class CreateShopOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public anonymous POST.
    }

    public function rules(): array
    {
        return [
            // Customer identity
            'customer_full_name'       => ['required', 'string', 'max:160'],
            'customer_email'           => ['required', 'email:rfc', 'max:200'],
            'customer_phone'           => ['required', 'string', 'regex:/^[+\d\s()-]{10,18}$/'],

            // Billing address (GB)
            'billing_address_line_1'   => ['required', 'string', 'max:255'],
            'billing_address_line_2'   => ['nullable', 'string', 'max:255'],
            'billing_city'             => ['required', 'string', 'max:100'],
            'billing_postcode'         => ['required', 'string', 'regex:/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/i'],
            'billing_country'          => ['required', 'string', 'size:2'],

            // Event context (optional DIY event date/venue)
            'event_date'               => ['nullable', 'date'],
            'event_venue'              => ['nullable', 'string', 'max:255'],

            // Payment & totals
            'payment_method'           => ['required', 'in:card,bank_transfer,on_arrival'],
            'shipping_amount'          => ['required', 'numeric', 'min:0'],
            'subtotal_ex_vat'          => ['required', 'numeric', 'min:0'],
            'vat_amount'               => ['required', 'numeric', 'min:0'],
            'grand_total'              => ['required', 'numeric', 'min:0'],

            // Notes
            'notes'                    => ['nullable', 'string', 'max:2000'],
            'source_ref'               => ['nullable', 'string', 'max:80'],

            // Items
            'items'                    => ['required', 'array', 'min:1', 'max:50'],
            'items.*.inventory_id'     => ['required', 'integer', 'min:1', 'exists:inventories,id'],
            'items.*.quantity'         => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.unit_price_ex_vat' => ['required', 'numeric', 'min:0'],
            'items.*.customisations'   => ['nullable', 'array'],
            'items.*.customisations.*.option_key' => ['required_with:items.*.customisations', 'string', 'max:40'],
            'items.*.customisations.*.value'      => ['required_with:items.*.customisations', 'string', 'max:80'],
            'items.*.customisations.*.label'      => ['nullable', 'string', 'max:120'],
            'items.*.customisations.*.price_delta_inc_vat' => ['nullable', 'numeric'],
        ];
    }

    /**
     * R-7: server-side recompute totals with 1p tolerance. Also recheck each item's unit_price matches DB active product.
     *
     * @throws ValidationException
     */
    protected function passedValidation(): void
    {
        $data = $this->validated();

        // (A) Each referenced item must exist, be active + shop-listed, and unit_price_ex_vat must match the DB shop price.
        $itemIds = array_values(array_unique(array_map(
            fn (array $item) => (int)$item['inventory_id'],
            $data['items']
        )));
        /** @var Inventory[] $items */
        $items = Inventory::whereIn('id', $itemIds)
            ->where('is_active', true)
            ->where('for_shop', true)
            ->get()
            ->keyBy('id');

        foreach ($data['items'] as $i => $line) {
            $iid = (int)$line['inventory_id'];
            if (!isset($items[$iid])) {
                throw ValidationException::withMessages([
                    "items.{$i}.inventory_id" => "Item #{$iid} is not available for purchase.",
                ]);
            }
            $expectedUnit = round((float) $items[$iid]->shop_price, 2);
            $gotUnit      = round((float) $line['unit_price_ex_vat'], 2);
            if (abs($expectedUnit - $gotUnit) > 0.01) {
                throw ValidationException::withMessages([
                    "items.{$i}.unit_price_ex_vat" => sprintf(
                        'Price mismatch for item %s: expected %.2f, got %.2f.',
                        $items[$iid]->slug,
                        $expectedUnit,
                        $gotUnit
                    ),
                ]);
            }
        }

        // (B) Recompute totals; throw TOTAL_MISMATCH if >1p drift on grand_total.
        $expected = ShopOrder::recomputeTotalsFromItems($data['items'], (float)$data['shipping_amount']);

        $grandExpected = (float)$expected['grand_total'];
        $grandSubmitted = (float)$data['grand_total'];
        if (abs($grandExpected - $grandSubmitted) > 0.01) {
            throw ValidationException::withMessages([
                'grand_total' => 'Order total does not match server recomputation.',
            ])->errorBag('default')
              ->redirectTo('');
        }
    }

    public function messages(): array
    {
        return [
            'customer_full_name.required'       => 'Full name is required.',
            'customer_email.required'           => 'Email address is required.',
            'customer_email.email'              => 'Please enter a valid email address.',
            'customer_phone.regex'              => 'Please enter a valid UK phone number (10–18 digits, may include +, spaces, hyphens, parentheses).',
            'billing_postcode.regex'            => 'Please enter a valid UK postcode (e.g. SW1A 1AA).',
            'billing_country.size'              => 'Country must be a 2-letter ISO code (e.g. GB).',
            'items.required'                    => 'Please add at least one item to the order.',
            'items.*.inventory_id.exists'       => 'One of the items you selected does not exist.',
            'items.*.quantity.min'              => 'Quantity must be at least 1.',
            'items.*.quantity.max'              => 'Maximum quantity per item is 99.',
            'payment_method.in'                 => 'Selected payment method is not supported.',
        ];
    }
}
