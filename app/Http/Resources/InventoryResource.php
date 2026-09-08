<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity
            'id'                   => $this->id,
            'asset_id'             => $this->asset_id,
            'name'                 => $this->name,

            // Classification
            'inventory_category_id' => $this->inventory_category_id,
            'category'             => $this->whenLoaded('category', function () {
                return [
                    'id'              => $this->category->id,
                    'name'            => $this->category->category_name,
                    'parent_id'       => $this->category->parent_id,
                    'parent_name'     => $this->category->parent?->category_name,
                ];
            }),
            'unit'                 => $this->unit,
            'condition'            => $this->condition,
            'condition_label'      => $this->condition_label,

            // Financial (formatted + raw for math)
            'price'                     => number_format((float)$this->price, 2),
            'price_raw'                 => $this->price,
            'cost_price'                => $this->cost_price === null ? null : number_format((float)$this->cost_price, 2),
            'cost_price_raw'            => $this->cost_price,
            'unit_replacement_cost'     => $this->unit_replacement_cost === null ? null : number_format((float)$this->unit_replacement_cost, 2),
            'unit_replacement_cost_raw' => $this->unit_replacement_cost,
            'current_value'             => $this->current_value === null ? null : number_format((float)$this->current_value, 2),
            'current_value_raw'         => $this->current_value,
            'total_revenue_generated'   => number_format((float)($this->total_revenue_generated ?? 0), 2),
            'total_revenue_generated_raw' => $this->total_revenue_generated,
            'break_even_events'         => $this->break_even_events,
            'profit_deficit'            => $this->profit_deficit === null ? null : number_format((float)$this->profit_deficit, 2),
            'profit_deficit_raw'        => $this->profit_deficit,

            // Descriptive
            'description'          => $this->description,
            'color'                => $this->color,
            'supplier'             => $this->supplier,
            'notes'                => $this->notes,

            // Dates
            'purchase_date'        => $this->purchase_date?->toDateString(),
            'last_checked'         => $this->last_checked?->toDateString(),
            'pat_service_due'      => $this->pat_service_due?->toDateString(),

            // Location: both normalized split + legacy concatenated string (backward compat)
            'location_name'        => $this->location_name,
            'location_zone'        => $this->location_zone,
            'location'             => $this->location_display,

            // Quantity & status
            'total_quantity'       => $this->total_quantity,
            'quantity_available'   => $this->quantity_available,
            'events_used_count'    => $this->events_used_count ?? 0,
            'is_active'            => $this->is_active,
            'availability_status'  => $this->getAvailabilityStatus(),

            // Audit
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),

            // Relations
            'events_count'         => $this->whenCounted('events'),
            'usage_logs_count'     => $this->whenCounted('usageLogs'),
            'repair_logs_count'    => $this->whenCounted('repairLogs'),

            'current_events'       => $this->whenLoaded('events', function () {
                return $this->events->where('event_date', '>=', now())->map(function ($event) {
                    return [
                        'id'                => $event->id,
                        'name'              => $event->name ?? 'Event #' . $event->id,
                        'event_date'        => $event->event_date,
                        'status'            => $event->status,
                        'venue_name'        => $event->venue_name,
                        'quantity_assigned' => $event->pivot->quantity ?? 1,
                    ];
                })->values();
            }),

            'usage_logs'           => $this->whenLoaded('usageLogs', function () {
                return EventUsageLogResource::collection($this->usageLogs->sortByDesc('event_date')->values());
            }),

            'repair_logs'          => $this->whenLoaded('repairLogs', function () {
                return RepairMaintenanceLogResource::collection($this->repairLogs->sortByDesc('date_logged')->values());
            }),

            'usage_statistics'     => $this->when($request->has('include_stats'), function () {
                return [
                    'total_events'     => $this->events()->count(),
                    'upcoming_events'  => $this->events()->where('event_date', '>=', now())->count(),
                    'past_events'      => $this->events()->where('event_date', '<', now())->count(),
                    'revenue_generated' => $this->calculateRevenueGenerated(),
                ];
            }),
        ];
    }

    private function getAvailabilityStatus(): string
    {
        if ($this->condition === 'retired_written_off') {
            return 'retired';
        }
        if (!$this->is_active) {
            return 'inactive';
        }
        if (($this->quantity_available ?? 0) <= 0) {
            return 'out_of_stock';
        }

        // Only refine to fully_booked / low_availability when the events relation
        // has been explicitly eager-loaded (via ?include_events=1 or the detail-page
        // loader). Otherwise skip the per-item assigned-quantity math to avoid
        // triggering an N+1 SELECT on every row of the listing.
        if ($this->relationLoaded('events')) {
            $assignedQuantity = $this->events
                ->where('event_date', '>=', now()->toDateString())
                ->whereIn('status', ['confirmed', 'in_progress'])
                ->sum(fn ($event) => (int) ($event->pivot?->quantity ?? 1));

            $availableQuantity = ($this->quantity_available ?? 0) - $assignedQuantity;

            if ($availableQuantity <= 0) {
                return 'fully_booked';
            }
            if ($availableQuantity <= 5) {
                return 'low_availability';
            }
        }

        return 'available';
    }

    private function calculateRevenueGenerated(): float
    {
        return (float)($this->events()
            ->whereIn('status', ['completed', 'paid'])
            ->sum('event_inventories.quantity') * ($this->price ?? 0));
    }
}
