<?php

namespace App\Services;

use App\Models\EventImage;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class ImageUploadService
{
    //protected $disk;
    protected $basePath;

    public function __construct()
    {
        //$this->disk = config('filesystems.default', 'public');
        $this->basePath = 'events'; // Match EventController path structure
    }

    /**
     * Upload multiple images for an event.
     */
    public function uploadMultiple(array $images, array $options = []): array
    {
        $results = [];

        foreach ($images as $index => $image) {
            try {
                // If sort_order isn't provided, use loop index
                $imageOptions = array_merge($options, [
                    'sort_order' => $options['sort_order'] ?? ($index + 1),
                    'is_primary' => isset($options['is_primary']) ? ($options['is_primary'] && $index === 0) : false,
                ]);

                $results[] = $this->uploadSingle($image, $imageOptions);
            } catch (Exception $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'filename' => $image->getClientOriginalName(),
                ];
            }
        }

        return $results;
    }

    /**
     * Upload a single image for an event.
     */
    public function uploadSingle(UploadedFile $file, array $options = []): array
    {
        $eventId = $options['event_id'];

        // Validate file (basic validation)
        if (!$file->isValid()) {
            throw new Exception('Invalid file upload.');
        }

        // Generate filename consistent with EventController
        $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Store file
        // EventController used: $path = $image->storeAs('public/events/' . $event->id, $filename);
        $path = $file->storeAs($this->basePath . '/' . $eventId, $filename, 'public');
        //$storedPath = $file->storeAs($uploadPath, $filename, 'public');

        // Get dimensions
        $dimensions = null;
        try {
            [$width, $height] = getimagesize($file->getPathname());
            $dimensions = "{$width}x{$height}";
        } catch (\Exception $e) {
            // Ignore
        }

        // Create database record
        $image = EventImage::create([
            'event_id' => $eventId,
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => 'events/' . $eventId . '/' . $filename, // Matches what frontend expects/EventController logic
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $options['category'] ?? 'other',
            'description' => $options['description'] ?? null,
            'tags' => isset($options['tags']) ? json_encode($options['tags']) : null,
            'is_primary' => $options['is_primary'] ?? false,
            'sort_order' => $options['sort_order'] ?? 1,
            'thumbnails' => null, // No thumbnails without Intervention
            'uploaded_by' => auth()->id(),
            'dimensions' => $dimensions,
        ]);

        // Handle primary image logic if this one is primary
        if ($image->is_primary) {
            $this->setAsPrimary($image);
        }

        return [
            'success' => true,
            'image' => $image,
            'url' => $this->getImageUrl($image->file_path),
        ];
    }

    /**
     * Delete an image and its associated files.
     */
    public function deleteImage(EventImage $image): bool
    {
        try {
            // Delete file from storage
            // Note: EventController stored in public/events/ID/file
            // EventImage path is events/ID/file (relative to storage root usually, or public disk root)
            // If using 'public' disk, path should be relative to it.
            // Let's assume standard Laravel storage linking.

            // If the path in DB is 'events/1/xy.jpg' and it's in 'public' disk
            if (Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
            }
            // Fallback check if stored with 'public/' prefix in DB
            elseif (Storage::exists('public/' . $image->file_path)) {
                Storage::delete('public/' . $image->file_path);
            }

            // Delete database record
            $image->delete();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Set an image as primary for an event.
     */
    public function setAsPrimary(EventImage $image): void
    {
        // Remove primary flag from other images
        EventImage::where('event_id', $image->event_id)
            ->where('id', '!=', $image->id)
            ->update(['is_primary' => false]);

        // Set this image as primary
        $image->update(['is_primary' => true]);
    }

    /**
     * Get image URL.
     */
    public function getImageUrl(string $path): string
    {
        return asset('storage/' . $path);
    }

    /**
     * Get storage statistics for an event.
     */
    public function getStorageStats(int $eventId): array
    {
        $images = EventImage::where('event_id', $eventId)->get();

        return [
            'total_images' => $images->count(),
            'total_size' => $images->sum('file_size'),
            'primary_image' => $images->where('is_primary', true)->first(),
            'categories' => $images->groupBy('category')->map->count(),
            'uploaded_by' => $images->groupBy('uploaded_by')->map->count(),
        ];
    }

    /**
     * Reorder images for an event.
     */
    public function reorderImages(int $eventId, array $order): bool
    {
        try {
            foreach ($order as $index => $imageId) {
                EventImage::where('id', $imageId)
                    ->where('event_id', $eventId)
                    ->update(['sort_order' => $index + 1]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}