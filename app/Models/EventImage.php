<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventImage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'event_id',
        'filename',
        'original_filename',
        'file_path',
        'file_size',
        'mime_type',
        'category',
        'description',
        'tags',
        'is_primary',
        'sort_order',
        'thumbnails',
        'uploaded_by',
        'dimensions',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'file_size' => 'integer',
        'tags' => 'array',
        'thumbnails' => 'array',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Get the event that owns the image.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the user who uploaded the image.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope for primary images.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope for images by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for ordered images.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->file_size;

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

    /**
     * Get image URL.
     */
    public function getImageUrl(): string
    {
        return asset('storage/' . $this->file_path);
    }

    /**
     * Get thumbnail URLs.
     */
    public function getThumbnailUrls(): array
    {
        $urls = [];
        $thumbnails = $this->thumbnails ?? [];

        // If thumbnails exist, use them
        if (!empty($thumbnails)) {
            foreach ($thumbnails as $size => $thumbnail) {
                $urls[$size] = asset('storage/' . $thumbnail['path']);
            }
        } else {
            // Fallback to original image for standard sizes if thumbnails don't exist
            // This prevents UI breakage when using the new native upload service
            $originalUrl = $this->getImageUrl();
            $urls = [
                'small' => $originalUrl,
                'medium' => $originalUrl,
                'large' => $originalUrl,
                'xl' => $originalUrl,
            ];
        }

        return $urls;
    }

    /**
     * Get thumbnail URL for specific size.
     */
    public function getThumbnailUrl(string $size): ?string
    {
        $thumbnails = $this->thumbnails ?? [];

        if (isset($thumbnails[$size])) {
            return asset('storage/' . $thumbnails[$size]['path']);
        }

        // Fallback to original image
        return $this->getImageUrl();
    }

    /**
     * Get image dimensions as array.
     */
    public function getDimensionsArray(): ?array
    {
        if (!$this->dimensions) {
            return null;
        }

        $parts = explode('x', $this->dimensions);
        if (count($parts) === 2) {
            return [
                'width' => (int) $parts[0],
                'height' => (int) $parts[1],
            ];
        }

        return null;
    }

    /**
     * Get tags as array.
     */
    public function getTagsArray(): array
    {
        return $this->tags ?? [];
    }

    /**
     * Check if image has specific tag.
     */
    public function hasTag(string $tag): bool
    {
        $tags = $this->getTagsArray();
        return in_array($tag, $tags);
    }

    /**
     * Get category label.
     */
    public function getCategoryLabel(): string
    {
        $labels = [
            'venue' => 'Venue',
            'setup' => 'Setup',
            'food' => 'Food & Beverages',
            'decorations' => 'Decorations',
            'other' => 'Other',
        ];

        return $labels[$this->category] ?? 'Unknown';
    }

    /**
     * Get category color.
     */
    public function getCategoryColor(): string
    {
        $colors = [
            'venue' => 'blue',
            'setup' => 'green',
            'food' => 'orange',
            'decorations' => 'purple',
            'other' => 'gray',
        ];

        return $colors[$this->category] ?? 'gray';
    }

    /**
     * Check if image is optimized.
     */
    public function isOptimized(): bool
    {
        return $this->mime_type === 'image/jpeg' || $this->mime_type === 'image/webp';
    }

    /**
     * Get upload date formatted.
     */
    public function getUploadDateFormatted(): string
    {
        return $this->created_at->format('M d, Y H:i');
    }

    /**
     * Get uploader name.
     */
    public function getUploaderName(): ?string
    {
        return $this->uploadedBy?->name;
    }

    /**
     * Scope for images with thumbnails.
     */
    public function scopeWithThumbnails($query)
    {
        return $query->whereNotNull('thumbnails');
    }

    /**
     * Scope for images without thumbnails.
     */
    public function scopeWithoutThumbnails($query)
    {
        return $query->whereNull('thumbnails');
    }

    /**
     * Scope for large images.
     */
    public function scopeLarge($query)
    {
        return $query->where('file_size', '>', 1024 * 1024); // > 1MB
    }

    /**
     * Scope for recent uploads.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Get next image in sequence.
     */
    public function getNextImage(): ?EventImage
    {
        return EventImage::where('event_id', $this->event_id)
            ->where('sort_order', '>', $this->sort_order)
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * Get previous image in sequence.
     */
    public function getPreviousImage(): ?EventImage
    {
        return EventImage::where('event_id', $this->event_id)
            ->where('sort_order', '<', $this->sort_order)
            ->orderByDesc('sort_order')
            ->first();
    }
}