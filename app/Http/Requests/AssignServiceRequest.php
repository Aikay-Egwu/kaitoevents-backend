<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Service;
use App\Http\Requests\AppRequest;

class AssignServiceRequest extends AppRequest
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
        return [
            'service_id' => [
                'required',
                'integer',
                'exists:services,id',
                function ($attribute, $value, $fail) {
                    $service = Service::find($value);
                    if (!$service) {
                        $fail('The selected service is not available.');
                    }
                },
            ],
            'quantity' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
            'custom_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:99999.99',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'scheduled_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:today',
            ],
            'scheduled_time' => [
                'sometimes',
                'nullable',
                'date_format:H:i',
            ],
            'duration_hours' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.5',
                'max:24',
            ],
            'value' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'service_id.required' => 'Please select a service to assign.',
            'service_id.exists' => 'The selected service does not exist.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity cannot exceed 100.',
            'custom_price.min' => 'Custom price cannot be negative.',
            'custom_price.max' => 'Custom price cannot exceed $99,999.99.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
            'scheduled_date.after_or_equal' => 'Scheduled date cannot be in the past.',
            'scheduled_time.date_format' => 'Scheduled time must be in HH:MM format.',
            'duration_hours.min' => 'Duration must be at least 0.5 hours.',
            'duration_hours.max' => 'Duration cannot exceed 24 hours.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'service_id' => 'service',
            'custom_price' => 'custom price',
            'scheduled_date' => 'scheduled date',
            'scheduled_time' => 'scheduled time',
            'duration_hours' => 'duration',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default quantity if not provided
        if (!$this->has('quantity')) {
            $this->merge(['quantity' => 1]);
        }

        // Format custom price if provided
        if ($this->has('custom_price') && $this->custom_price !== null) {
            $this->merge(['custom_price' => round((float) $this->custom_price, 2)]);
        }

        // Format duration if provided
        if ($this->has('duration_hours') && $this->duration_hours !== null) {
            $this->merge(['duration_hours' => round((float) $this->duration_hours, 2)]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $event = $this->route('event');

            if (!$event instanceof Event) {
                return;
            }

            // Check if service is already assigned to this event
            if ($this->service_id && $event->services()->where('service_id', $this->service_id)->exists()) {
                $validator->errors()->add('service_id', 'This service is already assigned to the event.');
            }

            // Validate scheduled date is within event timeframe
            if ($this->scheduled_date && $event->event_date) {
                $eventDate = $event->event_date->format('Y-m-d');

                if ($this->scheduled_date !== $eventDate) {
                    // Allow services to be scheduled on the day before or after the event
                    $dayBefore = $event->event_date->subDay()->format('Y-m-d');
                    $dayAfter = $event->event_date->addDay()->format('Y-m-d');

                    if (!in_array($this->scheduled_date, [$dayBefore, $eventDate, $dayAfter])) {
                        $validator->errors()->add('scheduled_date', 'Service must be scheduled within one day of the event date.');
                    }
                }
            }

            // Validate total service cost doesn't exceed event budget
            if ($event->budget && $this->service_id) {
                $service = Service::find($this->service_id);

                if ($service) {
                    $servicePrice = $this->custom_price ?? $service->price;
                    $quantity = $this->quantity ?? 1;
                    $serviceCost = $servicePrice * $quantity;

                    $currentEventCost = $event->getEstimatedTotal();
                    $newTotalCost = $currentEventCost + $serviceCost;

                    if ($newTotalCost > $event->budget) {
                        $budgetExcess = $newTotalCost - $event->budget;
                        $validator->errors()->add(
                            'custom_price',
                            "Adding this service would exceed the event budget by $" . number_format($budgetExcess, 2) . "."
                        );
                    }
                }
            }

            // Validate scheduled time conflicts
            if ($this->scheduled_date && $this->scheduled_time && $this->duration_hours) {
                $scheduledDateTime = $this->scheduled_date . ' ' . $this->scheduled_time;
                $startTime = strtotime($scheduledDateTime);
                $endTime = $startTime + ($this->duration_hours * 3600);

                // Check for conflicts with other assigned services
                $conflictingServices = $event->services()
                    ->whereNotNull('scheduled_date')
                    ->whereNotNull('scheduled_time')
                    ->whereNotNull('duration_hours')
                    ->get()
                    ->filter(function ($assignedService) use ($startTime, $endTime) {
                        $assignedStart = strtotime($assignedService->pivot->scheduled_date . ' ' . $assignedService->pivot->scheduled_time);
                        $assignedEnd = $assignedStart + ($assignedService->pivot->duration_hours * 3600);

                        // Check for time overlap
                        return ($startTime < $assignedEnd && $endTime > $assignedStart);
                    });

                if ($conflictingServices->count() > 0) {
                    $conflictingService = $conflictingServices->first();
                    $validator->errors()->add(
                        'scheduled_time',
                        "This time conflicts with the already assigned service: {$conflictingService->name}."
                    );
                }
            }
        });
    }

    /**
     * Get the validated data with calculated pricing.
     */
    public function getValidatedDataWithPricing(): array
    {
        $validated = $this->validated();

        if (isset($validated['service_id'])) {
            $service = Service::find($validated['service_id']);

            if ($service) {
                // Use custom price if provided, otherwise use service default price
                $unitPrice = $validated['custom_price'] ?? $service->price;
                $quantity = $validated['quantity'] ?? 1;

                $validated['unit_price'] = $unitPrice;
                $validated['total_price'] = $unitPrice * $quantity;
                $validated['original_price'] = $service->price;
                $validated['price_override'] = isset($validated['custom_price']);
            }
        }

        return $validated;
    }

    /**
     * Get service assignment summary for confirmation.
     */
    public function getAssignmentSummary(): array
    {
        $validated = $this->getValidatedDataWithPricing();
        $service = Service::find($validated['service_id']);

        if (!$service) {
            return [];
        }

        return [
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'category' => 'Uncategorized',
            ],
            'assignment' => [
                'quantity' => $validated['quantity'] ?? 1,
                'unit_price' => number_format($validated['unit_price'], 2),
                'total_price' => number_format($validated['total_price'], 2),
                'price_override' => $validated['price_override'],
                'original_price' => number_format($validated['original_price'], 2),
                'scheduled_date' => $validated['scheduled_date'] ?? null,
                'scheduled_time' => $validated['scheduled_time'] ?? null,
                'duration_hours' => $validated['duration_hours'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
        ];
    }
}
