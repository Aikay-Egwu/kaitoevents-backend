<?php

namespace App\Http\Resources\Shop;

// ShopPaymentTransactionResource — stores references only. Never exposes raw PAN/CVC (no such columns exist).

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopPaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => (int) $this->id,
            'transaction_ref'     => $this->transaction_ref,
            'provider'            => $this->provider,
            'provider_payment_id' => $this->provider_payment_id, // pi_xxx ref only
            'status'              => $this->status,
            'amount'              => number_format((float)$this->amount, 2, '.', ''),
            'amount_raw'          => (float)$this->amount,
            'currency'            => $this->currency,
            'failure_reason'      => $this->failure_reason,
            // raw_metadata intentionally SKIPPED in default response — may contain processor
            // internal references; only exposed if caller is admin via explicit include_metadata flag.
            'metadata_keys'       => $this->when($request->boolean('include_metadata') && is_array($this->raw_metadata), function () {
                return array_keys($this->raw_metadata);
            }),
            'processed_at'        => $this->processed_at?->toISOString(),
            'created_at'          => $this->created_at?->toISOString(),
        ];
    }
}
