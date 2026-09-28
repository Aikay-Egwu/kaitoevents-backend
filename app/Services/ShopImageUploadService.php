<?php

namespace App\Services;

// ShopImageUploadService — shop gallery uploads to `public` disk at shop/products/{inventoryId}/{uuid}.{ext}.
// Max 8 MB per file; mime jpg/png/webp only. UUID basename prevents path traversal; relative path stored in DB.

use App\Models\Inventory;
use App\Models\ShopProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Exceptions\ImageUploadFailedException;

class ShopImageUploadService
{
    public const DISK_NAME = 'public';
    public const BASE_PREFIX = 'shop/products';
    public const MAX_SIZE_BYTES = 8 * 1024 * 1024; // 8 MB
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    public const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    protected string $disk;

    public function __construct()
    {
        $this->disk = self::DISK_NAME;
    }

    /**
     * Base directory prefix for a shop-listed inventory item (without trailing slash).
     */
    public function productBaseDir(Inventory $item): string
    {
        return self::BASE_PREFIX . '/' . $item->id;
    }

    /**
     * Upload an array of UploadedFile objects as an item's gallery.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, ShopProductImage>
     */
    public function uploadMultiple(Inventory $item, array $files, ?int $primaryIndex = 0): array
    {
        $results = [];
        foreach ($files as $i => $file) {
            $sortOrder = $i + 1;
            $isPrimary = ($primaryIndex !== null && $i === $primaryIndex);
            $results[] = $this->uploadSingle($item, $file, $sortOrder, $isPrimary);
        }

        return $results;
    }

    /**
     * Upload one image, validate size/mime, persist using UUID filename, return ShopProductImage row.
     *
     * @throws ImageUploadFailedException If file invalid / too large / wrong mime.
     */
    public function uploadSingle(Inventory $item, UploadedFile $file, int $sortOrder, bool $isPrimary): ShopProductImage
    {
        $this->validateFile($file);

        $ext    = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin'));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new ImageUploadFailedException("Unsupported image extension: $ext");
        }

        $filename = Str::uuid()->toString() . '.' . $ext;
        $dir      = $this->productBaseDir($item);
        $relPath  = $file->storeAs($dir, $filename, $this->disk);
        if (!$relPath) {
            throw new ImageUploadFailedException('Failed to write file to storage disk.');
        }

        $alt = $file->getClientOriginalName();
        if (str_ends_with($alt, '.' . $ext)) {
            $alt = substr($alt, 0, -strlen('.' . $ext));
        }

        // Clear previous primary flag if we're about to insert a new primary image.
        if ($isPrimary) {
            ShopProductImage::where('inventory_id', $item->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // If the item has NO primary yet, promote this first upload automatically.
        $hasPrimary = ShopProductImage::where('inventory_id', $item->id)
            ->where('is_primary', true)
            ->exists();
        $makePrimary = $isPrimary || !$hasPrimary;

        /** @var ShopProductImage $row */
        $row = ShopProductImage::create([
            'inventory_id' => $item->id,
            'path'         => $relPath,
            'alt_text'     => $alt,
            'sort_order'   => $sortOrder,
            'is_primary'   => $makePrimary,
        ]);

        return $row;
    }

    /**
     * Delete the underlying disk file AND the image row.
     */
    public function deleteImage(ShopProductImage $image): void
    {
        $this->deleteFileIfExists($image->path);
        $image->delete();
    }

    /**
     * Promote a specific image to primary; strip primary flag from all sibling images of the same item.
     */
    public function setPrimary(ShopProductImage $image): void
    {
        ShopProductImage::where('inventory_id', $image->inventory_id)
            ->where('id', '!=', $image->id)
            ->update(['is_primary' => false]);

        $image->update(['is_primary' => true, 'sort_order' => 1]);
    }

    /**
     * Delete ALL disk files associated with an item (called from Inventory::deleting hook).
     */
    public function deleteAllForProduct(Inventory $item): void
    {
        $dir = $this->productBaseDir($item);
        $disk = Storage::disk($this->disk);
        $files = $disk->files($dir);
        if (count($files) > 0) {
            $disk->delete($files);
        }
        if ($disk->exists($dir)) {
            $disk->deleteDirectory($dir);
        }
    }

    /**
     * Basic pre-upload validation: isValid, size <= 8 MB, mime in whitelist.
     */
    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ImageUploadFailedException('Uploaded file is not valid (UPLOAD_ERR non-zero).');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new ImageUploadFailedException('File exceeds max size of 8 MB.');
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            throw new ImageUploadFailedException('MIME type not allowed (must be jpg/png/webp).');
        }
    }

    protected function deleteFileIfExists(string $path): void
    {
        $disk = Storage::disk($this->disk);
        if ($path !== '' && $disk->exists($path)) {
            $disk->delete($path);
        }
    }
}