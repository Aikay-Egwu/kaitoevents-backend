<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules(): array
    {
        return [
            // Required core fields
            'name'                     => ['required', 'string', 'max:255'],
            'inventory_category_id'    => ['required', 'integer', 'exists:inventory_categories,id'],
            'price'                    => ['required', 'numeric', 'min:0', 'max:99999999.99'],

            // Identification & classification
            'asset_id'                 => ['nullable', 'string', 'max:32', 'unique:inventories,asset_id'],
            'unit'                     => ['nullable', 'string', 'max:20'],
            'condition'                => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'color'                    => ['nullable', 'string', 'max:100'],
            'description'              => ['nullable', 'string', 'max:1000'],

            // Financial
            'cost_price'               => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'unit_replacement_cost'    => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'current_value'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'total_revenue_generated'  => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'break_even_events'        => ['nullable', 'integer', 'min:0', 'max:99999'],
            'profit_deficit'           => ['nullable', 'numeric', 'min:-9999999999.99', 'max:9999999999.99'],

            // Dates & compliance
            'purchase_date'            => ['nullable', 'date'],
            'last_checked'             => ['nullable', 'date'],
            'pat_service_due'          => ['nullable', 'date'],

            // Normalized location (preferred) OR legacy location string — accept either
            'location_name'            => ['nullable', 'string', 'max:100'],
            'location_zone'            => ['nullable', 'string', 'max:50'],
            'location'                 => ['nullable', 'string', 'max:255'],

            // Supplier, notes, counters
            'supplier'                 => ['nullable', 'string', 'max:150'],
            'events_used_count'        => ['nullable', 'integer', 'min:0', 'max:99999'],
            'notes'                    => ['nullable', 'string'],

            // Quantity & status
            'total_quantity'           => ['nullable', 'integer', 'min:0', 'max:99999'],
            'quantity_available'       => ['nullable', 'integer', 'min:0', 'max:99999'],
            'is_active'                => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                     => 'The inventory item name is required.',
            'inventory_category_id.required'    => 'Please select a category for this inventory item.',
            'inventory_category_id.exists'      => 'The selected category does not exist.',
            'price.required'                    => 'The price is required.',
            'asset_id.unique'                   => 'This Asset ID already exists in the system.',
            'condition.in'                      => 'Invalid condition selection.',
        ];
    }

    public function attributes(): array
    {
        return [
            'inventory_category_id' => 'category',
            'quantity_available'    => 'quantity available',
            'is_active'             => 'active status',
            'location_name'         => 'location',
            'pat_service_due'       => 'PAT service due date',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Legacy → normalized location auto-split: if only "location" is supplied,
        // split it into location_name + location_zone using standard delimiters.
        if (!$this->filled('location_name') && $this->filled('location')) {
            $parts = preg_split('/\s*[-—\/]\s*/', trim($this->location), 2);
            $this->merge([
                'location_name' => $parts[0] ?? null,
                'location_zone' => $parts[1] ?? null,
            ]);
        }

        // Sensible defaults
        if (!$this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }
        if (!$this->filled('quantity_available')) {
            $this->merge(['quantity_available' => 0]);
        }
        if (!$this->filled('condition')) {
            $this->merge(['condition' => 'excellent_good']);
        }
        if (!$this->filled('total_quantity')) {
            $this->merge(['total_quantity' => $this->quantity_available ?? 0]);
        }
        if (!$this->filled('events_used_count')) {
            $this->merge(['events_used_count' => 0]);
        }
        if (!$this->filled('total_revenue_generated')) {
            $this->merge(['total_revenue_generated' => 0]);
        }
    }
}
