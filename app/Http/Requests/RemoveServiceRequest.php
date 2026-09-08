<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Event;
use App\Models\Service;

class RemoveServiceRequest extends FormRequest
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
            'reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'refund_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:99999.99',
            ],
            'force_remove' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reason.max' => 'Removal reason cannot exceed 500 characters.',
            'refund_amount.min' => 'Refund amount cannot be negative.',
            'refund_amount.max' => 'Refund amount cannot exceed $99,999.99.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'refund_amount' => 'refund amount',
            'force_remove' => 'force removal',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $event = $this->route('event');
            $service = $this->route('service');
            
            if (!$event instanceof Event || !$service instanceof Service) {
                return;
            }

            // Check if service is actually assigned to this event
            $assignedService = $event->services()->where('service_id', $service->id)->first();
            
            if (!$assignedService) {
                $validator->errors()->add('service_id', 'This service is not assigned to the event.');
                return;
            }

            // Check if event status allows service removal
            if (in_array($event->status, ['completed', 'cancelled'])) {
                if (!$this->force_remove) {
                    $validator->errors()->add('force_remove', 
                        'Cannot remove services from completed or cancelled events without force removal.'
                    );
                }
            }

            // Check if event is within 24 hours and service removal might affect setup
            if ($event->isUpcoming() && $event->getHoursUntilEvent() <= 24) {
                if (!$this->force_remove) {
                    $validator->errors()->add('force_remove', 
                        'Cannot remove services within 24 hours of the event without force removal.'
                    );
                }
            }

            // Validate refund amount doesn't exceed service cost
            if ($this->refund_amount !== null) {
                $serviceCost = $assignedService->pivot->price ?? $assignedService->price;
                $quantity = $assignedService->pivot->quantity ?? 1;
                $totalServiceCost = $serviceCost * $quantity;
                
                if ($this->refund_amount > $totalServiceCost) {
                    $validator->errors()->add('refund_amount', 
                        'Refund amount cannot exceed the service cost of $' . number_format($totalServiceCost, 2) . '.'
                    );
                }
            }

            // Check if service removal would affect other dependent services
            $dependentServices = $this->checkServiceDependencies($event, $service);
            
            if (!empty($dependentServices) && !$this->force_remove) {
                $dependentNames = implode(', ', array_column($dependentServices, 'name'));
                $validator->errors()->add('force_remove', 
                    "Removing this service would affect dependent services: {$dependentNames}. Use force removal to proceed."
                );
            }

            // Check if service has already been invoiced
            if ($event->hasInvoice() && $event->invoice->payment_status === 'paid') {
                if (!$this->force_remove) {
                    $validator->errors()->add('force_remove', 
                        'Cannot remove services from events with paid invoices without force removal.'
                    );
                }
            }
        });
    }

    /**
     * Check for service dependencies.
     */
    private function checkServiceDependencies(Event $event, Service $service): array
    {
        $dependencies = [];
        
        // Check if other assigned services depend on this service
        $assignedServices = $event->services()->where('service_id', '!=', $service->id)->get();
        
        foreach ($assignedServices as $assignedService) {
            // Check if the assigned service has this service as a prerequisite
            if ($assignedService->prerequisites()->where('prerequisite_service_id', $service->id)->exists()) {
                $dependencies[] = [
                    'id' => $assignedService->id,
                    'name' => $assignedService->service_name,
                    'type' => 'prerequisite',
                ];
            }
            
            // Check for complementary services that work together
            if ($assignedService->complementary_services()->where('complementary_service_id', $service->id)->exists()) {
                $dependencies[] = [
                    'id' => $assignedService->id,
                    'name' => $assignedService->service_name,
                    'type' => 'complementary',
                ];
            }
        }
        
        return $dependencies;
    }

    /**
     * Get removal summary for confirmation.
     */
    public function getRemovalSummary(Event $event, Service $service): array
    {
        $assignedService = $event->services()->where('service_id', $service->id)->first();
        
        if (!$assignedService) {
            return [];
        }
        
        $serviceCost = $assignedService->pivot->price ?? $assignedService->price;
        $quantity = $assignedService->pivot->quantity ?? 1;
        $totalServiceCost = $serviceCost * $quantity;
        
        return [
            'service' => [
                'id' => $service->id,
                'name' => $service->service_name,
                'description' => $service->service_description,
            ],
            'assignment' => [
                'quantity' => $quantity,
                'unit_price' => number_format($serviceCost, 2),
                'total_cost' => number_format($totalServiceCost, 2),
                'assigned_at' => $assignedService->pivot->created_at?->format('Y-m-d H:i:s'),
                'scheduled_date' => $assignedService->pivot->scheduled_date,
                'scheduled_time' => $assignedService->pivot->scheduled_time,
            ],
            'removal' => [
                'reason' => $this->reason,
                'refund_amount' => $this->refund_amount ? number_format($this->refund_amount, 2) : null,
                'force_remove' => $this->force_remove ?? false,
            ],
            'impact' => [
                'cost_reduction' => number_format($totalServiceCost, 2),
                'dependencies' => $this->checkServiceDependencies($event, $service),
                'warnings' => $this->getRemovalWarnings($event, $service),
            ],
        ];
    }

    /**
     * Get warnings about service removal.
     */
    private function getRemovalWarnings(Event $event, Service $service): array
    {
        $warnings = [];
        
        if ($event->status === 'confirmed' && $event->getHoursUntilEvent() <= 48) {
            $warnings[] = 'Event is within 48 hours - removal may affect event setup.';
        }
        
        if ($event->hasInvoice()) {
            $warnings[] = 'Event has an existing invoice - removal may require invoice adjustment.';
        }
        
        $dependencies = $this->checkServiceDependencies($event, $service);
        if (!empty($dependencies)) {
            $warnings[] = 'Other services depend on this service and may be affected.';
        }
        
        if ($service->requires_advance_notice) {
            $noticeHours = $service->advance_notice_hours ?? 24;
            if ($event->getHoursUntilEvent() <= $noticeHours) {
                $warnings[] = "This service requires {$noticeHours} hours advance notice for changes.";
            }
        }
        
        return $warnings;
    }
}