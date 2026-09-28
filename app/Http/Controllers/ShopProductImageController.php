<?php

namespace App\Http\Controllers;

// ShopProductImageController — admin: upload image files to a shop-listed inventory item,
// remove an image, or promote to primary. Uses ShopImageUploadService for size/mime/UUID enforcement.

use App\Models\Inventory;
use App\Models\ShopProductImage;
use App\Services\ShopImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopProductImageController extends Controller
{
    public function __construct(protected ShopImageUploadService $svc) {}

    /**
     * POST /api/admin/shop/products/{product}/images — accept `images[]` as multipart form data.
     */
    public function store(Request $request, Inventory $product): JsonResponse
    {
        $valid = $request->validate([
            'images'            => ['required', 'array', 'min:1', 'max:20'],
            'images.*'          => ['image', 'max:8192'], // 8 MB
            'primary_index'     => ['nullable', 'integer', 'min:0'],
            'alt_texts'         => ['nullable', 'array'],
            'alt_texts.*'       => ['nullable', 'string', 'max:200'],
        ]);

        $primaryIndex = isset($valid['primary_index']) ? (int)$valid['primary_index'] : 0;
        $rows = $this->svc->uploadMultiple($product, $valid['images'], $primaryIndex);

        // Apply alt_texts[] override if caller provided.
        if (isset($valid['alt_texts']) && is_array($valid['alt_texts'])) {
            foreach ($rows as $idx => $row) {
                if (isset($valid['alt_texts'][$idx]) && trim((string)$valid['alt_texts'][$idx]) !== '') {
                    $row->update(['alt_text' => (string)$valid['alt_texts'][$idx]]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'count'   => count($rows),
            'images'  => $rows->map(function ($r) {
                return [
                    'id'         => (int)$r->id,
                    'path'       => $r->path,
                    'url'        => $r->url,
                    'alt_text'   => $r->alt_text,
                    'is_primary' => (bool)$r->is_primary,
                    'sort_order' => (int)$r->sort_order,
                ];
            })->values(),
        ], 201);
    }

    /**
     * DELETE /api/admin/shop/product-images/{imageId} — removes file from disk + DB row.
     */
    public function destroy(ShopProductImage $imageId): JsonResponse
    {
        $this->svc->deleteImage($imageId);

        return response()->json(['success' => true], 204);
    }

    /**
     * POST /api/admin/shop/product-images/{imageId}/primary — promotes to primary, demotes siblings.
     */
    public function setPrimary(ShopProductImage $imageId): JsonResponse
    {
        $this->svc->setPrimary($imageId);

        return response()->json([
            'success' => true,
            'image'   => [
                'id'         => (int)$imageId->fresh()?->id,
                'is_primary' => true,
            ],
        ]);
    }
}
