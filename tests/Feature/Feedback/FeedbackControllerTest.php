<?php

namespace Tests\Feature\Feedback;

use App\Models\EventType;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeedbackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the in-memory sqlite testing connection is used.
        config(['database.default' => 'testing']);

        $this->eventType = EventType::factory()->create([
            'title' => 'Wedding',
            'slug' => 'wedding',
        ]);
    }

    /**
     * A fully valid feedback payload mirroring the post-event feedback form.
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Chioma Adeyemi',
            'event_date' => now()->subDays(10)->toDateString(),
            'event_type_id' => $this->eventType->id,
            'creativity' => 6,
            'excellence' => 5,
            'transcendence' => 6,
            'bespoke' => 5,
            'integrity' => 6,
            'exactitude' => 5,
            'intentionality' => 6,
            'genuine_connection' => 6,
            'communication_rating' => 5,
            'experience_comparison' => 'exceeded_expectations',
            'likely_to_return' => true,
            'would_recommend' => true,
            'unmet_expectations' => 'Nothing at all.',
            'stood_out' => 'The floral design was breathtaking.',
        ], $overrides);
    }

    /*
    |------------------------------------------------------------------
    | Public store endpoint
    |------------------------------------------------------------------
    */

    /** @test */
    public function it_allows_guests_to_submit_feedback()
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/public/feedback', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.client_name', $payload['client_name']);

        $this->assertDatabaseHas('feedbacks', [
            'client_name' => $payload['client_name'],
            'event_type_id' => $payload['event_type_id'],
            'creativity' => $payload['creativity'],
            'experience_comparison' => $payload['experience_comparison'],
            'status' => 'new',
        ]);
    }

    /** @test */
    public function it_accepts_null_optional_summary_texts()
    {
        $payload = $this->validPayload([
            'unmet_expectations' => null,
            'stood_out' => null,
        ]);

        $this->postJson('/api/public/feedback', $payload)
            ->assertStatus(201);

        $this->assertDatabaseHas('feedbacks', [
            'client_name' => $payload['client_name'],
            'unmet_expectations' => null,
            'stood_out' => null,
        ]);
    }

    /** @test */
    public function it_requires_the_header_fields()
    {
        foreach (['client_name', 'event_date', 'event_type_id'] as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->postJson('/api/public/feedback', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    /** @test */
    public function it_rejects_a_future_event_date()
    {
        $this->postJson('/api/public/feedback', $this->validPayload([
            'event_date' => now()->addDays(5)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_date');
    }

    /** @test */
    public function it_rejects_an_invalid_event_date()
    {
        $this->postJson('/api/public/feedback', $this->validPayload([
            'event_date' => 'not-a-date',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_date');
    }

    /** @test */
    public function it_requires_every_rating_field()
    {
        foreach (Feedback::RATING_FIELDS as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->postJson('/api/public/feedback', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    /** @test */
    public function it_rejects_out_of_range_ratings()
    {
        foreach (Feedback::RATING_FIELDS as $field) {
            foreach ([0, 7] as $invalid) {
                $this->postJson('/api/public/feedback', $this->validPayload([$field => $invalid]))
                    ->assertStatus(422)
                    ->assertJsonValidationErrors($field);
            }
        }
    }

    /** @test */
    public function it_rejects_non_integer_ratings()
    {
        $this->postJson('/api/public/feedback', $this->validPayload([
            'creativity' => 'exceptional',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('creativity');
    }

    /** @test */
    public function it_requires_a_valid_experience_comparison()
    {
        $this->postJson('/api/public/feedback', $this->validPayload([
            'experience_comparison' => 'average',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('experience_comparison');

        $payload = $this->validPayload();
        unset($payload['experience_comparison']);

        $this->postJson('/api/public/feedback', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('experience_comparison');
    }

    /** @test */
    public function it_requires_yes_no_answers()
    {
        foreach (['likely_to_return', 'would_recommend'] as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->postJson('/api/public/feedback', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    /** @test */
    public function it_rejects_overlong_summary_texts()
    {
        $this->postJson('/api/public/feedback', $this->validPayload([
            'unmet_expectations' => str_repeat('a', 2001),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unmet_expectations');

        $this->postJson('/api/public/feedback', $this->validPayload([
            'stood_out' => str_repeat('b', 2001),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stood_out');
    }

    /** @test */
    public function it_rejects_nonexistent_client_and_event_ids()
    {
        $this->postJson('/api/public/feedback', $this->validPayload(['client_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->postJson('/api/public/feedback', $this->validPayload(['event_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');

        $this->postJson('/api/public/feedback', $this->validPayload(['event_type_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_type_id');
    }

    /*
    |------------------------------------------------------------------
    | Authorization for admin endpoints
    |------------------------------------------------------------------
    */

    /** @test */
    public function it_requires_authentication_for_admin_endpoints()
    {
        $feedback = Feedback::factory()->create();

        $this->getJson('/api/admin/feedback')->assertStatus(401);
        $this->getJson("/api/admin/feedback/{$feedback->id}")->assertStatus(401);
        $this->putJson("/api/admin/feedback/{$feedback->id}", ['status' => 'reviewed'])->assertStatus(401);
        $this->deleteJson("/api/admin/feedback/{$feedback->id}")->assertStatus(401);
    }

    /*
    |------------------------------------------------------------------
    | Admin endpoints
    |------------------------------------------------------------------
    */

    /** @test */
    public function it_can_list_feedback_as_an_admin()
    {
        Sanctum::actingAs(User::factory()->create());
        Feedback::factory()->count(3)->create();

        $response = $this->getJson('/api/admin/feedback');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'client_name', 'event_date', 'event_type_id', 'creativity', 'status'],
                ],
                'meta' => [
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('meta.pagination.total', 3);
    }

    /** @test */
    public function it_can_filter_feedback_by_status()
    {
        Sanctum::actingAs(User::factory()->create());
        Feedback::factory()->count(2)->create();
        Feedback::factory()->reviewed()->create();

        $response = $this->getJson('/api/admin/feedback?status=reviewed');

        $response->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'reviewed');
    }

    /** @test */
    public function it_can_search_feedback_by_client_name()
    {
        Sanctum::actingAs(User::factory()->create());
        Feedback::factory()->create(['client_name' => 'Chioma Adeyemi']);
        Feedback::factory()->create(['client_name' => 'Amaka Obi']);

        $response = $this->getJson('/api/admin/feedback?search=Chioma');

        $response->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.client_name', 'Chioma Adeyemi');
    }

    /** @test */
    public function it_can_show_a_single_feedback()
    {
        Sanctum::actingAs(User::factory()->create());
        $feedback = Feedback::factory()->create();

        $this->getJson("/api/admin/feedback/{$feedback->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $feedback->id);
    }

    /** @test */
    public function it_returns_404_for_missing_feedback()
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/feedback/999')->assertStatus(404);
    }

    /** @test */
    public function it_can_partially_update_a_feedback()
    {
        Sanctum::actingAs(User::factory()->create());
        $feedback = Feedback::factory()->create(['client_name' => 'Chioma Adeyemi']);

        $response = $this->putJson("/api/admin/feedback/{$feedback->id}", [
            'status' => 'reviewed',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'reviewed');

        // Untouched fields remain intact.
        $this->assertDatabaseHas('feedbacks', [
            'id' => $feedback->id,
            'status' => 'reviewed',
            'client_name' => 'Chioma Adeyemi',
        ]);
    }

    /** @test */
    public function it_validates_updates()
    {
        Sanctum::actingAs(User::factory()->create());
        $feedback = Feedback::factory()->create();

        $this->putJson("/api/admin/feedback/{$feedback->id}", ['status' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->putJson("/api/admin/feedback/{$feedback->id}", ['creativity' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('creativity');

        $this->putJson("/api/admin/feedback/{$feedback->id}", ['event_date' => now()->addYear()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_date');
    }

    /** @test */
    public function it_can_delete_a_feedback()
    {
        Sanctum::actingAs(User::factory()->create());
        $feedback = Feedback::factory()->create();

        $this->deleteJson("/api/admin/feedback/{$feedback->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('feedbacks', ['id' => $feedback->id]);
    }
}
