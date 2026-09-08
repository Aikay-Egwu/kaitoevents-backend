<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\ValidEventDate;
use App\Rules\ValidEventDateTime;
use App\Rules\ValidStatusTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $event = $this->route('event');
        
        return [
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'event_type_id' => ['sometimes', 'integer', 'exists:event_types,id'],
            'event_date' => ['sometimes', 'date', new ValidEventDate()],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i', 'after:start_time'],
            'venue_name' => ['sometimes', 'string', 'max:255'],
            'venue_address' => ['sometimes', 'string', 'max:500'],
            'guest_number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'special_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => [
                'sometimes', 
                'string', 
                Rule::in(['pending', 'confirmed', 'in_progress', 'completed', 'cancelled']),
                new ValidStatusTransition($event)
            ],

            // EventVenue fields — Basic venue information
            'venue_contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'venue_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'main_room_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room_length' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'room_width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ceiling_height_lowest' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ceiling_height_apex' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'floor_surface' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Infrastructure
            'lighting_infrastructure' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'power_points' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rigging_points' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Venue Logistics
            'has_free_onsite_parking' => ['sometimes', 'boolean'],
            'has_direct_fire_door_access' => ['sometimes', 'boolean'],
            'venue_floor_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'has_stair_flight' => ['sometimes', 'boolean'],
            'stair_flight_details' => ['sometimes', 'nullable', 'string', 'max:500'],
            'setup_time_allowed' => ['sometimes', 'nullable', 'string', 'max:500'],
            'arrival_protocol' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'venue_tour_available' => ['sometimes', 'boolean'],
            'venue_tour_appointment' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Spatial Design
            'floor_plan_file' => ['sometimes', 'nullable', 'string', 'max:500'],
            'spatial_design_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'table_configuration' => ['sometimes', 'nullable', 'string', 'max:500'],
            'total_tables' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Focal Points
            'primary_focal_point' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secondary_focal_points' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Venue Services and Provisions
            'venue_completes_layout' => ['sometimes', 'boolean'],
            'venue_provides_furniture' => ['sometimes', 'boolean'],
            'table_type' => ['sometimes', 'nullable', 'string', 'in:rectangular,circle,both,none'],
            'rectangular_table_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rectangular_table_seating' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'circle_table_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'circle_table_seating' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'chairs_need_covering' => ['sometimes', 'boolean'],
            'venue_provides_table_cloths' => ['sometimes', 'boolean'],
            'venue_provides_table_numbers' => ['sometimes', 'boolean'],
            'venue_provides_napkins' => ['sometimes', 'boolean'],
            'venue_provides_cutleries' => ['sometimes', 'boolean'],
            'venue_provides_glasswares' => ['sometimes', 'boolean'],
            'special_requirements' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'additional_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'event_date.after' => 'Event date must be in the future.',
            'end_time.after' => 'End time must be after start time.',
            'guest_number.min' => 'At least 1 guest is required.',
            'guest_number.max' => 'Maximum 10,000 guests allowed.',
            'budget.max' => 'Budget cannot exceed $999,999.99.',
            'special_instructions.max' => 'Special instructions cannot exceed 2000 characters.',
            'status.in' => 'Invalid event status. Must be one of: pending, confirmed, in_progress, completed, cancelled.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'client_id' => 'client',
            'event_type_id' => 'event type',
            'event_date' => 'event date',
            'start_time' => 'start time',
            'end_time' => 'end time',
            'venue_name' => 'venue name',
            'venue_address' => 'venue address',
            'guest_number' => 'number of guests',
            'special_instructions' => 'special instructions',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure budget is properly formatted
        if ($this->has('budget') && $this->budget !== null) {
            $this->merge(['budget' => round((float) $this->budget, 2)]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $event = $this->route('event');

            // Custom validation for event date and time combination
            if ($this->event_date && $this->start_time) {
                $dateTimeRule = new ValidEventDateTime($this->event_date, $this->start_time, 24);
                $dateTimeRule->validate('event_datetime', null, function ($message) use ($validator) {
                    $validator->errors()->add('start_time', $message);
                });
            }

            // Validate event duration (minimum 1 hour, maximum 24 hours)
            if ($this->start_time && $this->end_time) {
                $start = strtotime($this->start_time);
                $end = strtotime($this->end_time);
                
                if ($start === false || $end === false) {
                    $validator->errors()->add('start_time', 'Invalid time format.');
                    return;
                }
                
                $duration = ($end - $start) / 3600; // Convert to hours

                if ($duration < 1) {
                    $validator->errors()->add('end_time', 'Event must be at least 1 hour long.');
                } elseif ($duration > 24) {
                    $validator->errors()->add('end_time', 'Event cannot be longer than 24 hours.');
                }
            }

            // Prevent changes to completed or cancelled events
            if ($event instanceof Event && in_array($event->status, ['completed', 'cancelled'])) {
                $restrictedFields = ['event_date', 'start_time', 'end_time', 'venue_name', 'venue_address', 'guest_number'];
                if ($this->hasAny($restrictedFields)) {
                    $validator->errors()->add('status', 'Cannot modify details of completed or cancelled events.');
                }
            }

            // Prevent date changes for confirmed events within 48 hours
            if ($event instanceof Event && $event->status === 'confirmed' && $this->has('event_date')) {
                $currentEventTime = strtotime($event->event_date . ' ' . $event->start_time);
                $hoursUntilEvent = ($currentEventTime - time()) / 3600;

                if ($hoursUntilEvent <= 48) {
                    $validator->errors()->add('event_date', 'Cannot change event date within 48 hours of a confirmed event.');
                }
            }

            // Validate budget reasonableness for updates
            if ($this->budget && ($this->guest_number || ($event && $event->guest_number))) {
                $guestCount = $this->guest_number ?? $event->guest_number;
                $budgetPerGuest = $this->budget / $guestCount;
                if ($budgetPerGuest < 10) {
                    $validator->errors()->add('budget', 'Budget appears too low for the number of guests (minimum $10 per guest recommended).');
                }
            }

            // Prevent reducing guest count if services/inventory are already assigned
            if ($event instanceof Event && $this->has('guest_number') && $this->guest_number < $event->guest_number) {
                if ($event->services()->exists() || $event->inventories()->exists()) {
                    $validator->errors()->add('guest_number', 'Cannot reduce guest count when services or inventory are already assigned.');
                }
            }
        });
    }


}
