<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\ValidEventDate;
use App\Rules\ValidEventDateTime;

class CreateEventRequest extends AppRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Client information (new format)
            'firstName' => ['required_without:client_id', 'string', 'max:255'],
            'lastName' => ['required_without:client_id', 'string', 'max:255'],
            'email' => ['required_without:client_id', 'email', 'max:255'],
            'phone' => ['required_without:client_id', 'string', 'max:20'],



            // Event details (new format)
            'eventType' => ['required_without:event_type_id', 'string', 'max:255'],
            'eventDate' => ['required_without:event_date', 'date', new ValidEventDate()],
            'guestCount' => ['required_without:guest_number', 'integer', 'min:1', 'max:10000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'colorScheme' => ['nullable', 'string', 'max:255'],
            'hasVenue' => ['required', 'string', 'in:yes,no'],
            'venueAddress' => ['nullable', 'string', 'max:500'],
            'venueName' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function getClientData(): array
    {
        return [
            "first_name" => $this->get("firstName"),
            "last_name" => $this->get("lastName"),
            "email" => $this->get("email"),
            "phone" => $this->get("phone"),
        ];
    }

    public function getEventData(): array
    {
        return [
            "event_type_id" => $this->get("eventType"),
            "event_date" => $this->get("eventDate"),
            "number_of_guests" => $this->get("guestCount"),
            "special_instructions" => $this->get("specialInstructions") ?? null,
            "start_time" => $this->get("startTime") ?? null,
            "status" => $this->get("status") ?? 'inquiry',
            "budget" => $this->get("budget") ?? null,
        ];
    }

    public function eventDetails(): array
    {
        return [
            "event_type_id" => $this->get("eventType"),
            "event_date" => $this->get("eventDate"),
            "number_of_guests" => $this->get("guestCount"),

            "special_instructions" => $this->get("specialInstructions"),
            "start_time" => $this->get("startTime"),
            "budget" => $this->get("budget") ?? null,
            "status" => 'inquiry'
        ];
    }

    public function getVenueData(): array
    {
        return [
            "venue_name" => $this->get("venueName") ?? "Not Provided",
            "venue_address" => $this->get("venueAddress") ?? "Not Provided",
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // New field messages
            'firstName.required_without' => 'First name is required when creating a new client.',
            'lastName.required_without' => 'Last name is required when creating a new client.',
            'email.required_without' => 'Email address is required when creating a new client.',
            'phone.required_without' => 'Phone number is required when creating a new client.',
            'eventType.required_without' => 'Please select an event type.',
            'eventDate.required_without' => 'Please provide an event date.',
            'eventDate.after' => 'Event date must be in the future.',
            'guestCount.required_without' => 'Please provide the number of guests.',
            'guestCount.min' => 'At least 1 guest is required.',
            'guestCount.max' => 'Maximum 10,000 guests allowed.',
            'hasVenue.required' => 'Please indicate whether you have a venue.',
            'hasVenue.in' => 'Please select either "yes" or "no" for venue availability.',
            'venueAddress.required' => 'Please provide the venue address.',
            'budget.max' => 'Budget cannot exceed $999,999.99.',

            // Legacy field messages (for backward compatibility)
            'client_name.required_without_all' => 'Client name is required when creating a new client.',
            'client_email.required_without_all' => 'Client email is required when creating a new client.',
            'client_phone.required_without_all' => 'Client phone is required when creating a new client.',
            'end_time.after' => 'End time must be after start time.',
            'special_instructions.max' => 'Special instructions cannot exceed 2000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // New field attributes
            'firstName' => 'first name',
            'lastName' => 'last name',
            'email' => 'email address',
            'phone' => 'phone number',
            'eventType' => 'event type',
            'eventDate' => 'event date',
            'guestCount' => 'number of guests',
            'colorScheme' => 'color scheme',
            'hasVenue' => 'venue availability',
            'venueAddress' => 'venue address',

            // Legacy field attributes (for backward compatibility)
            'client_id' => 'client',
            'client_name' => 'client name',
            'client_email' => 'client email',
            'client_phone' => 'client phone',
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
        // Set default status for new events
        if (!$this->has('status')) {
            $this->merge(['status' => 'pending']);
        }

        // Clean up phone number (both new and legacy)
        if ($this->has('phone')) {
            $phone = preg_replace('/[^0-9+\-\(\)\s]/', '', $this->phone);
            $this->merge(['phone' => $phone]);
        }

        if ($this->has('client_phone')) {
            $phone = preg_replace('/[^0-9+\-\(\)\s]/', '', $this->client_phone);
            $this->merge(['client_phone' => $phone]);
        }

        // Ensure budget is properly formatted (both new and legacy)
        if ($this->has('budget') && $this->budget !== null) {
            $this->merge(['budget' => round((float) $this->budget, 2)]);
        }

        // Map new fields to legacy fields for backward compatibility
        if ($this->has('firstName') && $this->has('lastName') && !$this->has('client_name')) {
            $this->merge(['client_name' => $this->firstName . ' ' . $this->lastName]);
        }

        if ($this->has('email') && !$this->has('client_email')) {
            $this->merge(['client_email' => $this->email]);
        }

        if ($this->has('phone') && !$this->has('client_phone')) {
            $this->merge(['client_phone' => $this->phone]);
        }

        if ($this->has('eventType') && !$this->has('event_type_id')) {
            $this->merge(['event_type_name' => $this->eventType]);
        }

        if ($this->has('eventDate') && !$this->has('event_date')) {
            $this->merge(['event_date' => $this->eventDate]);
        }

        if ($this->has('guestCount') && !$this->has('guest_number')) {
            $this->merge(['guest_number' => $this->guestCount]);
        }

        if ($this->has('venueAddress') && !$this->has('venue_address')) {
            $this->merge(['venue_address' => $this->venueAddress]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Custom validation for event date and time combination (legacy)
            if ($this->event_date && $this->start_time) {
                $dateTimeRule = new ValidEventDateTime($this->event_date, $this->start_time, 24);
                $dateTimeRule->validate('event_datetime', null, function ($message) use ($validator) {
                    $validator->errors()->add('start_time', $message);
                });
            }

            // Validate event duration (minimum 1 hour, maximum 24 hours) - legacy
            if ($this->start_time && $this->end_time) {
                $start = strtotime($this->start_time);
                $end = strtotime($this->end_time);

                if ($start === false || $end === false) {
                    $validator->errors()->add('start_time', 'Invalid time format.');
                    return;
                }

                $duration = ($end - $start) / 3600; // Convert to hours

                if ($duration < 1) {
                    $validator->errors()->add('end_time', 'Event duration must be at least 1 hour.');
                }

                if ($duration > 24) {
                    $validator->errors()->add('end_time', 'Event duration cannot exceed 24 hours.');
                }
            }

            // Ensure consistency between client_id and new client information
            if ($this->client_id && ($this->firstName || $this->lastName || $this->email || $this->phone)) {
                $validator->errors()->add('client_id', 'Cannot provide both client_id and new client information.');
            }

            // Validate guest number and budget reasonableness
            if ($this->guestCount && $this->budget) {
                $guestCount = (int) $this->guestCount;
                $budget = (float) $this->budget;

                if ($guestCount > 0 && $budget > 0 && ($budget / $guestCount) < 10) {
                    $validator->errors()->add('budget', 'Budget seems too low for the number of guests. Please verify.');
                }
            }

            // Add custom validation for large events
            if ($this->guestCount && (int) $this->guestCount > 5000) {
                $validator->errors()->add('guestCount', 'Events with more than 5000 guests require special arrangements. Please contact us directly.');
            }
        });
    }
}