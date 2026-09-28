<?php

namespace App\Http\Requests\Shop;

// UpdateShopProductRequest — admin partial update of a shop-listed inventory item.

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('product') ?? $this->route('inventory') ?? $this->route('id');
        $id = is_object($id) ? $id->getKey() : $id;

        return [
            'name'                      => ['sometimes', 'required', 'string', 'max:255'],
            'slug'                      => ['sometimes', 'nullable', 'string', 'max:240',
                                            'unique:inventories,slug,' . $id,
                                          ],
            'tagline'                   => ['nullable', 'string', 'max:65535'],
            'description'               => ['nullable', 'string', 'max:65535'],
            'price_ex_vat'              => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'price'                     => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'cost_price'                => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'suitable_for_guests_min'   => ['nullable', 'integer', 'min:0', 'max:100000'],
            'suitable_for_guests_max'   => ['nullable', 'integer', 'min:0', 'max:100000'],
            'suitable_for_guests_label' => ['nullable', 'string', 'max:80'],
            'is_active'                 => ['nullable', 'boolean'],
            'inventory_category_id'     => ['sometimes', 'integer', 'exists:inventory_categories,id'],
            'category_ids'              => ['sometimes', 'array'],
            'category_ids.*'            => ['integer', 'exists:inventory_categories,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('inventory_category_id') && $this->has('category_ids')) {
            $ids = array_values(array_filter((array) $this->input('category_ids')));
            if (count($ids) > 0) {
                $this->merge(['inventory_category_id' => (int) $ids[0]]);
            }
        }
    }
}