<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventInvoice;
use App\Models\InvoiceItem;
use App\Http\Requests\GenerateInvoiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InvoiceService
{
    /**
     * Generate an invoice for an event.
     */
    public function generateInvoice(Event $event, GenerateInvoiceRequest $request): EventInvoice
    {
        try {
            DB::beginTransaction();

            // Validate event can have invoice generated
            $this->validateEventForInvoiceGeneration($event);

            $validatedData = $request->getValidatedDataWithDefaults();

            // Create the invoice
            $invoice = $this->createInvoice($event, $validatedData);

            // Add items to invoice
            $this->addItemsToInvoice($invoice, $event, $validatedData);

            // Calculate totals
            $invoice->calculateTotals();

            // Handle post-generation actions
            $this->handlePostGenerationActions($invoice, $validatedData);

            DB::commit();

            Log::info('Invoice generated successfully', [
                'invoice_id' => $invoice->id,
                'event_id' => $event->id,
                'total_amount' => $invoice->total_amount,
            ]);

            return $invoice;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice generation failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Regenerate an existing invoice with updated data.
     */
    public function regenerateInvoice(EventInvoice $invoice, GenerateInvoiceRequest $request): EventInvoice
    {
        try {
            DB::beginTransaction();

            // Validate invoice can be regenerated
            $this->validateInvoiceForRegeneration($invoice);

            $validatedData = $request->getValidatedDataWithDefaults();

            // Clear existing items
            $invoice->items()->delete();

            // Update invoice data
            $this->updateInvoiceData($invoice, $validatedData);

            // Add items to invoice
            $this->addItemsToInvoice($invoice, $invoice->event, $validatedData);

            // Calculate totals
            $invoice->calculateTotals();

            // Handle post-generation actions
            $this->handlePostGenerationActions($invoice, $validatedData);

            DB::commit();

            Log::info('Invoice regenerated successfully', [
                'invoice_id' => $invoice->id,
                'event_id' => $invoice->event_id,
                'total_amount' => $invoice->total_amount,
            ]);

            return $invoice;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice regeneration failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Calculate invoice totals with detailed breakdown.
     */
    public function calculateInvoiceTotals(EventInvoice $invoice): array
    {
        $items = $invoice->items()->get();
        
        $breakdown = [
            'items' => [],
            'subtotals' => [
                'services' => 0,
                'inventory' => 0,
                'custom' => 0,
                'total' => 0,
            ],
            'discount' => [
                'percentage' => $invoice->discount_percentage ?? 0,
                'amount' => 0,
            ],
            'tax' => [
                'rate' => $invoice->tax_rate ?? 0,
                'amount' => 0,
            ],
            'totals' => [
                'subtotal' => 0,
                'discount_amount' => 0,
                'taxable_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ],
        ];

        // Calculate item totals
        foreach ($items as $item) {
            $itemSubtotal = $item->quantity * $item->unit_price;
            $itemDiscount = $itemSubtotal * (($item->discount_percentage ?? 0) / 100);
            $itemTaxable = $itemSubtotal - $itemDiscount;
            $itemTax = $itemTaxable * ($item->tax_rate ?? 0);
            $itemTotal = $itemSubtotal - $itemDiscount + $itemTax;

            $itemBreakdown = [
                'id' => $item->id,
                'type' => $item->item_type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $itemSubtotal,
                'discount_percentage' => $item->discount_percentage ?? 0,
                'discount_amount' => $itemDiscount,
                'taxable_amount' => $itemTaxable,
                'tax_rate' => $item->tax_rate ?? 0,
                'tax_amount' => $itemTax,
                'total' => $itemTotal,
            ];

            $breakdown['items'][] = $itemBreakdown;
            $breakdown['subtotals'][$item->item_type] += $itemSubtotal;
            $breakdown['subtotals']['total'] += $itemSubtotal;
        }

        // Calculate invoice-level totals
        $subtotal = $breakdown['subtotals']['total'];
        $discountAmount = $subtotal * (($invoice->discount_percentage ?? 0) / 100);
        $taxableAmount = $subtotal - $discountAmount;
        $taxAmount = $taxableAmount * ($invoice->tax_rate ?? 0);
        $totalAmount = $subtotal - $discountAmount + $taxAmount;

        $breakdown['discount']['amount'] = $discountAmount;
        $breakdown['tax']['amount'] = $taxAmount;
        $breakdown['totals'] = [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'taxable_amount' => $taxableAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];

        return $breakdown;
    }

    /**
     * Generate a unique invoice number.
     */
    public function generateInvoiceNumber(Carbon $date = null): string
    {
        $date = $date ?? now();
        $year = $date->year;
        $month = $date->format('m');
        
        // Get the last invoice number for this year and month
        $lastInvoice = EventInvoice::whereYear('generated_at', $year)
                                 ->whereMonth('generated_at', $date->month)
                                 ->whereNotNull('invoice_number')
                                 ->orderBy('id', 'desc')
                                 ->first();
        
        $nextNumber = 1;
        if ($lastInvoice && $lastInvoice->invoice_number) {
            // Extract number from format: INV-YYYYMM-XXXX
            $parts = explode('-', $lastInvoice->invoice_number);
            if (count($parts) === 3) {
                $nextNumber = (int) $parts[2] + 1;
            }
        }
        
        return 'INV-' . $year . $month . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate estimated invoice total for an event.
     */
    public function calculateEstimatedTotal(Event $event, array $options = []): array
    {
        $includeServices = $options['include_services'] ?? true;
        $includeInventory = $options['include_inventory'] ?? true;
        $customItems = $options['custom_items'] ?? [];
        $taxRate = $options['tax_rate'] ?? config('invoice.default_tax_rate', 0.08);
        $discountPercentage = $options['discount_percentage'] ?? 0;

        $breakdown = [
            'services' => 0,
            'inventory' => 0,
            'custom' => 0,
            'subtotal' => 0,
            'discount_amount' => 0,
            'taxable_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ];

        // Calculate services total
        if ($includeServices) {
            foreach ($event->services as $service) {
                $quantity = $service->pivot->quantity ?? 1;
                $price = $service->pivot->price ?? $service->price;
                $breakdown['services'] += $quantity * $price;
            }
        }

        // Calculate inventory total
        if ($includeInventory) {
            foreach ($event->inventories as $inventory) {
                $quantity = $inventory->pivot->quantity ?? 1;
                $price = $inventory->rental_price ?? $inventory->price_per_unit;
                $breakdown['inventory'] += $quantity * $price;
            }
        }

        // Calculate custom items total
        foreach ($customItems as $item) {
            if (isset($item['quantity'], $item['unit_price'])) {
                $breakdown['custom'] += $item['quantity'] * $item['unit_price'];
            }
        }

        // Calculate totals
        $breakdown['subtotal'] = $breakdown['services'] + $breakdown['inventory'] + $breakdown['custom'];
        $breakdown['discount_amount'] = $breakdown['subtotal'] * ($discountPercentage / 100);
        $breakdown['taxable_amount'] = $breakdown['subtotal'] - $breakdown['discount_amount'];
        $breakdown['tax_amount'] = $breakdown['taxable_amount'] * $taxRate;
        $breakdown['total_amount'] = $breakdown['subtotal'] - $breakdown['discount_amount'] + $breakdown['tax_amount'];

        return $breakdown;
    }

    /**
     * Get invoice statistics for reporting.
     */
    public function getInvoiceStatistics(array $filters = []): array
    {
        $query = EventInvoice::query();

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->where('generated_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('generated_at', '<=', $filters['date_to']);
        }

        if (isset($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        $invoices = $query->get();

        return [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('total_amount'),
            'paid_invoices' => $invoices->where('payment_status', 'paid')->count(),
            'paid_amount' => $invoices->where('payment_status', 'paid')->sum('total_amount'),
            'pending_invoices' => $invoices->where('payment_status', 'pending')->count(),
            'pending_amount' => $invoices->where('payment_status', 'pending')->sum('total_amount'),
            'overdue_invoices' => $invoices->filter->isOverdue()->count(),
            'overdue_amount' => $invoices->filter->isOverdue()->sum('total_amount'),
            'average_invoice_amount' => $invoices->count() > 0 ? $invoices->avg('total_amount') : 0,
            'payment_rate' => $invoices->count() > 0 ? 
                ($invoices->where('payment_status', 'paid')->count() / $invoices->count()) * 100 : 0,
        ];
    }

    /**
     * Process invoice payment.
     */
    public function processPayment(EventInvoice $invoice, array $paymentData): EventInvoice
    {
        try {
            DB::beginTransaction();

            $paymentMethod = $paymentData['payment_method'] ?? null;
            $paymentDate = isset($paymentData['payment_date']) ? 
                Carbon::parse($paymentData['payment_date']) : now();
            $amount = $paymentData['amount'] ?? $invoice->total_amount;
            $notes = $paymentData['notes'] ?? null;

            // Determine payment status
            if ($amount >= $invoice->total_amount) {
                $invoice->markAsPaid($paymentMethod, $paymentDate);
            } else {
                $invoice->markAsPartiallyPaid($amount, $paymentMethod);
            }

            // Add payment notes
            if ($notes) {
                $existingNotes = $invoice->notes ? $invoice->notes . "\n" : '';
                $invoice->update(['notes' => $existingNotes . "Payment: " . $notes]);
            }

            DB::commit();

            Log::info('Invoice payment processed', [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'status' => $invoice->payment_status,
            ]);

            return $invoice;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Invoice payment processing failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send invoice to client.
     */
    public function sendInvoiceToClient(EventInvoice $invoice, array $options = []): bool
    {
        try {
            // Mark invoice as sent
            $invoice->sendToClient();

            // Here you would integrate with email service
            // Mail::to($invoice->event->client->email)->send(new InvoiceMail($invoice));

            Log::info('Invoice sent to client', [
                'invoice_id' => $invoice->id,
                'client_email' => $invoice->event->client->email,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send invoice to client', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate event for invoice generation.
     */
    private function validateEventForInvoiceGeneration(Event $event): void
    {
        if ($event->hasInvoice()) {
            throw new \InvalidArgumentException('Event already has an invoice generated.');
        }

        $validStatuses = ['confirmed', 'in_progress', 'completed'];
        if (!in_array($event->status, $validStatuses)) {
            throw new \InvalidArgumentException('Invoice can only be generated for confirmed, in-progress, or completed events.');
        }

        $hasServices = $event->services()->exists();
        $hasInventory = $event->inventories()->exists();

        if (!$hasServices && !$hasInventory) {
            throw new \InvalidArgumentException('Event must have services or inventory to generate an invoice.');
        }
    }

    /**
     * Validate invoice for regeneration.
     */
    private function validateInvoiceForRegeneration(EventInvoice $invoice): void
    {
        if (!$invoice->canBeEdited()) {
            throw new \InvalidArgumentException('Invoice cannot be regenerated in its current state.');
        }
    }

    /**
     * Create the invoice record.
     */
    private function createInvoice(Event $event, array $data): EventInvoice
    {
        return EventInvoice::create([
            'event_id' => $event->id,
            'due_date' => $data['due_date'],
            'tax_rate' => $data['tax_rate'],
            'discount_percentage' => $data['discount_percentage'] ?? 0,
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
            'terms_and_conditions' => $data['terms_and_conditions'],
            'generated_by' => auth()->id(),
        ]);
    }

    /**
     * Update existing invoice data.
     */
    private function updateInvoiceData(EventInvoice $invoice, array $data): void
    {
        $invoice->update([
            'due_date' => $data['due_date'],
            'tax_rate' => $data['tax_rate'],
            'discount_percentage' => $data['discount_percentage'] ?? 0,
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
            'terms_and_conditions' => $data['terms_and_conditions'],
        ]);
    }

    /**
     * Add items to invoice.
     */
    private function addItemsToInvoice(EventInvoice $invoice, Event $event, array $data): void
    {
        $sortOrder = 1;

        // Add services
        if ($data['include_services'] ?? true) {
            foreach ($event->services as $service) {
                $invoice->items()->create([
                    'item_type' => 'service',
                    'item_id' => $service->id,
                    'description' => $service->service_name,
                    'quantity' => $service->pivot->quantity ?? 1,
                    'unit_price' => $service->pivot->price ?? $service->price,
                    'total_price' => ($service->pivot->price ?? $service->price) * ($service->pivot->quantity ?? 1),
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // Add inventory
        if ($data['include_inventory'] ?? true) {
            foreach ($event->inventories as $inventory) {
                $invoice->items()->create([
                    'item_type' => 'inventory',
                    'item_id' => $inventory->id,
                    'description' => $inventory->inventory_name . ' (Rental)',
                    'quantity' => $inventory->pivot->quantity ?? 1,
                    'unit_price' => $inventory->rental_price ?? $inventory->price_per_unit,
                    'total_price' => ($inventory->rental_price ?? $inventory->price_per_unit) * ($inventory->pivot->quantity ?? 1),
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // Add custom items
        if (isset($data['custom_items']) && is_array($data['custom_items'])) {
            foreach ($data['custom_items'] as $customItem) {
                $invoice->items()->create([
                    'item_type' => 'custom',
                    'item_id' => null,
                    'description' => $customItem['description'],
                    'quantity' => $customItem['quantity'],
                    'unit_price' => $customItem['unit_price'],
                    'total_price' => $customItem['quantity'] * $customItem['unit_price'],
                    'notes' => $customItem['notes'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }
    }

    /**
     * Handle post-generation actions.
     */
    private function handlePostGenerationActions(EventInvoice $invoice, array $data): void
    {
        // Auto-approve if requested
        if ($data['auto_approve'] ?? false) {
            $invoice->approve();
        }

        // Send to client if requested
        if ($data['send_to_client'] ?? false) {
            $this->sendInvoiceToClient($invoice);
        }
    }
}