<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\Event;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Client;
use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $event;
    protected $service;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $this->category = ServiceCategory::factory()->create();
        
        $this->event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'guest_number' => 100,
            'budget' => 5000.00,
        ]);

        $this->service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 500.00,
            'is_active' => true,
            'max_capacity' => 200,
        ]);
    }

    /** @test */
    public function it_requires_authentication_for_service_management()
    {
        $response = $this->getJson("/api/admin/events/{$this->event->id}/services");
        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_list_assigned_services_for_event()
    {
        Sanctum::actingAs($this->user);

        // Assign services to event
        $this->event->services()->attach($this->service->id, [
            'quantity' => 2,
            'price' => 450.00,
            'notes' => 'Special setup required',
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'category',
                            'assignment' => [
                                'quantity',
                                'unit_price',
                                'total_price',
                                'notes',
                                'status',
                                'assigned_at',
                            ],
                            'service_details',
                        ]
                    ],
                    'summary' => [
                        'total_services',
                        'total_cost',
                        'services_by_status',
                        'services_by_category',
                    ],
                    'event',
                ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->service->id, $data[0]['id']);
        $this->assertEquals(2, $data[0]['assignment']['quantity']);
        $this->assertEquals('450.00', $data[0]['assignment']['unit_price']);
    }

    /** @test */
    public function it_can_get_available_services_for_event()
    {
        Sanctum::actingAs($this->user);

        // Create additional services
        $availableService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'is_active' => true,
        ]);

        $inactiveService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'is_active' => false,
        ]);

        // Assign one service to exclude it from available list
        $this->event->services()->attach($this->service->id);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'price',
                            'calculated_price',
                            'is_available',
                            'category',
                            'constraints',
                            'compatibility',
                            'recommendations',
                            'pricing',
                        ]
                    ],
                    'meta' => [
                        'total_available',
                        'filters_applied',
                        'event_constraints',
                    ],
                ]);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($availableService->id, $serviceIds);
        $this->assertNotContains($this->service->id, $serviceIds); // Already assigned
        $this->assertNotContains($inactiveService->id, $serviceIds); // Inactive
    }

    /** @test */
    public function it_can_assign_service_to_event()
    {
        Sanctum::actingAs($this->user);

        $assignmentData = [
            'service_id' => $this->service->id,
            'quantity' => 2,
            'custom_price' => 450.00,
            'notes' => 'Special requirements',
            'scheduled_date' => $this->event->event_date->format('Y-m-d'),
            'scheduled_time' => '14:00',
            'duration_hours' => 4.0,
        ];

        $response = $this->postJson("/api/admin/events/{$this->event->id}/services/assign", $assignmentData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'service',
                        'assignment',
                        'event_impact',
                    ],
                    'message',
                ]);

        // Verify service was assigned
        $this->assertDatabaseHas('event_services', [
            'event_id' => $this->event->id,
            'service_id' => $this->service->id,
            'quantity' => 2,
            'price' => 450.00,
            'notes' => 'Special requirements',
        ]);

        $responseData = $response->json();
        $this->assertTrue($responseData['success']);
        $this->assertEquals(2, $responseData['data']['assignment']['quantity']);
        $this->assertEquals('450.00', $responseData['data']['assignment']['unit_price']);
    }

    /** @test */
    public function it_validates_service_assignment_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/services/assign", []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['service_id']);
    }

    /** @test */
    public function it_prevents_duplicate_service_assignment()
    {
        Sanctum::actingAs($this->user);

        // Assign service first
        $this->event->services()->attach($this->service->id);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/services/assign", [
            'service_id' => $this->service->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['service_id']);
    }

    /** @test */
    public function it_can_update_service_assignment()
    {
        Sanctum::actingAs($this->user);

        // Assign service first
        $this->event->services()->attach($this->service->id, [
            'quantity' => 1,
            'price' => 500.00,
            'status' => 'assigned',
        ]);

        $updateData = [
            'quantity' => 3,
            'custom_price' => 400.00,
            'notes' => 'Updated requirements',
            'status' => 'confirmed',
        ];

        $response = $this->putJson("/api/admin/events/{$this->event->id}/services/{$this->service->id}", $updateData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'service',
                        'assignment',
                        'event_impact',
                    ],
                    'message',
                ]);

        // Verify update
        $this->assertDatabaseHas('event_services', [
            'event_id' => $this->event->id,
            'service_id' => $this->service->id,
            'quantity' => 3,
            'price' => 400.00,
            'notes' => 'Updated requirements',
            'status' => 'confirmed',
        ]);
    }

    /** @test */
    public function it_prevents_updating_unassigned_service()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/admin/events/{$this->event->id}/services/{$this->service->id}", [
            'quantity' => 2,
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_NOT_ASSIGNED'
                    ]
                ]);
    }

    /** @test */
    public function it_can_remove_service_from_event()
    {
        Sanctum::actingAs($this->user);

        // Assign service first
        $this->event->services()->attach($this->service->id, [
            'quantity' => 2,
            'price' => 500.00,
        ]);

        $removalData = [
            'reason' => 'Client requested change',
            'refund_amount' => 500.00,
        ];

        $response = $this->deleteJson("/api/admin/events/{$this->event->id}/services/{$this->service->id}", $removalData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'removed_service',
                        'removal_details',
                        'cost_impact',
                        'warnings',
                        'dependencies_affected',
                    ],
                    'message',
                ]);

        // Verify service was removed
        $this->assertDatabaseMissing('event_services', [
            'event_id' => $this->event->id,
            'service_id' => $this->service->id,
        ]);
    }

    /** @test */
    public function it_prevents_removing_unassigned_service()
    {
        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/admin/events/{$this->event->id}/services/{$this->service->id}");

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_NOT_ASSIGNED'
                    ]
                ]);
    }

    /** @test */
    public function it_can_get_service_categories()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/services/categories');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'color',
                            'icon',
                            'services_count',
                            'is_parent',
                            'has_children',
                        ]
                    ],
                ]);

        $categories = $response->json('data');
        $this->assertGreaterThan(0, count($categories));
        
        $categoryIds = collect($categories)->pluck('id')->toArray();
        $this->assertContains($this->category->id, $categoryIds);
    }

    /** @test */
    public function it_can_get_service_recommendations()
    {
        Sanctum::actingAs($this->user);

        // Create some services for recommendations
        Service::factory()->count(3)->create([
            'service_category_id' => $this->category->id,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/recommendations");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'type',
                            'title',
                            'description',
                            'services' => [
                                '*' => [
                                    'id',
                                    'name',
                                    'price',
                                    'category',
                                    'compatibility_score',
                                ]
                            ],
                        ]
                    ],
                    'event_context',
                ]);
    }

    /** @test */
    public function it_filters_available_services_by_category()
    {
        Sanctum::actingAs($this->user);

        $otherCategory = ServiceCategory::factory()->create();
        $otherService = Service::factory()->create([
            'service_category_id' => $otherCategory->id,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available?category_id={$this->category->id}");

        $response->assertStatus(200);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->service->id, $serviceIds);
        $this->assertNotContains($otherService->id, $serviceIds);
    }

    /** @test */
    public function it_filters_available_services_by_price_range()
    {
        Sanctum::actingAs($this->user);

        $expensiveService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 1000.00,
            'is_active' => true,
        ]);

        $cheapService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 100.00,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available?min_price=200&max_price=800");

        $response->assertStatus(200);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->service->id, $serviceIds); // 500.00 is in range
        $this->assertNotContains($expensiveService->id, $serviceIds); // Too expensive
        $this->assertNotContains($cheapService->id, $serviceIds); // Too cheap
    }

    /** @test */
    public function it_filters_available_services_by_capacity_compatibility()
    {
        Sanctum::actingAs($this->user);

        $limitedService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'max_capacity' => 50, // Less than event's 100 guests
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available?capacity_compatible=true");

        $response->assertStatus(200);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->service->id, $serviceIds); // Compatible
        $this->assertNotContains($limitedService->id, $serviceIds); // Not compatible
    }

    /** @test */
    public function it_searches_available_services()
    {
        Sanctum::actingAs($this->user);

        $this->service->update([
            'service_name' => 'Wedding Photography',
            'service_description' => 'Professional wedding photos',
        ]);

        $otherService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'service_name' => 'Catering Service',
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available?search=photography");

        $response->assertStatus(200);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->service->id, $serviceIds);
        $this->assertNotContains($otherService->id, $serviceIds);
    }

    /** @test */
    public function it_sorts_available_services()
    {
        Sanctum::actingAs($this->user);

        $cheapService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'service_name' => 'A Service',
            'price' => 100.00,
            'is_active' => true,
        ]);

        $expensiveService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'service_name' => 'Z Service',
            'price' => 1000.00,
            'is_active' => true,
        ]);

        // Sort by price ascending
        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available?sort_by=price&sort_order=asc");

        $response->assertStatus(200);

        $services = $response->json('data');
        $prices = collect($services)->pluck('price_raw')->toArray();
        
        $this->assertEquals($cheapService->price, $prices[0]);
        $this->assertEquals($this->service->price, $prices[1]);
        $this->assertEquals($expensiveService->price, $prices[2]);
    }

    /** @test */
    public function it_calculates_event_impact_correctly()
    {
        Sanctum::actingAs($this->user);

        // Assign first service
        $this->event->services()->attach($this->service->id, [
            'quantity' => 1,
            'price' => 500.00,
        ]);

        $secondService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 300.00,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/events/{$this->event->id}/services/assign", [
            'service_id' => $secondService->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201);

        $eventImpact = $response->json('data.event_impact');
        $this->assertEquals('1,100.00', $eventImpact['new_total_cost']); // 500 + (300 * 2)
        $this->assertEquals('3,900.00', $eventImpact['budget_remaining']); // 5000 - 1100
        $this->assertEquals(2, $eventImpact['total_services']);
    }

    /** @test */
    public function it_provides_service_availability_reasons()
    {
        Sanctum::actingAs($this->user);

        $unavailableService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'max_capacity' => 50, // Less than event's 100 guests
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services/available");

        $response->assertStatus(200);

        $services = collect($response->json('data'));
        $unavailableServiceData = $services->firstWhere('id', $unavailableService->id);
        
        $this->assertFalse($unavailableServiceData['is_available']);
        $this->assertNotEmpty($unavailableServiceData['availability_reasons']);
        $this->assertStringContainsString('capacity', strtolower($unavailableServiceData['availability_reasons'][0]));
    }

    /** @test */
    public function it_includes_service_summary_in_listing()
    {
        Sanctum::actingAs($this->user);

        // Assign multiple services
        $this->event->services()->attach($this->service->id, [
            'quantity' => 2,
            'price' => 500.00,
            'status' => 'assigned',
        ]);

        $secondService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 300.00,
        ]);

        $this->event->services()->attach($secondService->id, [
            'quantity' => 1,
            'price' => 300.00,
            'status' => 'confirmed',
        ]);

        $response = $this->getJson("/api/admin/events/{$this->event->id}/services");

        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertEquals(2, $summary['total_services']);
        $this->assertEquals(1300.00, $summary['total_cost']); // (500 * 2) + (300 * 1)
        $this->assertEquals('1,300.00', $summary['total_cost_formatted']);
        $this->assertArrayHasKey('services_by_status', $summary);
        $this->assertArrayHasKey('services_by_category', $summary);
    }
}