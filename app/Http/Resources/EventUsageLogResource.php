<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event Usage Log resource — represents one inventory item deployment / return cycle.
 */
class EventUsageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'inventory_id'       => $this->inventory_id,
            'inventory_name'     => $this->whenLoaded('inventory', fn () => $this->inventory?->name),
            'asset_id'           => $this->whenLoaded('inventory', fn () => $this->inventory?->asset_id),

            // Event linkage (structured FK or free-text reference from Excel import)
            'event_id'           => $this->event_id,
            'event_ref'          => $this->event_ref,
            'event_name_client'  => $this->event_name_client,
            'event_date'         => $this->event_date?->toDateString(),
            'event_name'         => $this->whenLoaded('event', fn () => $this->event?->name),

            // Deployment / return details
            'quantity_deployed'  => $this->quantity_deployed,
            'condition_out'      => $this->condition_out,
            'condition_out_label' => $this->condition_out_label,
            'condition_back'     => $this->condition_back,
            'condition_back_label' => $this->condition_back_label,
            'date_returned'      => $this->date_returned?->toDateString(),
            'notes_damage_action' => $this->notes_damage_action,

            // Audit
            'user_id'            => $this->user_id,
            'user_name'          => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at'         => $this->created_at?->toISOString(),
            'updated_at'         => $this->updated_at?->toISOString(),
        ];
    }
}
