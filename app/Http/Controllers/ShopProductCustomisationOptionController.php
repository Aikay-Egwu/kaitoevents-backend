<?php

namespace App\Http\Controllers;

// ShopProductCustomisationOptionController — nested admin CRUD under products/{product}.

use App\Models\Inventory;
use App\Models\ShopProductCustomisationOption;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopProductCustomisationOptionController extends Controller
{
    public function index(Inventory $product): JsonResponse
    {
        $rows = $product->customisationOptions()->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $rows->map(fn ($r) => [
                'id'          => (int)$r->id,
                'option_key'  => $r->option_key,
                'label'       => $r->label,
                'option_type' => $r->option_type,
                'is_required' => (bool)$r->is_required,
                'sort_order'  => (int)$r->sort_order,
                'choices'     => $r->choices_with_vat,
            ])->values(),
        ]);
    }

    public function store(Request $request, Inventory $product): JsonResponse
    {
        $valid = $request->validate([
            'option_key'  => ['required', 'string', 'max:40'],
            'label'       => ['required', 'string', 'max:80'],
            'option_type' => ['required', 'in:radio,select'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'choices'     => ['required', 'array', 'min:1'],
            'choices.*.value'             => ['required', 'string', 'max:80'],
            'choices.*.label'             => ['required', 'string', 'max:120'],
            'choices.*.price_delta_ex_vat' => ['required', 'numeric'],
        ]);

        $row = ShopProductCustomisationOption::create(array_merge($valid, [
            'inventory_id' => $product->id,
            'is_required'  => (bool)($valid['is_required'] ?? true),
            'sort_order'   => (int)($valid['sort_order'] ?? 1),
        ]));

        return response()->json([
            'success' => true,
            'data'    => [
                'id'          => (int)$row->id,
                'option_key'  => $row->option_key,
                'choices'     => $row->choices_with_vat,
            ],
        ], 201);
    }

    public function update(Request $request, ShopProductCustomisationOption $option): JsonResponse
    {
        $valid = $request->validate([
            'option_key'  => ['sometimes', 'required', 'string', 'max:40'],
            'label'       => ['sometimes', 'required', 'string', 'max:80'],
            'option_type' => ['sometimes', 'required', 'in:radio,select'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'choices'     => ['sometimes', 'required', 'array', 'min:1'],
            'choices.*.value'             => ['required', 'string', 'max:80'],
            'choices.*.label'             => ['required', 'string', 'max:120'],
            'choices.*.price_delta_ex_vat' => ['required', 'numeric'],
        ]);

        $option->update($valid);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'      => (int)$option->id,
                'choices' => $option->fresh()->choices_with_vat,
            ],
        ]);
    }

    public function destroy(ShopProductCustomisationOption $option): JsonResponse
    {
        $option->delete();

        return response()->json(['success' => true], 204);
    }
}
