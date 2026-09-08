<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Feedback;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_casts_attributes_to_native_types()
    {
        $feedback = Feedback::factory()->create([
            'event_date' => '2026-07-01',
            'likely_to_return' => 1,
            'would_recommend' => 0,
            'creativity' => '5',
        ]);

        $this->assertInstanceOf(Carbon::class, $feedback->event_date);
        $this->assertTrue($feedback->likely_to_return);
        $this->assertFalse($feedback->would_recommend);
        $this->assertSame(5, $feedback->creativity);
    }

    /** @test */
    public function it_belongs_to_a_client_and_an_event()
    {
        $feedback = new Feedback();

        $this->assertInstanceOf(BelongsTo::class, $feedback->client());
        $this->assertInstanceOf(BelongsTo::class, $feedback->event());
        $this->assertInstanceOf(Client::class, $feedback->client()->getRelated());
        $this->assertInstanceOf(Event::class, $feedback->event()->getRelated());
    }

    /** @test */
    public function it_exposes_inverse_relations_from_client_and_event()
    {
        $client = Client::factory()->create([
            'firstname' => 'Chioma',
            'lastname' => 'Adeyemi',
            'email' => 'chioma@example.com',
        ]);
        $feedback = Feedback::factory()->forClient($client)->create();

        $this->assertTrue($client->feedbacks->contains($feedback));

        $eventType = EventType::factory()->create(['title' => 'Wedding', 'slug' => 'wedding']);
        $event = Event::create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'event_date' => now()->subDays(30)->toDateString(),
            'number_of_guests' => 120,
            'status' => 'completed',
        ]);
        $eventFeedback = Feedback::factory()->forEvent($event)->create();

        $this->assertTrue($event->feedbacks->contains($eventFeedback));
    }

    /** @test */
    public function it_defines_rating_rules_bounded_between_1_and_6()
    {
        $rules = Feedback::rules();

        foreach (Feedback::RATING_FIELDS as $field) {
            $this->assertArrayHasKey($field, $rules);
            $this->assertSame('required|integer|between:1,6', $rules[$field]);
        }

        $this->assertSame('required|string|max:255', $rules['client_name']);
        $this->assertSame('required|date|before_or_equal:today', $rules['event_date']);
        $this->assertSame('required|integer|exists:event_types,id', $rules['event_type_id']);
    }

    /** @test */
    public function it_computes_the_average_rating()
    {
        $feedback = Feedback::factory()->create([
            'creativity' => 6,
            'excellence' => 6,
            'transcendence' => 6,
            'bespoke' => 6,
            'integrity' => 6,
            'exactitude' => 6,
            'intentionality' => 6,
            'genuine_connection' => 6,
            'communication_rating' => 3,
        ]);

        // (8 * 6 + 3) / 9 = 5.666... rounded to 5.67
        $this->assertEqualsWithDelta(5.67, $feedback->averageRating(), 0.001);
    }

    /** @test */
    public function it_scopes_by_status_and_client_search()
    {
        Feedback::factory()->create(['client_name' => 'Chioma Adeyemi', 'status' => 'new']);
        Feedback::factory()->create(['client_name' => 'Amaka Obi', 'status' => 'reviewed']);

        $this->assertSame(1, Feedback::byStatus('reviewed')->count());
        $this->assertSame(1, Feedback::searchClient('Chioma')->count());
    }
}
