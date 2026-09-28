<?php

namespace App\Http\Requests\Client;

use App\Models\Inventory;
use Illuminate\Foundation\Http\FormRequest;

class AddBasketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'selected_options' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $item = Inventory::where('id', $this->input('inventory_id'))
                ->where('for_shop', true)
                ->where('is_active', true)
                ->first();

            if (!$item) {
                $validator->errors()->add('inventory_id', 'This item is not available in the shop.');
            }
        });
    }
}