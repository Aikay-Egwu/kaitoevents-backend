<?php

namespace App\Http\Requests\Shop;

// StoreShopProductRequest — admin payload for listing an inventory item in the shop.
// Inventory is the single source of shop products, so this validates against `inventories`
// and accepts the legacy `price_ex_vat` / `category_ids` field names the admin UI sends.

use Illuminate\Foundation\Http\FormRequest;

class StoreShopProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:255'],
            'slug'                      => ['nullable', 'string', 'max:240', 'unique:inventories,slug'],
            'tagline'                   => ['nullable', 'string', 'max:65535'],
            'description'               => ['nullable', 'string', 'max:65535'],
            // Shop retail price (ex-VAT).
            'price_ex_vat'              => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Internal hire price / cost — defaulted in the controller when omitted.
            'price'                     => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'cost_price'                => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'suitable_for_guests_min'   => ['nullable', 'integer', 'min:0', 'max:100000'],
            'suitable_for_guests_max'   => ['nullable', 'integer', 'min:0', 'max:100000'],
            'suitable_for_guests_label' => ['nullable', 'string', 'max:80'],
            'is_active'                 => ['nullable', 'boolean'],
            'inventory_category_id'     => ['required', 'integer', 'exists:inventory_categories,id'],
            'customisation_options'     => ['sometimes', 'array'],
            'customisation_options.*.option_key'  => ['required_with:customisation_options', 'string', 'max:40'],
            'customisation_options.*.label'       => ['required_with:customisation_options', 'string', 'max:80'],
            'customisation_options.*.option_type' => ['required_with:customisation_options', 'in:radio,select'],
            'customisation_options.*.is_required' => ['boolean'],
            'customisation_options.*.sort_order'  => ['integer', 'min:0'],
            'customisation_options.*.choices'     => ['required_with:customisation_options', 'array', 'min:1'],
            'customisation_options.*.choices.*.value'             => ['required', 'string', 'max:80'],
            'customisation_options.*.choices.*.label'             => ['required', 'string', 'max:120'],
            'customisation_options.*.choices.*.price_delta_ex_vat' => ['required', 'numeric', 'min:-999999.99', 'max:999999.99'],
        ];
    }

    /**
     * The admin UI posts `category_ids[]`; inventory holds a single category per item.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('inventory_category_id') && $this->has('category_ids')) {
            $ids = array_values(array_filter((array) $this->input('category_ids')));
            if (count($ids) > 0) {
                $this->merge(['inventory_category_id' => (int) $ids[0]]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'name.required'                 => 'Product name is required.',
            'price_ex_vat.required'         => 'Price (ex. VAT) is required.',
            'price_ex_vat.min'              => 'Price cannot be negative.',
            'slug.unique'                   => 'This product URL slug is already in use.',
            'inventory_category_id.required' => 'Please choose a category for this shop item.',
            'inventory_category_id.exists'  => 'The selected category does not exist.',
        ];
    }
}