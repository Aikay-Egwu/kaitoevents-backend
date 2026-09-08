<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Resources\Json\JsonResource;

class EventImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_path' => Storage::url($this->file_path), // Returning full URL as requested
            //'file_path' => $this->getImageUrl(), // Returning full URL as requested
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->getFormattedFileSize(),
            'mime_type' => $this->mime_type,
            'category' => $this->category,
            'category_label' => $this->getCategoryLabel(),
            'category_color' => $this->getCategoryColor(),
            'description' => $this->description,
            'tags' => $this->getTagsArray(),
            'is_primary' => (bool) $this->is_primary,
            'sort_order' => $this->sort_order,
            'dimensions' => $this->getDimensionsArray(),
            'urls' => [
                'original' => $this->getImageUrl(),
                'thumbnails' => $this->getThumbnailUrls(),
            ],
            'uploaded_at' => $this->created_at->toDateTimeString(),
            'uploaded_by' => $this->getUploaderName(),
            'event_id' => $this->event_id,
        ];
    }
}
