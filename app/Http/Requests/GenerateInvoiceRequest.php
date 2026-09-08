<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Event;
use App\Models\EventInvoice;

class GenerateInvoiceRequest extends FormRequest
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
            'due_date' => [
                'sometimes',
                'nullable',
                'date',
                'after:today',
            ],
            
            'discount_percentage' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'terms_and_conditions' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],
            'currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:3',
                Rule::in(['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY']),
            ],
            'payment_terms_days' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            'include_services' => [
                'sometimes',
                'boolean',
            ],
            'include_inventory' => [
                'sometimes',
                'boolean',
            ],
            'custom_items' => [
                'sometimes',
                'array',
                'max:50',
            ],
            'custom_items.*.description' => [
                'required_with:custom_items',
                'string',
                'max:500',
            ],
            'custom_items.*.quantity' => [
                'required_with:custom_items',
                'integer',
                'min:1',
                'max:1000',
            ],
            'custom_items.*.unit_price' => [
                'required_with:custom_items',
                'numeric',
                'min:0',
                'max:99999.99',
            ],
            'custom_items.*.notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'send_to_client' => [
                'sometimes',
                'boolean',
            ],
            'auto_approve' => [
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
            'due_date.after' => 'Due date must be in the future.',
            'tax_rate.min' => 'Tax rate cannot be negative.',
            'tax_rate.max' => 'Tax rate cannot exceed 100%.',
            'discount_percentage.min' => 'Discount percentage cannot be negative.',
            'discount_percentage.max' => 'Discount percentage cannot exceed 100%.',
            'notes.max' => 'Notes cannot exceed 2000 characters.',
            'terms_and_conditions.max' => 'Terms and conditions cannot exceed 5000 characters.',
            'currency.size' => 'Currency must be a 3-letter code.',
            'currency.in' => 'Currency must be one of: USD, EUR, GBP, CAD, AUD, JPY.',
            'payment_terms_days.min' => 'Payment terms must be at least 1 day.',
            'payment_terms_days.max' => 'Payment terms cannot exceed 365 days.',
            'custom_items.max' => 'Cannot add more than 50 custom items.',
            'custom_items.*.description.required_with' => 'Custom item description is required.',
            'custom_items.*.description.max' => 'Custom item description cannot exceed 500 characters.',
            'custom_items.*.quantity.required_with' => 'Custom item quantity is required.',
            'custom_items.*.quantity.min' => 'Custom item quantity must be at least 1.',
            'custom_items.*.quantity.max' => 'Custom item quantity cannot exceed 1000.',
            'custom_items.*.unit_price.required_with' => 'Custom item unit price is required.',
            'custom_items.*.unit_price.min' => 'Custom item unit price cannot be negative.',
            'custom_items.*.unit_price.max' => 'Custom item unit price cannot exceed $99,999.99.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'due_date' => 'due date',
            'tax_rate' => 'tax rate',
            'discount_percentage' => 'discount percentage',
            'terms_and_conditions' => 'terms and conditions',
            'payment_terms_days' => 'payment terms',
            'include_services' => 'include services',
            'include_inventory' => 'include inventory',
            'custom_items' => 'custom items',
            'send_to_client' => 'send to client',
            'auto_approve' => 'auto approve',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        if (!$this->has('include_services')) {
            $this->merge(['include_services' => true]);
        }

        if (!$this->has('include_inventory')) {
            $this->merge(['include_inventory' => true]);
        }

        if (!$this->has('send_to_client')) {
            $this->merge(['send_to_client' => false]);
        }

        if (!$this->has('auto_approve')) {
            $this->merge(['auto_approve' => false]);
        }

        // Set due date based on payment terms
        if (!$this->has('due_date') && $this->has('payment_terms_days')) {
            $this->merge(['due_date' => now()->addDays($this->payment_terms_days)->format('Y-m-d')]);
        }

        // Format tax rate as decimal
        if ($this->has('tax_rate') && $this->tax_rate !== null) {
            $taxRate = (float) $this->tax_rate;
            // If tax rate is provided as percentage (e.g., 8.5), convert to decimal
            if ($taxRate > 1) {
                $taxRate = $taxRate / 100;
            }
            $this->merge(['tax_rate' => $taxRate]);
        }

        // Format discount percentage
        if ($this->has('discount_percentage') && $this->discount_percentage !== null) {
            $this->merge(['discount_percentage' => (float) $this->discount_percentage]);
        }

        // Process custom items
        if ($this->has('custom_items') && is_array($this->custom_items)) {
            $customItems = [];
            foreach ($this->custom_items as $item) {
                if (isset($item['description'], $item['quantity'], $item['unit_price'])) {
                    $customItems[] = [
                        'description' => trim($item['description']),
                        'quantity' => (int) $item['quantity'],
                        'unit_price' => (float) $item['unit_price'],
                        'notes' => isset($item['notes']) ? trim($item['notes']) : null,
                    ];
                }
            }
            $this->merge(['custom_items' => $customItems]);
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

            // Check if event already has an invoice
            if ($event->hasInvoice()) {
                $validator->errors()->add('event', 'This event already has an invoice generated.');
            }

            // Check if event has any billable items
            $hasServices = $event->services()->exists();
            $hasInventory = $event->inventories()->exists();
            $hasCustomItems = $this->has('custom_items') && !empty($this->custom_items);

            if (!$hasServices && !$hasInventory && !$hasCustomItems) {
                $validator->errors()->add('event', 'Event must have services, inventory, or custom items to generate an invoice.');
            }

            // Validate that at least one item type is included
            $includeServices = $this->include_services ?? true;
            $includeInventory = $this->include_inventory ?? true;

            if (!$includeServices && !$includeInventory && !$hasCustomItems) {
                $validator->errors()->add('include_services', 'At least one item type must be included in the invoice.');
            }

            // Check if event is in a valid status for invoice generation
            $validStatuses = ['confirmed', 'in_progress', 'completed'];
            if (!in_array($event->status, $validStatuses)) {
                $validator->errors()->add('event', 'Invoice can only be generated for confirmed, in-progress, or completed events.');
            }

            // Validate custom items totals don't exceed reasonable limits
            if ($this->has('custom_items') && is_array($this->custom_items)) {
                $totalCustomAmount = 0;
                foreach ($this->custom_items as $item) {
                    if (isset($item['quantity'], $item['unit_price'])) {
                        $totalCustomAmount += $item['quantity'] * $item['unit_price'];
                    }
                }

                if ($totalCustomAmount > 1000000) { // $1M limit
                    $validator->errors()->add('custom_items', 'Total custom items amount cannot exceed $1,000,000.');
                }
            }

            // Validate tax rate is reasonable
            if ($this->has('tax_rate') && $this->tax_rate !== null) {
                if ($this->tax_rate > 0.5) { // 50% max tax rate
                    $validator->errors()->add('tax_rate', 'Tax rate cannot exceed 50%.');
                }
            }

            // Validate discount doesn't exceed event total
            if ($this->has('discount_percentage') && $this->discount_percentage > 0) {
                $eventTotal = $event->getEstimatedTotal();
                $discountAmount = $eventTotal * ($this->discount_percentage / 100);
                
                if ($discountAmount > $eventTotal) {
                    $validator->errors()->add('discount_percentage', 'Discount cannot exceed the total event cost.');
                }
            }

            // Validate due date is reasonable
            if ($this->has('due_date') && $this->due_date) {
                $dueDate = \Carbon\Carbon::parse($this->due_date);
                $maxDueDate = now()->addYear();
                
                if ($dueDate->isAfter($maxDueDate)) {
                    $validator->errors()->add('due_date', 'Due date cannot be more than one year in the future.');
                }
            }
        });
    }

    /**
     * Get the validated data with calculated values.
     */
    public function getValidatedDataWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Set default values
        $validated['tax_rate'] = $validated['tax_rate'] ?? config('invoice.default_tax_rate', 0.08);
        $validated['currency'] = $validated['currency'] ?? config('invoice.default_currency', 'USD');
        $validated['due_date'] = $validated['due_date'] ?? now()->addDays(30)->format('Y-m-d');
        
        // Set default terms and conditions if not provided
        if (!isset($validated['terms_and_conditions'])) {
            $validated['terms_and_conditions'] = config('invoice.default_terms', 
                'Payment is due within 30 days of invoice date. Late payments may incur additional fees.'
            );
        }
        
        return $validated;
    }

    /**
     * Get invoice generation summary.
     */
    public function getInvoiceGenerationSummary(Event $event): array
    {
        $validated = $this->getValidatedDataWithDefaults();
        
        $summary = [
            'event' => [
                'id' => $event->id,
                'name' => $event->venue_name,
                'date' => $event->event_date?->format('Y-m-d'),
                'client' => $event->client->name,
                'status' => $event->status,
            ],
            'invoice_settings' => [
                'due_date' => $validated['due_date'],
                'tax_rate' => number_format($validated['tax_rate'] * 100, 2) . '%',
                'discount_percentage' => $validated['discount_percentage'] ?? 0,
                'currency' => $validated['currency'],
                'include_services' => $validated['include_services'],
                'include_inventory' => $validated['include_inventory'],
                'send_to_client' => $validated['send_to_client'],
                'auto_approve' => $validated['auto_approve'],
            ],
            'items_to_include' => [],
            'estimated_totals' => [],
        ];

        // Calculate items to include
        if ($validated['include_services'] && $event->services()->exists()) {
            $services = $event->services()->get();
            $serviceTotal = 0;
            
            foreach ($services as $service) {
                $itemTotal = ($service->pivot->price ?? $service->price) * ($service->pivot->quantity ?? 1);
                $serviceTotal += $itemTotal;
                
                $summary['items_to_include'][] = [
                    'type' => 'service',
                    'name' => $service->service_name,
                    'quantity' => $service->pivot->quantity ?? 1,
                    'unit_price' => number_format($service->pivot->price ?? $service->price, 2),
                    'total' => number_format($itemTotal, 2),
                ];
            }
            
            $summary['estimated_totals']['services'] = number_format($serviceTotal, 2);
        }

        if ($validated['include_inventory'] && $event->inventories()->exists()) {
            $inventories = $event->inventories()->get();
            $inventoryTotal = 0;
            
            foreach ($inventories as $inventory) {
                $itemTotal = ($inventory->rental_price ?? $inventory->price_per_unit) * ($inventory->pivot->quantity ?? 1);
                $inventoryTotal += $itemTotal;
                
                $summary['items_to_include'][] = [
                    'type' => 'inventory',
                    'name' => $inventory->inventory_name,
                    'quantity' => $inventory->pivot->quantity ?? 1,
                    'unit_price' => number_format($inventory->rental_price ?? $inventory->price_per_unit, 2),
                    'total' => number_format($itemTotal, 2),
                ];
            }
            
            $summary['estimated_totals']['inventory'] = number_format($inventoryTotal, 2);
        }

        if (isset($validated['custom_items']) && !empty($validated['custom_items'])) {
            $customTotal = 0;
            
            foreach ($validated['custom_items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_price'];
                $customTotal += $itemTotal;
                
                $summary['items_to_include'][] = [
                    'type' => 'custom',
                    'name' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => number_format($item['unit_price'], 2),
                    'total' => number_format($itemTotal, 2),
                ];
            }
            
            $summary['estimated_totals']['custom'] = number_format($customTotal, 2);
        }

        // Calculate final totals
        $subtotal = array_sum([
            $summary['estimated_totals']['services'] ?? 0,
            $summary['estimated_totals']['inventory'] ?? 0,
            $summary['estimated_totals']['custom'] ?? 0,
        ]);

        $discountAmount = $subtotal * (($validated['discount_percentage'] ?? 0) / 100);
        $taxableAmount = $subtotal - $discountAmount;
        $taxAmount = $taxableAmount * $validated['tax_rate'];
        $totalAmount = $subtotal - $discountAmount + $taxAmount;

        $summary['estimated_totals']['subtotal'] = number_format($subtotal, 2);
        $summary['estimated_totals']['discount'] = number_format($discountAmount, 2);
        $summary['estimated_totals']['tax'] = number_format($taxAmount, 2);
        $summary['estimated_totals']['total'] = number_format($totalAmount, 2);

        return $summary;
    }
}