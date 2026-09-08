<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Event;
use App\Models\User;
use App\Models\EventImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $event;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->event = Event::factory()->create();
        
        Storage::fake('public');
    }

    /** @test */
    public function it_can_upload_images_for_an_event()
    {
        $this->actingAs($this->user);

        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images", [
            'images' => [$file],
            'category' => 'venue',
            'description' => 'Test venue image',
            'tags' => ['venue', 'outdoor'],
            'is_primary' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'uploaded' => 1,
                         'failed' => 0,
                     ],
                 ]);

        $this->assertDatabaseHas('event_images', [
            'event_id' => $this->event->id,
            'category' => 'venue',
            'is_primary' => true,
            'uploaded_by' => $this->user->id,
        ]);
    }

    /** @test */
    public function it_validates_image_upload_request()
    {
        $this->actingAs($this->user);

        // Test invalid file type
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images", [
            'images' => [$file],
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('images.0');

        // Test file size limit
        $largeFile = UploadedFile::fake()->image('large.jpg')->size(6000); // 6MB

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images", [
            'images' => [$largeFile],
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('images.0');
    }

    /** @test */
    public function it_can_list_images_for_an_event()
    {
        EventImage::factory()->count(3)->create([
            'event_id' => $this->event->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/images");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [],
                 ])
                 ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_filter_images_by_category()
    {
        EventImage::factory()->create([
            'event_id' => $this->event->id,
            'category' => 'venue',
        ]);

        EventImage::factory()->create([
            'event_id' => $this->event->id,
            'category' => 'food',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/images?category=venue");

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function it_can_update_image_metadata()
    {
        $image = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'category' => 'venue',
            'description' => 'Original description',
        ]);

        $this->actingAs($this->user);

        $response = $this->putJson("/api/admin/images/{$image->id}", [
            'category' => 'decorations',
            'description' => 'Updated description',
            'tags' => ['new', 'tags'],
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        $image->refresh();
        $this->assertEquals('decorations', $image->category);
        $this->assertEquals('Updated description', $image->description);
        $this->assertEquals(['new', 'tags'], $image->getTagsArray());
    }

    /** @test */
    public function it_can_delete_an_image()
    {
        $image = EventImage::factory()->create([
            'event_id' => $this->event->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->deleteJson("/api/admin/images/{$image->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        $this->assertDatabaseMissing('event_images', ['id' => $image->id]);
    }

    /** @test */
    public function it_can_set_image_as_primary()
    {
        $image1 = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'is_primary' => false,
        ]);

        $image2 = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'is_primary' => true,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/api/admin/images/{$image1->id}/primary");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        $image1->refresh();
        $image2->refresh();

        $this->assertTrue($image1->is_primary);
        $this->assertFalse($image2->is_primary);
    }

    /** @test */
    public function it_can_reorder_images()
    {
        $image1 = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'sort_order' => 1,
        ]);

        $image2 = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'sort_order' => 2,
        ]);

        $image3 = EventImage::factory()->create([
            'event_id' => $this->event->id,
            'sort_order' => 3,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images/reorder", [
            'order' => [$image3->id, $image1->id, $image2->id],
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                 ]);

        $image1->refresh();
        $image2->refresh();
        $image3->refresh();

        $this->assertEquals(2, $image1->sort_order);
        $this->assertEquals(3, $image2->sort_order);
        $this->assertEquals(1, $image3->sort_order);
    }

    /** @test */
    public function it_can_bulk_delete_images()
    {
        $images = EventImage::factory()->count(3)->create([
            'event_id' => $this->event->id,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images/bulk-delete", [
            'image_ids' => $images->pluck('id')->toArray(),
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'deleted' => 3,
                         'total' => 3,
                     ],
                 ]);

        foreach ($images as $image) {
            $this->assertDatabaseMissing('event_images', ['id' => $image->id]);
        }
    }

    /** @test */
    public function it_can_get_image_statistics()
    {
        EventImage::factory()->count(5)->create([
            'event_id' => $this->event->id,
            'category' => 'venue',
            'file_size' => 1024 * 1024, // 1MB each
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/images/statistics");

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'statistics' => [
                             'total_images' => 5,
                             'total_size' => 5 * 1024 * 1024,
                         ],
                     ],
                 ]);
    }

    /** @test */
    public function it_requires_authentication_for_admin_endpoints()
    {
        $response = $this->getJson("/api/admin/events/{$this->event->id}/images");
        $response->assertStatus(401);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/images");
        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_search_images()
    {
        EventImage::factory()->create([
            'event_id' => $this->event->id,
            'original_filename' => 'wedding_venue.jpg',
            'description' => 'Beautiful wedding venue',
        ]);

        EventImage::factory()->create([
            'event_id' => $this->event->id,
            'original_filename' => 'food_table.jpg',
            'description' => 'Wedding food setup',
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/images?search=wedding");

        $response->assertStatus(200)
                 ->assertJsonCount(2, 'data');

        $response = $this->getJson("/api/admin/events/{$this->event->id}/images?search=venue");

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data');
    }
}