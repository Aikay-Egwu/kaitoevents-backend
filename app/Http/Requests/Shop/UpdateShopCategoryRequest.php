<?php

namespace App\Http\Requests\Shop;

// UpdateShopCategoryRequest — admin partial update of a shop-listed inventory category.

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category') ?? $this->route('inventory_category') ?? $this->route('id');
        $categoryId = is_object($categoryId) ? $categoryId->getKey() : $categoryId;

        return [
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'slug'           => ['sometimes', 'nullable', 'string', 'max:160',
                                    'unique:inventory_categories,slug,' . $categoryId,
                                ],
            'description'    => ['nullable', 'string', 'max:255'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'parent_id'      => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'for_shop'       => ['nullable', 'boolean'],
            // Accepted for admin-UI compatibility; mapped onto `for_shop`.
            'is_active'      => ['nullable', 'boolean'],
        ];
    }
}