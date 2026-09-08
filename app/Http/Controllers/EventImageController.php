<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadImageRequest;
use App\Models\Event;
use App\Models\EventImage;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class EventImageController extends Controller
{
    protected $imageUploadService;

    public function __construct(ImageUploadService $imageUploadService)
    {
        $this->imageUploadService = $imageUploadService;
    }

    /**
     * Get all images for an event.
     */
    public function index(Request $request, Event $event): JsonResponse
    {
        $query = EventImage::where('event_id', $event->id);

        // Apply filters
        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('original_filename', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('tags', 'like', "%{$search}%");
            });
        }

        if ($request->filled('has_thumbnails')) {
            $request->has_thumbnails ? $query->withThumbnails() : $query->withoutThumbnails();
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortOrder = $request->get('sort_order', 'asc');

        $allowedSortFields = ['sort_order', 'created_at', 'file_size', 'original_filename'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = min($request->get('per_page', 15), 50);
        $images = $query->paginate($perPage);

        $imageData = $images->getCollection()->map(function ($image) {
            return $this->formatImageData($image);
        });

        return response()->json([
            'success' => true,
            'data' => $imageData,
            'pagination' => [
                'current_page' => $images->currentPage(),
                'last_page' => $images->lastPage(),
                'per_page' => $images->perPage(),
                'total' => $images->total(),
                'from' => $images->firstItem(),
                'to' => $images->lastItem(),
            ],
            'event' => [
                'id' => $event->id,
                'name' => $event->venue_name,
            ],
            'summary' => $this->getImagesSummary($event->id),
        ]);
    }

    /**
     * Upload images for an event.
     */
    public function upload(UploadImageRequest $request, Event $event): JsonResponse
    {
        try {
            $images = $request->file('images');
            $options = $request->only([
                'category',
                'description',
                'tags',
                'is_primary',
                'optimize',
                'thumbnail_sizes'
            ]);
            $options['event_id'] = $event->id;

            $results = $this->imageUploadService->uploadMultiple($images, $options);

            $successful = array_filter($results, fn($result) => $result['success']);
            $failed = array_filter($results, fn($result) => !$result['success']);

            return response()->json([
                'success' => true,
                'data' => [
                    'uploaded' => count($successful),
                    'failed' => count($failed),
                    'images' => array_map(fn($result) => $this->formatImageData($result['image']), $successful),
                    'errors' => $failed,
                ],
                'message' => sprintf(
                    'Uploaded %d images successfully%s',
                    count($successful),
                    count($failed) > 0 ? sprintf(', %d failed', count($failed)) : ''
                ),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'IMAGE_UPLOAD_FAILED',
                    'message' => 'Failed to upload images.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Show a specific image.
     */
    public function show(EventImage $image): JsonResponse
    {
        $image->load(['event', 'uploadedBy']);

        return response()->json([
            'success' => true,
            'data' => $this->formatImageData($image, true),
        ]);
    }

    public function getEventImages(Event $event): JsonResponse
    {
        $images = EventImage::where('event_id', $event->id)->get();
        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\EventImageResource::collection($images),
        ]);
    }

    /**
     * Update image metadata.
     */
    public function update(
        Request $request,
        EventImage $image
    ): JsonResponse {
        $request->validate([
            'category' => 'sometimes|string|in:venue,setup,food,decorations,other',
            'description' => 'sometimes|string|max:500',
            'tags' => 'sometimes|array|max:10',
            'tags.*' => 'string|max:50',
            'is_primary' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:1',
        ]);

        try {
            $updateData = $request->only(['category', 'description', 'is_primary', 'sort_order']);

            if ($request->has('tags')) {
                $updateData['tags'] = json_encode($request->tags);
            }

            $image->update($updateData);

            // Handle primary image logic
            if ($request->boolean('is_primary')) {
                $this->imageUploadService->setAsPrimary($image);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatImageData($image->fresh()),
                'message' => 'Image updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'IMAGE_UPDATE_FAILED',
                    'message' => 'Failed to update image.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Delete an image.
     */
    public function destroy(EventImage $image): JsonResponse
    {
        try {
            $imageId = $image->id;
            $eventId = $image->event_id;

            $deleted = $this->imageUploadService->deleteImage($image);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Image deleted successfully.',
                    'data' => [
                        'image_id' => $imageId,
                        'event_id' => $eventId,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'IMAGE_DELETE_FAILED',
                        'message' => 'Failed to delete image files.',
                    ],
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'IMAGE_DELETE_ERROR',
                    'message' => 'Error occurred while deleting image.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Set image as primary.
     */
    public function setPrimary(EventImage $image): JsonResponse
    {
        try {
            $this->imageUploadService->setAsPrimary($image);

            return response()->json([
                'success' => true,
                'data' => $this->formatImageData($image->fresh()),
                'message' => 'Image set as primary successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SET_PRIMARY_FAILED',
                    'message' => 'Failed to set image as primary.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Reorder images.
     */
    public function reorder(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:event_images,id',
        ]);

        try {
            $success = $this->imageUploadService->reorderImages($event->id, $request->order);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Images reordered successfully.',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'REORDER_FAILED',
                        'message' => 'Failed to reorder images.',
                    ],
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REORDER_ERROR',
                    'message' => 'Error occurred while reordering images.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get storage statistics for an event.
     */
    public function statistics(Request $request, Event $event): JsonResponse
    {
        $stats = $this->imageUploadService->getStorageStats($event->id);

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->venue_name,
                ],
                'statistics' => $stats,
                'formatted' => [
                    'total_size' => $this->formatBytes($stats['total_size']),
                ],
            ],
        ]);
    }

    /**
     * Bulk delete images.
     */
    public function bulkDelete(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'integer|exists:event_images,id',
        ]);

        try {
            $images = EventImage::where('event_id', $event->id)
                ->whereIn('id', $request->image_ids)
                ->get();

            $deleted = 0;
            $errors = [];

            foreach ($images as $image) {
                try {
                    if ($this->imageUploadService->deleteImage($image)) {
                        $deleted++;
                    } else {
                        $errors[] = "Failed to delete image {$image->id}";
                    }
                } catch (\Exception $e) {
                    $errors[] = "Error deleting image {$image->id}: " . $e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'deleted' => $deleted,
                    'total' => count($request->image_ids),
                    'errors' => $errors,
                ],
                'message' => sprintf('Deleted %d of %d images', $deleted, count($request->image_ids)),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'BULK_DELETE_FAILED',
                    'message' => 'Failed to delete images.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Format image data for API response.
     */
    private function formatImageData(EventImage $image, bool $includeEvent = false): array
    {
        $data = [
            'id' => $image->id,
            'filename' => $image->filename,
            'original_filename' => $image->original_filename,
            'file_size' => $image->file_size,
            'formatted_file_size' => $image->getFormattedFileSize(),
            'mime_type' => $image->mime_type,
            'category' => $image->category,
            'category_label' => $image->getCategoryLabel(),
            'category_color' => $image->getCategoryColor(),
            'description' => $image->description,
            'tags' => $image->getTagsArray(),
            'is_primary' => $image->is_primary,
            'sort_order' => $image->sort_order,
            'dimensions' => $image->getDimensionsArray(),
            'urls' => [
                'original' => $image->getImageUrl(),
                'thumbnails' => $image->getThumbnailUrls(),
            ],
            'uploaded_at' => $image->created_at->format('Y-m-d H:i:s'),
            'uploaded_by' => $image->getUploaderName(),
        ];

        if ($includeEvent && $image->relationLoaded('event')) {
            $data['event'] = [
                'id' => $image->event->id,
                'name' => $image->event->venue_name,
                'date' => $image->event->event_date?->format('Y-m-d'),
            ];
        }

        return $data;
    }

    /**
     * Get images summary for an event.
     */
    private function getImagesSummary(int $eventId): array
    {
        $images = EventImage::where('event_id', $eventId)->get();

        return [
            'total_images' => $images->count(),
            'primary_image' => $images->where('is_primary', true)->count(),
            'total_size' => $images->sum('file_size'),
            'categories' => $images->groupBy('category')->map->count(),
            'by_mime_type' => $images->groupBy('mime_type')->map->count(),
            'recent_uploads' => $images->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}