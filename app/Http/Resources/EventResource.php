<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'venue_name' => $this->venue_name ?? $this->venue?->venue_name, // Fallback to local venue_name if exists
            'venue_address' => $this->venue_address ?? $this->venue?->venue_address,
            'guest_number' => $this->guest_number,
            'budget' => $this->budget ? (float) $this->budget : null,
            'budget_raw' => $this->budget,
            'special_instructions' => $this->special_instructions,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'duration_hours' => $this->getDurationHours(),
            'is_upcoming' => $this->isUpcoming(),
            'is_past' => $this->isPast(),
            'days_until_event' => $this->getDaysUntilEvent(),

            // Permissions
            'permissions' => [
                'can_modify' => $this->canBeModified(),
                'can_cancel' => $this->canBeCancelled(),
            ],
            'can_be_cancelled' => $this->canBeCancelled(), // Backward compatibility if needed at root

            // Relationships
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'email' => $this->client->email,
                    'phone' => $this->client->phone,
                ];
            }),

            'event_type' => $this->whenLoaded('eventType', function () {
                return [
                    'id' => $this->eventType->id,
                    'name' => $this->eventType->event_type_name,
                    'description' => $this->eventType->event_type_description,
                ];
            }),

            // Calculated totals
            'totals' => $this->when($request->query('include_totals'), function () {
                return [
                    'services_total' => $this->getServicesTotal(),
                    'inventories_total' => $this->getInventoriesTotal(),
                    'estimated_total' => $this->getEstimatedTotal(),
                ];
            }),

            'created_at' => $this->created_at->diffForHumans(),
            'created_date' => $this->created_at->format('M d, Y'),
            'updated_at' => $this->updated_at->diffForHumans(),
        ];
    }

    /**
     * Get human-readable status label.
     */
    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pending Confirmation',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get status color for UI.
     */
    private function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'confirmed' => 'info',
            'in_progress' => 'primary',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Calculate event duration in hours.
     */
    private function getDurationHours(): ?float
    {
        if (!$this->start_time || !$this->end_time) {
            return null;
        }

        $start = strtotime($this->start_time);
        $end = strtotime($this->end_time);

        return round(($end - $start) / 3600, 2);
    }

    /**
     * Check if event is upcoming.
     */
    private function isUpcoming(): bool
    {
        if (!$this->event_date || !$this->start_time) {
            return false;
        }

        $eventDateTime = strtotime($this->event_date . ' ' . $this->start_time);
        return $eventDateTime > time();
    }

    /**
     * Check if event is past.
     */
    private function isPast(): bool
    {
        if (!$this->event_date || !$this->end_time) {
            return false;
        }

        $eventDateTime = strtotime($this->event_date . ' ' . $this->end_time);
        return $eventDateTime < time();
    }

    /**
     * Get days until event.
     */
    private function getDaysUntilEvent(): ?int
    {
        if (!$this->event_date) {
            return null;
        }

        $eventDate = strtotime($this->event_date);
        $today = strtotime('today');

        return (int) (($eventDate - $today) / 86400);
    }

    /**
     * Check if event can be modified.
     */
    private function canBeModified(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    /**
     * Check if event can be cancelled.
     */
    private function canBeCancelled(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    /**
     * Calculate total cost of services.
     */
    private function getServicesTotal(): float
    {
        if (!$this->relationLoaded('services')) {
            return 0;
        }

        return $this->services->sum(function ($service) {
            return $service->price * ($service->pivot->quantity ?? 1);
        });
    }

    /**
     * Calculate total cost of inventories.
     */
    private function getInventoriesTotal(): float
    {
        if (!$this->relationLoaded('inventories')) {
            return 0;
        }

        return $this->inventories->sum(function ($inventory) {
            return $inventory->price * ($inventory->pivot->quantity ?? 1);
        });
    }

    /**
     * Calculate estimated total cost.
     */
    private function getEstimatedTotal(): float
    {
        return $this->getServicesTotal() + $this->getInventoriesTotal();
    }

    /**
     * Calculate event completion percentage based on status and requirements.
     */
    private function getCompletionPercentage(): int
    {
        $percentage = 0;

        // Base completion based on status
        switch ($this->status) {
            case 'pending':
                $percentage = 20;
                break;
            case 'confirmed':
                $percentage = 40;
                break;
            case 'in_progress':
                $percentage = 70;
                break;
            case 'completed':
                $percentage = 100;
                break;
            case 'cancelled':
                $percentage = 0;
                break;
        }

        // Adjust based on assigned resources
        if ($this->status !== 'completed' && $this->status !== 'cancelled') {
            $hasServices = $this->relationLoaded('services') && $this->services->count() > 0;
            $hasInventory = $this->relationLoaded('inventories') && $this->inventories->count() > 0;
            $hasInvoice = $this->relationLoaded('invoice') && $this->invoice !== null;

            if ($hasServices)
                $percentage += 10;
            if ($hasInventory)
                $percentage += 10;
            if ($hasInvoice)
                $percentage += 10;

            $percentage = min($percentage, 95); // Cap at 95% until completed
        }

        return $percentage;
    }
}