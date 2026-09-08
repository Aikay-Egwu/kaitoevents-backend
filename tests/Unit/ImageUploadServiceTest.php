<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Event;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImageUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $event;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new ImageUploadService();
        $this->user = User::factory()->create();
        $this->event = Event::factory()->create();
        
        Storage::fake('public');
        
        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_upload_single_image()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        
        $result = $this->service->uploadSingle($file, [
            'event_id' => $this->event->id,
            'category' => 'venue',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('image', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('thumbnails', $result);
        
        Storage::disk('public')->assertExists($result['image']->file_path);
    }

    /** @test */
    public function it_can_upload_multiple_images()
    {
        $files = [
            UploadedFile::fake()->image('image1.jpg'),
            UploadedFile::fake()->image('image2.jpg'),
        ];

        $results = $this->service->uploadMultiple($files, [
            'event_id' => $this->event->id,
            'category' => 'venue',
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
    }

    /** @test */
    public function it_generates_thumbnails_correctly()
    {
        $file = UploadedFile::fake()->image('test.jpg', 1200, 800);
        
        $result = $this->service->uploadSingle($file, [
            'event_id' => $this->event->id,
            'thumbnail_sizes' => ['small', 'medium', 'large'],
        ]);

        $image = $result['image'];
        $thumbnails = json_decode($image->thumbnails, true);

        $this->assertArrayHasKey('small', $thumbnails);
        $this->assertArrayHasKey('medium', $thumbnails);
        $this->assertArrayHasKey('large', $thumbnails);
        
        $this->assertEquals(150, $thumbnails['small']['width']);
        $this->assertEquals(300, $thumbnails['medium']['width']);
        $this->assertEquals(600, $thumbnails['large']['width']);
    }

    /** @test */
    public function it_can_delete_image_and_thumbnails()
    {
        $file = UploadedFile::fake()->image('test.jpg');
        
        $result = $this->service->uploadSingle($file, [
            'event_id' => $this->event->id,
            'thumbnail_sizes' => ['small', 'medium'],
        ]);

        $image = $result['image'];
        
        // Verify files exist
        Storage::disk('public')->assertExists($image->file_path);
        $thumbnails = json_decode($image->thumbnails, true);
        foreach ($thumbnails as $thumbnail) {
            Storage::disk('public')->assertExists($thumbnail['path']);
        }

        // Delete image
        $deleted = $this->service->deleteImage($image);
        $this->assertTrue($deleted);

        // Verify files are deleted
        Storage::disk('public')->assertMissing($image->file_path);
        foreach ($thumbnails as $thumbnail) {
            Storage::disk('public')->assertMissing($thumbnail['path']);
        }
    }

    /** @test */
    public function it_can_set_image_as_primary()
    {
        $file1 = UploadedFile::fake()->image('image1.jpg');
        $file2 = UploadedFile::fake()->image('image2.jpg');

        $result1 = $this->service->uploadSingle($file1, [
            'event_id' => $this->event->id,
            'is_primary' => false,
        ]);

        $result2 = $this->service->uploadSingle($file2, [
            'event_id' => $this->event->id,
            'is_primary' => true,
        ]);

        $image1 = $result1['image'];
        $image2 = $result2['image'];

        $image1->refresh();
        $image2->refresh();

        $this->assertFalse($image1->is_primary);
        $this->assertTrue($image2->is_primary);
    }

    /** @test */
    public function it_can_reorder_images()
    {
        // Create test images
        $image1 = \App\Models\EventImage::factory()->create([
            'event_id' => $this->event->id,
            'sort_order' => 1,
        ]);
        
        $image2 = \App\Models\EventImage::factory()->create([
            'event_id' => $this->event->id,
            'sort_order' => 2,
        ]);

        $order = [$image2->id, $image1->id];
        $success = $this->service->reorderImages($this->event->id, $order);

        $this->assertTrue($success);

        $image1->refresh();
        $image2->refresh();

        $this->assertEquals(2, $image1->sort_order);
        $this->assertEquals(1, $image2->sort_order);
    }

    /** @test */
    public function it_can_get_storage_statistics()
    {
        // Create test images
        \App\Models\EventImage::factory()->count(3)->create([
            'event_id' => $this->event->id,
            'file_size' => 1024 * 1024, // 1MB each
            'is_primary' => false,
        ]);

        \App\Models\EventImage::factory()->create([
            'event_id' => $this->event->id,
            'file_size' => 2 * 1024 * 1024, // 2MB
            'is_primary' => true,
            'category' => 'venue',
        ]);

        $stats = $this->service->getStorageStats($this->event->id);

        $this->assertEquals(4, $stats['total_images']);
        $this->assertEquals(5 * 1024 * 1024, $stats['total_size']);
        $this->assertEquals(1, $stats['primary_image']);
        $this->assertArrayHasKey('venue', $stats['categories']);
    }

    /** @test */
    public function it_validates_file_type_and_size()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid file type');

        $invalidFile = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');
        $this->service->uploadSingle($invalidFile, ['event_id' => $this->event->id]);
    }

    /** @test */
    public function it_generates_unique_filenames()
    {
        $file1 = UploadedFile::fake()->image('test.jpg');
        $file2 = UploadedFile::fake()->image('test.jpg');

        $result1 = $this->service->uploadSingle($file1, ['event_id' => $this->event->id]);
        $result2 = $this->service->uploadSingle($file2, ['event_id' => $this->event->id]);

        $this->assertNotEquals($result1['image']->filename, $result2['image']->filename);
    }

    /** @test */
    public function it_handles_upload_errors_gracefully()
    {
        $file = UploadedFile::fake()->image('test.jpg');

        // Test with invalid event ID
        $result = $this->service->uploadSingle($file, ['event_id' => 99999]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}