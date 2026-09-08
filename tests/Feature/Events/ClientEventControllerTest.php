<?php

namespace Tests\Feature\Events;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventType;
use App\Models\EventInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientEventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $eventType;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->eventType = EventType::factory()->create([
            'event_type_name' => 'Wedding',
            'event_type_description' => 'Beautiful wedding ceremonies',
        ]);
    }

    /** @test */
    public function it_can_create_event_with_new_client()
    {
        $eventData = [
            'client_name' => 'Jane Smith',
            'client_email' => 'jane@example.com',
            'client_phone' => '555-987-6543',
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->addDays(60)->format('Y-m-d'),
            'start_time' => '15:00',
            'end_time' => '22:00',
            'venue_name' => 'Sunset Gardens',
            'venue_address' => '456 Garden Lane, City, State',
            'guest_number' => 120,
            'budget' => 8000.00,
            'special_instructions' => 'Outdoor ceremony preferred, indoor backup needed',
        ];

        $response = $this->postJson('/api/client/events', $eventData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'event_date',
                        'status',
                        'client',
                        'event_type',
                    ],
                    'message',
                    'next_steps',
                    'contact_info',
                    'reference_number',
                ]);

        // Verify client was created
        $this->assertDatabaseHas('clients', [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'phone' => '555-987-6543',
        ]);

        // Verify event was created with pending status
        $this->assertDatabaseHas('events', [
            'venue_name' => 'Sunset Gardens',
            'guest_number' => 120,
            'status' => 'pending',
        ]);

        // Check response structure
        $responseData = $response->json();
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('next_steps', $responseData);
        $this->assertArrayHasKey('contact_info', $responseData);
        $this->assertStringContainsString('EVT-', $responseData['reference_number']);
    }

    /** @test */
    public function it_can_create_event_with_existing_client()
    {
        $existingClient = Client::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $eventData = [
            'client_name' => 'Updated Name',
            'client_email' => 'existing@example.com', // Same email as existing client
            'client_phone' => '555-111-2222',
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->addDays(45)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'City Hall',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 50,
        ];

        $response = $this->postJson('/api/client/events', $eventData);

        $response->assertStatus(201);

        // Verify no duplicate client was created
        $this->assertEquals(1, Client::where('email', 'existing@example.com')->count());

        // Verify event was created with existing client
        $event = Event::where('venue_name', 'City Hall')->first();
        $this->assertEquals($existingClient->id, $event->client_id);
    }

    /** @test */
    public function it_validates_client_event_creation_data()
    {
        $response = $this->postJson('/api/client/events', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'client_name',
                    'client_email',
                    'client_phone',
                    'event_type_id',
                    'event_date',
                    'start_time',
                    'end_time',
                    'venue_name',
                    'venue_address',
                    'guest_number',
                ]);
    }

    /** @test */
    public function it_validates_event_date_is_in_future()
    {
        $eventData = [
            'client_name' => 'John Doe',
            'client_email' => 'john@example.com',
            'client_phone' => '555-123-4567',
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->subDays(1)->format('Y-m-d'), // Past date
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Test Venue',
            'venue_address' => '123 Test St',
            'guest_number' => 100,
        ];

        $response = $this->postJson('/api/client/events', $eventData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['event_date']);
    }

    /** @test */
    public function it_validates_time_constraints()
    {
        $eventData = [
            'client_name' => 'John Doe',
            'client_email' => 'john@example.com',
            'client_phone' => '555-123-4567',
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->addDays(30)->format('Y-m-d'),
            'start_time' => '18:00',
            'end_time' => '14:00', // End before start
            'venue_name' => 'Test Venue',
            'venue_address' => '123 Test St',
            'guest_number' => 100,
        ];

        $response = $this->postJson('/api/client/events', $eventData);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['end_time']);
    }

    /** @test */
    public function it_can_show_event_status_with_client_email()
    {
        $client = Client::factory()->create([
            'email' => 'client@example.com',
        ]);

        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'confirmed',
        ]);

        $response = $this->getJson("/api/client/events/{$event->id}?email=client@example.com");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'reference_number',
                        'event_date',
                        'status',
                        'status_label',
                        'status_description',
                        'event_type',
                        'client',
                        'services_count',
                        'inventories_count',
                        'invoice',
                    ],
                    'timeline',
                    'next_steps',
                    'contact_info',
                ]);

        $responseData = $response->json();
        $this->assertEquals('confirmed', $responseData['data']['status']);
        $this->assertEquals('Confirmed', $responseData['data']['status_label']);
        $this->assertArrayHasKey('timeline', $responseData);
        $this->assertArrayHasKey('next_steps', $responseData);
    }

    /** @test */
    public function it_denies_access_to_event_without_proper_credentials()
    {
        $client = Client::factory()->create([
            'email' => 'client@example.com',
        ]);

        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        // Try to access without email
        $response = $this->getJson("/api/client/events/{$event->id}");
        $response->assertStatus(403);

        // Try to access with wrong email
        $response = $this->getJson("/api/client/events/{$event->id}?email=wrong@example.com");
        $response->assertStatus(403);
    }

    /** @test */
    public function it_shows_event_with_invoice_information()
    {
        $client = Client::factory()->create([
            'email' => 'client@example.com',
        ]);

        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'confirmed',
        ]);

        $invoice = EventInvoice::factory()->create([
            'event_id' => $event->id,
            'invoice_number' => 'INV-2024-001',
            'total_amount' => 5000.00,
            'payment_status' => 'pending',
        ]);

        $response = $this->getJson("/api/client/events/{$event->id}?email=client@example.com");

        $response->assertStatus(200);

        $invoiceData = $response->json('data.invoice');
        $this->assertEquals('INV-2024-001', $invoiceData['invoice_number']);
        $this->assertEquals('5,000.00', $invoiceData['total_amount']);
        $this->assertEquals('pending', $invoiceData['payment_status']);
        $this->assertFalse($invoiceData['is_paid']);
    }

    /** @test */
    public function it_can_get_available_event_types()
    {
        EventType::factory()->create([
            'event_type_name' => 'Corporate Event',
            'is_active' => true,
        ]);

        EventType::factory()->create([
            'event_type_name' => 'Birthday Party',
            'is_active' => true,
        ]);

        EventType::factory()->create([
            'event_type_name' => 'Inactive Event',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/client/event-types');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'image',
                            'typical_duration',
                            'starting_price',
                        ]
                    ]
                ]);

        $eventTypes = $response->json('data');
        $this->assertCount(3, $eventTypes); // Including the one from setUp
        
        $eventTypeNames = collect($eventTypes)->pluck('name')->toArray();
        $this->assertContains('Wedding', $eventTypeNames);
        $this->assertContains('Corporate Event', $eventTypeNames);
        $this->assertContains('Birthday Party', $eventTypeNames);
    }

    /** @test */
    public function it_can_submit_consultation_request()
    {
        $consultationData = [
            'name' => 'Sarah Johnson',
            'email' => 'sarah@example.com',
            'phone' => '555-444-3333',
            'event_type_id' => $this->eventType->id,
            'preferred_date' => now()->addDays(90)->format('Y-m-d'),
            'guest_count' => 75,
            'budget_range' => '$3,000 - $5,000',
            'message' => 'Looking for an intimate wedding ceremony with reception',
            'preferred_contact_method' => 'email',
            'preferred_contact_time' => 'Weekday evenings',
        ];

        $response = $this->postJson('/api/client/consultation', $consultationData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'reference_number',
                    'next_steps',
                    'contact_info',
                ]);

        // Verify client was created
        $this->assertDatabaseHas('clients', [
            'name' => 'Sarah Johnson',
            'email' => 'sarah@example.com',
            'phone' => '555-444-3333',
        ]);

        $responseData = $response->json();
        $this->assertTrue($responseData['success']);
        $this->assertStringContainsString('CONS-', $responseData['reference_number']);
        $this->assertArrayHasKey('next_steps', $responseData);
    }

    /** @test */
    public function it_validates_consultation_request_data()
    {
        $response = $this->postJson('/api/client/consultation', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'name',
                    'email',
                    'phone',
                ]);
    }

    /** @test */
    public function it_handles_consultation_request_with_existing_client()
    {
        $existingClient = Client::factory()->create([
            'email' => 'existing@example.com',
            'name' => 'Original Name',
        ]);

        $consultationData = [
            'name' => 'Updated Name',
            'email' => 'existing@example.com', // Same email
            'phone' => '555-999-8888',
            'message' => 'I would like to discuss event options',
        ];

        $response = $this->postJson('/api/client/consultation', $consultationData);

        $response->assertStatus(201);

        // Verify no duplicate client was created
        $this->assertEquals(1, Client::where('email', 'existing@example.com')->count());
    }

    /** @test */
    public function it_provides_appropriate_timeline_for_different_statuses()
    {
        $client = Client::factory()->create(['email' => 'client@example.com']);

        // Test pending event timeline
        $pendingEvent = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/client/events/{$pendingEvent->id}?email=client@example.com");
        $timeline = $response->json('timeline');
        
        $this->assertCount(1, $timeline); // Only submission event
        $this->assertEquals('Event Request Submitted', $timeline[0]['title']);

        // Test confirmed event timeline
        $confirmedEvent = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'confirmed',
            'event_date' => now()->addDays(30),
        ]);

        $response = $this->getJson("/api/client/events/{$confirmedEvent->id}?email=client@example.com");
        $timeline = $response->json('timeline');
        
        $this->assertCount(3, $timeline); // Submission, confirmation, and event day
        $this->assertEquals('Event Confirmed', $timeline[1]['title']);
        $this->assertEquals('Event Day', $timeline[2]['title']);
    }

    /** @test */
    public function it_provides_status_specific_next_steps()
    {
        $client = Client::factory()->create(['email' => 'client@example.com']);

        $statuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

        foreach ($statuses as $status) {
            $event = Event::factory()->create([
                'client_id' => $client->id,
                'event_type_id' => $this->eventType->id,
                'status' => $status,
            ]);

            $response = $this->getJson("/api/client/events/{$event->id}?email=client@example.com");
            $nextSteps = $response->json('next_steps');
            
            $this->assertIsArray($nextSteps);
            $this->assertNotEmpty($nextSteps);
            
            // Verify status-specific content
            switch ($status) {
                case 'pending':
                    $this->assertStringContainsString('review', strtolower(implode(' ', $nextSteps)));
                    break;
                case 'confirmed':
                    $this->assertStringContainsString('payment', strtolower(implode(' ', $nextSteps)));
                    break;
                case 'completed':
                    $this->assertStringContainsString('thank you', strtolower(implode(' ', $nextSteps)));
                    break;
            }
        }
    }

    /** @test */
    public function it_generates_consistent_reference_numbers()
    {
        $client = Client::factory()->create();
        
        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $response = $this->getJson("/api/client/events/{$event->id}?client_id={$client->id}");
        $referenceNumber = $response->json('data.reference_number');

        // Reference number should follow format: EVT-YYYYMMDD-XXXX
        $this->assertMatchesRegularExpression('/^EVT-\d{8}-\d{4}$/', $referenceNumber);
        
        // Should contain the creation date
        $expectedDate = $event->created_at->format('Ymd');
        $this->assertStringContainsString($expectedDate, $referenceNumber);
    }
}