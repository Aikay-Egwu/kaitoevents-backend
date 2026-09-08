<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization will be handled by middleware
    }

    public function rules(): array
    {
        $inventoryId = $this->route('inventory') ?? $this->route('id');

        return [
            // Required core fields — sent on every edit (with sane fallbacks from existing record)
            'name'                     => ['filled', 'required', 'string', 'max:255'],
            'inventory_category_id'    => ['filled', 'required', 'integer', 'exists:inventory_categories,id'],
            'price'                    => ['filled', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'quantity_available'       => ['filled', 'required', 'integer', 'min:0', 'max:99999'],

            // Identification & classification (nullable — "" sent by frontend, normalized to null)
            'asset_id'                 => [
                'nullable', 'string', 'max:32',
                Rule::unique('inventories', 'asset_id')->ignore($inventoryId),
            ],
            'unit'                     => ['nullable', 'string', 'max:20'],
            'condition'                => ['nullable', 'in:excellent_good,fair_wear,needs_repair,retired_written_off'],
            'color'                    => ['nullable', 'string', 'max:100'],
            'description'              => ['nullable', 'string', 'max:1000'],

            // Financial (nullable numeric — "" sent by frontend, normalized to null)
            'cost_price'               => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'unit_replacement_cost'    => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'current_value'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'total_revenue_generated'  => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'break_even_events'        => ['nullable', 'integer', 'min:0', 'max:99999'],
            'profit_deficit'           => ['nullable', 'numeric', 'min:-9999999999.99', 'max:9999999999.99'],

            // Dates & compliance (nullable date strings — "" normalized to null)
            'purchase_date'            => ['nullable', 'date'],
            'last_checked'             => ['nullable', 'date'],
            'pat_service_due'          => ['nullable', 'date'],

            // Location (accept either normalized or legacy — prepareForValidation splits legacy)
            'location_name'            => ['nullable', 'string', 'max:100'],
            'location_zone'            => ['nullable', 'string', 'max:50'],
            'location'                 => ['nullable', 'string', 'max:255'],

            // Supplier, notes, counters
            'supplier'                 => ['nullable', 'string', 'max:150'],
            'events_used_count'        => ['nullable', 'integer', 'min:0', 'max:99999'],
            'notes'                    => ['nullable', 'string'],

            // Status
            'total_quantity'           => ['nullable', 'integer', 'min:0', 'max:99999'],
            'is_active'                => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                  => 'The inventory item name is required.',
            'inventory_category_id.required' => 'Please select a category for this inventory item.',
            'inventory_category_id.exists'   => 'The selected category does not exist.',
            'price.required'                 => 'The price is required.',
            'quantity_available.required'    => 'The quantity available is required.',
            'asset_id.unique'                => 'This Asset ID already exists in the system.',
            'condition.in'                   => 'Invalid condition selection.',
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
        $merged = [];

        // 1) Normalize empty strings → null for every nullable input field.
        //    Frontend always sends the key with "" when cleared; convert that
        //    to null so nullable DB columns are properly nulled.
        $stringNullable = [
            'asset_id', 'unit', 'condition', 'color', 'description',
            'location_name', 'location_zone', 'location', 'supplier',
            'notes',
        ];
        foreach ($stringNullable as $k) {
            if ($this->has($k) && $this->input($k) === '') {
                $merged[$k] = null;
            }
        }

        // 2) Numeric nullables: '' → null, but keep actual zero/numbers.
        $numericNullable = [
            'cost_price', 'unit_replacement_cost', 'current_value',
            'total_revenue_generated', 'break_even_events', 'profit_deficit',
        ];
        foreach ($numericNullable as $k) {
            if ($this->has($k) && ($this->input($k) === '' || $this->input($k) === null)) {
                $merged[$k] = null;
            }
        }

        // 3) Dates: '' → null
        $dateNullable = ['purchase_date', 'last_checked', 'pat_service_due'];
        foreach ($dateNullable as $k) {
            if ($this->has($k) && ($this->input($k) === '' || $this->input($k) === null)) {
                $merged[$k] = null;
            }
        }

        // 4) is_active from FormData comes as string "1"/"0" — cast to boolean.
        if ($this->has('is_active')) {
            $v = $this->input('is_active');
            if (is_string($v)) {
                $merged['is_active'] = in_array($v, ['1', 'true', 'on', 'yes'], true);
            }
        }

        // 5) events_used_count: accept "" → 0 (non-nullable counter)
        if ($this->has('events_used_count') && ($this->input('events_used_count') === '' || $this->input('events_used_count') === null)) {
            $merged['events_used_count'] = 0;
        }

        // 6) Legacy → normalized location auto-split (runs after empty-normalization
        //    to keep the same semantics as StoreInventoryRequest).
        if (
            ($this->has('location_name') && $this->input('location_name') === null ||
             !$this->filled('location_name'))
            && $this->filled('location')
        ) {
            $parts = preg_split('/\s*[-—\/]\s*/', trim((string)$this->location), 2);
            $merged['location_name'] = $parts[0] ?? null;
            $merged['location_zone'] = $parts[1] ?? null;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('is_active') && $this->boolean('is_active') === false) {
                $inventory = $this->route('inventory');
                if ($inventory) {
                    $activeEventsCount = $inventory->events()
                        ->where('event_date', '>=', now())
                        ->whereIn('status', ['confirmed', 'in_progress'])
                        ->count();

                    if ($activeEventsCount > 0) {
                        $validator->errors()->add(
                            'is_active',
                            'Cannot deactivate inventory item that is assigned to active events.'
                        );
                    }
                }
            }
        });
    }
}
