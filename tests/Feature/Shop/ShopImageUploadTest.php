<?php

namespace Tests\Feature\Shop;

// @group shop-new
// T3 R-3 / NFR-5: image uploads write to disk, primary flag set, delete product wipes files, non-image rejected.
//
// Shop gallery images belong to Inventory rows (the shop's single product source) via
// `shop_product_images.inventory_id`.

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\Inventory;
use App\Models\ShopProductImage;
use App\Exceptions\ImageUploadFailedException;
use App\Services\ShopImageUploadService;

class ShopImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ShopImageUploadService::DISK_NAME);
    }

    /** @test */
    public function three_uploads_saved_first_is_primary_and_files_exist_on_disk()
    {
        $product = Inventory::factory()->forShop(75.00)->create();
        $images = [
            UploadedFile::fake()->image('hero.jpg', 1200, 800)->size(250), // KB
            UploadedFile::fake()->image('detail.jpg', 800, 800)->size(180),
            UploadedFile::fake()->image('setup.png', 1024, 768, 3)->size(300),
        ];

        $svc = app(ShopImageUploadService::class);
        $rows = $svc->uploadMultiple($product, $images, 0);

        $this->assertCount(3, $rows);
        // First upload promoted to primary.
        $this->assertTrue((bool)$rows[0]->is_primary, 'First upload should be primary');
        // All three paths exist on fake disk.
        foreach ($rows as $r) {
            Storage::disk(ShopImageUploadService::DISK_NAME)->assertExists($r->path);
            $this->assertEquals($product->id, $r->inventory_id);
        }
        // Sort orders are sequential 1,2,3.
        $this->assertEquals(1, $rows[0]->sort_order);
        $this->assertEquals(2, $rows[1]->sort_order);
        $this->assertEquals(3, $rows[2]->sort_order);
    }

    /** @test */
    public function deleting_inventory_item_removes_all_three_files_from_disk()
    {
        $product = Inventory::factory()->forShop(75.00)->create();
        $svc = app(ShopImageUploadService::class);
        $paths = [];
        $rows = $svc->uploadMultiple($product, [
            UploadedFile::fake()->image('a.jpg')->size(100),
            UploadedFile::fake()->image('b.webp')->size(100),
            UploadedFile::fake()->image('c.png')->size(100),
        ]);
        foreach ($rows as $r) {
            $paths[] = $r->path;
        }
        // All on disk before delete.
        foreach ($paths as $p) {
            Storage::disk(ShopImageUploadService::DISK_NAME)->assertExists($p);
        }
        // Deleting the inventory item triggers booted() hook → deleteAllForProduct service call.
        $product->delete();

        foreach ($paths as $p) {
            $this->assertFalse(
                Storage::disk(ShopImageUploadService::DISK_NAME)->exists($p),
                "File $p should have been removed on product delete, but it still exists."
            );
        }
        // DB rows also cascade delete thanks to FK on migration.
        $this->assertEquals(0, ShopProductImage::where('inventory_id', $product->id)->count());
    }

    /** @test */
    public function non_image_php_file_rejected_before_disk_write()
    {
        $product = Inventory::factory()->forShop(75.00)->create();
        // Craft a file that has `text/x-php` mime and .php extension — not in jpg/png/webp whitelist.
        $phpFile = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'nope_php_'),
            'exploit.php',
            'text/x-php',
            null,
            true
        );

        $this->expectException(ImageUploadFailedException::class);
        $svc = app(ShopImageUploadService::class);
        try {
            $svc->uploadSingle($product, $phpFile, 1, false);
        } catch (ImageUploadFailedException $e) {
            // Ensure nothing was written to disk regardless.
            $written = Storage::disk(ShopImageUploadService::DISK_NAME)->allFiles(
                ShopImageUploadService::BASE_PREFIX . '/' . $product->id
            );
            $this->assertEmpty($written, 'Disk should have zero files after a rejected upload.');
            throw $e;
        }
    }

    /** @test */
    public function set_promotes_image_to_primary_and_demotes_siblings()
    {
        $product = Inventory::factory()->forShop(75.00)->create();
        $svc = app(ShopImageUploadService::class);
        [$first, $second] = $svc->uploadMultiple($product, [
            UploadedFile::fake()->image('1.jpg')->size(80),
            UploadedFile::fake()->image('2.jpg')->size(80),
        ]);

        $this->assertTrue((bool)$first->fresh()->is_primary);
        $this->assertFalse((bool)$second->fresh()->is_primary);

        $svc->setPrimary($second->fresh());
        $this->assertFalse((bool)$first->fresh()->is_primary, 'First image should be demoted');
        $this->assertTrue((bool)$second->fresh()->is_primary, 'Second image should be promoted to primary');
    }
}