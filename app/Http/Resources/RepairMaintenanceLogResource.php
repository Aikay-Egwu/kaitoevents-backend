<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Repair & Maintenance Log resource — captures PAT tests, repairs, servicing, and retirement.
 */
class RepairMaintenanceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'inventory_id'         => $this->inventory_id,
            'inventory_name'       => $this->whenLoaded('inventory', fn () => $this->inventory?->name),
            'asset_id'             => $this->whenLoaded('inventory', fn () => $this->inventory?->asset_id),

            // Repair / maintenance body
            'issue_work_required'  => $this->issue_work_required,
            'date_logged'          => $this->date_logged?->toDateString(),
            'date_resolved'        => $this->date_resolved?->toDateString(),
            'cost'                 => number_format((float)($this->cost ?? 0), 2),
            'cost_raw'             => $this->cost,
            'action_taken'         => $this->action_taken,

            // Technician (free-text for external, FK for internal user)
            'repaired_by'          => $this->repaired_by,
            'technician_user_id'   => $this->technician_user_id,
            'technician_name'      => $this->whenLoaded('technician', fn () => $this->technician?->name),

            // Workflow
            'new_condition'        => $this->new_condition,
            'new_condition_label'  => $this->new_condition_label,
            'status'               => $this->status,
            'status_label'         => $this->status_label,
            'notes'                => $this->notes,

            // Audit
            'user_id'              => $this->user_id,
            'user_name'            => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),
        ];
    }
}
