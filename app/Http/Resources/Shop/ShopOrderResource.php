<?php

namespace App\Http\Resources\Shop;

// ShopOrderResource — detail payload returned after POST create (admin + customer lookup share shape).

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity & status
            'id'            => (int) $this->id,
            'order_ref'     => $this->order_ref,
            'status'        => $this->status,
            'payment_method'=> $this->payment_method,
            'source_ref'    => $this->source_ref,

            // Customer (PII — returned only for correct customer_email pair OR admin)
            'customer_full_name' => $this->customer_full_name,
            'customer_email'     => $this->customer_email,
            'customer_phone'     => $this->customer_phone,

            // Billing address
            'billing' => [
                'line_1'   => $this->billing_address_line_1,
                'line_2'   => $this->billing_address_line_2,
                'city'     => $this->billing_city,
                'postcode' => $this->billing_postcode,
                'country'  => $this->billing_country,
            ],

            // Event context
            'event_date'  => $this->event_date?->toDateString(),
            'event_venue' => $this->event_venue,
            'notes'       => $this->notes,

            // Financials (formatted + raw)
            'subtotal_ex_vat'   => number_format((float)$this->subtotal_ex_vat, 2, '.', ''),
            'subtotal_ex_vat_raw' => (float)$this->subtotal_ex_vat,
            'vat_amount'        => number_format((float)$this->vat_amount, 2, '.', ''),
            'vat_amount_raw'    => (float)$this->vat_amount,
            'shipping_amount'   => number_format((float)$this->shipping_amount, 2, '.', ''),
            'shipping_amount_raw' => (float)$this->shipping_amount,
            'grand_total'       => number_format((float)$this->grand_total, 2, '.', ''),
            'grand_total_raw'   => (float)$this->grand_total,

            // Line items
            'items' => $this->whenLoaded('items', function () {
                return ShopOrderItemResource::collection($this->items);
            }),

            // Payment transactions (admin only — but returned here if admin viewer or POST just-created)
            'payment_transactions' => $this->whenLoaded('paymentTransactions', function () {
                return ShopPaymentTransactionResource::collection($this->paymentTransactions);
            }),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
