<?php

namespace App\Http\Controllers;

// ShopProductReviewController — nested admin CRUD under products/{product}.
// Admin can manually backfill reviews or toggle approval (e.g. moderate via update is_approved=false).

use App\Models\Inventory;
use App\Models\ShopProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopProductReviewController extends Controller
{
    public function index(Inventory $product): JsonResponse
    {
        $rows = $product->allReviews()->orderByDesc('review_date')->get();

        return response()->json([
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $rows->map(fn ($r) => [
                'id'           => (int)$r->id,
                'author_name'  => $r->author_name,
                'rating'       => (int)$r->rating,
                'title'        => $r->title,
                'review_text'  => $r->review_text,
                'review_date'  => $r->review_date?->toDateString(),
                'is_approved'  => (bool)$r->is_approved,
            ])->values(),
        ]);
    }

    public function store(Request $request, Inventory $product): JsonResponse
    {
        $valid = $request->validate([
            'author_name' => ['required', 'string', 'max:80'],
            'rating'      => ['required', 'integer', 'min:1', 'max:5'],
            'title'       => ['required', 'string', 'max:160'],
            'review_text' => ['nullable', 'string'],
            'review_date' => ['required', 'date'],
            'is_approved' => ['nullable', 'boolean'],
        ]);

        $r = ShopProductReview::create(array_merge($valid, [
            'inventory_id' => $product->id,
            'is_approved'  => (bool)($valid['is_approved'] ?? true),
        ]));

        return response()->json([
            'success' => true,
            'data'    => ['id' => (int)$r->id],
        ], 201);
    }

    public function update(Request $request, ShopProductReview $review): JsonResponse
    {
        $valid = $request->validate([
            'author_name' => ['sometimes', 'required', 'string', 'max:80'],
            'rating'      => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'title'       => ['sometimes', 'required', 'string', 'max:160'],
            'review_text' => ['nullable', 'string'],
            'review_date' => ['sometimes', 'required', 'date'],
            'is_approved' => ['nullable', 'boolean'],
        ]);

        $review->update($valid);

        return response()->json(['success' => true]);
    }

    public function destroy(ShopProductReview $review): JsonResponse
    {
        $review->delete();

        return response()->json(['success' => true], 204);
    }
}
