<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\CreateEventRequest;
use App\Models\EventType;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CreateEventRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        EventType::factory()->create(['id' => 1]);
        Client::factory()->create(['id' => 1]);
    }

    /** @test */
    public function it_passes_validation_with_valid_data_for_existing_client()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
            'budget' => 5000.00,
            'special_instructions' => 'Please arrange flowers on each table',
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_passes_validation_with_valid_data_for_new_client()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_name' => 'John Doe',
            'client_email' => 'john@example.com',
            'client_phone' => '555-123-4567',
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_requires_client_information()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('client_name', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_event_date_is_in_future()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->subDays(1)->format('Y-m-d'), // Past date
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_end_time_is_after_start_time()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '18:00',
            'end_time' => '14:00', // Before start time
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_guest_number_range()
    {
        $request = new CreateEventRequest();
        
        // Test minimum guests
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 0, // Below minimum
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('guest_number', $validator->errors()->toArray());

        // Test maximum guests
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 15000, // Above maximum
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('guest_number', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_budget_range()
    {
        $request = new CreateEventRequest();
        
        // Test negative budget
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
            'budget' => -100, // Negative budget
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('budget', $validator->errors()->toArray());

        // Test excessive budget
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
            'budget' => 1000000, // Exceeds maximum
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('budget', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_required_fields()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([], $request->rules());

        $this->assertFalse($validator->passes());
        
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('client_name', $errors);
        $this->assertArrayHasKey('event_type_id', $errors);
        $this->assertArrayHasKey('event_date', $errors);
        $this->assertArrayHasKey('start_time', $errors);
        $this->assertArrayHasKey('end_time', $errors);
        $this->assertArrayHasKey('venue_name', $errors);
        $this->assertArrayHasKey('venue_address', $errors);
        $this->assertArrayHasKey('guest_number', $errors);
    }

    /** @test */
    public function it_validates_string_field_lengths()
    {
        $request = new CreateEventRequest();
        
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => str_repeat('a', 256), // Exceeds 255 chars
            'venue_address' => str_repeat('a', 501), // Exceeds 500 chars
            'guest_number' => 100,
            'special_instructions' => str_repeat('a', 2001), // Exceeds 2000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('venue_name', $errors);
        $this->assertArrayHasKey('venue_address', $errors);
        $this->assertArrayHasKey('special_instructions', $errors);
    }

    /** @test */
    public function it_validates_event_type_exists()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 999, // Non-existent event type
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_type_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_client_exists_when_provided()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 999, // Non-existent client
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('client_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_status_values()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
            'status' => 'invalid_status',
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_time_formats()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '2:00 PM', // Invalid format
            'end_time' => '6:00 PM', // Invalid format
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('start_time', $errors);
        $this->assertArrayHasKey('end_time', $errors);
    }

    /** @test */
    public function it_validates_email_format_for_new_client()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_name' => 'John Doe',
            'client_email' => 'invalid-email', // Invalid email format
            'client_phone' => '555-123-4567',
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('client_email', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_optional_fields_to_be_null()
    {
        $request = new CreateEventRequest();
        $validator = Validator::make([
            'client_id' => 1,
            'event_type_id' => 1,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 100,
            'budget' => null,
            'special_instructions' => null,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }
}