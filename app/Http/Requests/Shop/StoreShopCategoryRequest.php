<?php

namespace App\Http\Requests\Shop;

// StoreShopCategoryRequest — admin: publish an inventory category to the shop.
// Categories live in `inventory_categories`; the shop exposes those flagged `for_shop`.

use Illuminate\Foundation\Http\FormRequest;

class StoreShopCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Sanctum middleware already gates admin routes.
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:160', 'unique:inventory_categories,slug'],
            'description'    => ['nullable', 'string', 'max:255'],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'parent_id'      => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'for_shop'       => ['nullable', 'boolean'],
            // Accepted for admin-UI compatibility; mapped onto `for_shop`.
            'is_active'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required.',
            'slug.unique'   => 'This category URL slug is already in use.',
        ];
    }
}