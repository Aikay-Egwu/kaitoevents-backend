<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\EventType;

use Illuminate\Support\Facades\Config;

class EventTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'testing');
    }

    /** @test */
    public function it_can_get_all_event_types()
    {
        EventType::factory()->count(3)->create();

        $response = $this->getJson('/event-types');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    /** @test */
    public function it_can_create_an_event_type()
    {
        $data = [
            'title' => 'Test Event Type',
        ];

        $response = $this->postJson('/event-types', $data);

        $response->assertStatus(201)
            ->assertJsonFragment($data);

        $this->assertDatabaseHas('event_types', $data);
    }

    /** @test */
    public function it_validates_the_title_when_creating_an_event_type()
    {
        $response = $this->postJson('/event-types', ['title' => '']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    /** @test */
    public function it_can_get_a_single_event_type()
    {
        $eventType = EventType::factory()->create();

        $response = $this->getJson("/event-types/{$eventType->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $eventType->id]);
    }

    /** @test */
    public function it_can_update_an_event_type()
    {
        $eventType = EventType::factory()->create();

        $data = [
            'title' => 'Updated Event Type',
        ];

        $response = $this->putJson("/event-types/{$eventType->id}", $data);

        $response->assertStatus(200)
            ->assertJsonFragment($data);

        $this->assertDatabaseHas('event_types', $data);
    }

    /** @test */
    public function it_validates_the_title_when_updating_an_event_type()
    {
        $eventType = EventType::factory()->create();

        $response = $this->putJson("/event-types/{$eventType->id}", ['title' => '']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    /** @test */
    public function it_can_delete_an_event_type()
    {
        $eventType = EventType::factory()->create();

        $response = $this->deleteJson("/event-types/{$eventType->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('event_types', ['id' => $eventType->id]);
    }
}
