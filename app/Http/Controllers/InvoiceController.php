<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateInvoiceRequest;
use App\Models\Event;
use App\Models\EventInvoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;

class InvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Get all invoices with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = EventInvoice::with(['event.client', 'generatedBy', 'approvedBy']);

        // Apply filters
        $this->applyInvoiceFilters($query, $request);

        // Apply sorting
        $sortBy = $request->get('sort_by', 'generated_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        $allowedSortFields = [
            'generated_at', 'due_date', 'total_amount', 'payment_status', 
            'invoice_status', 'invoice_number', 'payment_date'
        ];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $invoices = $query->paginate($perPage);

        $invoiceData = $invoices->getCollection()->map(function ($invoice) {
            return $this->formatInvoiceData($invoice);
        });

        return response()->json([
            'success' => true,
            'data' => $invoiceData,
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'from' => $invoices->firstItem(),
                'to' => $invoices->lastItem(),
            ],
            'filters_applied' => $this->getAppliedInvoiceFilters($request),
            'summary' => $this->getInvoicesSummary($query->get()),
        ]);
    }

    /**
     * Generate an invoice for an event.
     */
    public function generateInvoice(GenerateInvoiceRequest $request, Event $event): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->generateInvoice($event, $request);

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($invoice, true),
                'message' => "Invoice {$invoice->getFormattedInvoiceNumber()} has been generated successfully.",
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_GENERATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_GENERATION_ERROR',
                    'message' => 'Failed to generate invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Show a specific invoice.
     */
    public function show(EventInvoice $invoice): JsonResponse
    {
        $invoice->load(['event.client', 'items', 'generatedBy', 'approvedBy']);
        
        // Mark as viewed if not already viewed
        $invoice->markAsViewed();

        return response()->json([
            'success' => true,
            'data' => $this->formatInvoiceData($invoice, true),
        ]);
    }

    /**
     * Update an invoice.
     */
    public function update(Request $request, EventInvoice $invoice): JsonResponse
    {
        $request->validate([
            'payment_status' => 'sometimes|string|in:pending,paid,partially_paid,overdue,cancelled,refunded',
            'payment_method' => 'sometimes|nullable|string|max:50',
            'payment_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:2000',
            'due_date' => 'sometimes|date|after:today',
            'invoice_status' => 'sometimes|string|in:draft,sent,approved,cancelled',
        ]);

        try {
            // Check if invoice can be updated
            if (!$invoice->canBeEdited() && $request->hasAny(['due_date', 'notes'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVOICE_NOT_EDITABLE',
                        'message' => 'Invoice cannot be edited in its current state.',
                    ],
                ], 422);
            }

            $updateData = $request->only([
                'payment_status', 'payment_method', 'payment_date', 
                'notes', 'due_date', 'invoice_status'
            ]);

            $invoice->update($updateData);

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($invoice->fresh()),
                'message' => 'Invoice updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_UPDATE_FAILED',
                    'message' => 'Failed to update invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Process payment for an invoice.
     */
    public function processPayment(Request $request, EventInvoice $invoice): JsonResponse
    {
        $request->validate([
            'payment_method' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01|max:' . $invoice->total_amount,
            'payment_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);

        try {
            $paymentData = $request->only(['payment_method', 'amount', 'payment_date', 'notes']);
            
            $paidInvoice = $this->invoiceService->processPayment($invoice, $paymentData);

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($paidInvoice),
                'message' => 'Payment processed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PAYMENT_PROCESSING_FAILED',
                    'message' => 'Failed to process payment.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Send invoice to client.
     */
    public function sendToClient(EventInvoice $invoice): JsonResponse
    {
        try {
            $sent = $this->invoiceService->sendInvoiceToClient($invoice);

            if ($sent) {
                return response()->json([
                    'success' => true,
                    'data' => $this->formatInvoiceData($invoice->fresh()),
                    'message' => 'Invoice sent to client successfully.',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVOICE_SEND_FAILED',
                        'message' => 'Failed to send invoice to client.',
                    ],
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_SEND_ERROR',
                    'message' => 'Error occurred while sending invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Approve an invoice.
     */
    public function approve(EventInvoice $invoice): JsonResponse
    {
        try {
            $invoice->approve();

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($invoice->fresh()),
                'message' => 'Invoice approved successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_APPROVAL_FAILED',
                    'message' => 'Failed to approve invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Cancel an invoice.
     */
    public function cancel(Request $request, EventInvoice $invoice): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            if (!$invoice->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVOICE_NOT_CANCELLABLE',
                        'message' => 'Invoice cannot be cancelled in its current state.',
                    ],
                ], 422);
            }

            $invoice->markAsCancelled($request->reason);

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($invoice->fresh()),
                'message' => 'Invoice cancelled successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_CANCELLATION_FAILED',
                    'message' => 'Failed to cancel invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Regenerate an existing invoice.
     */
    public function regenerate(GenerateInvoiceRequest $request, EventInvoice $invoice): JsonResponse
    {
        try {
            $regeneratedInvoice = $this->invoiceService->regenerateInvoice($invoice, $request);

            return response()->json([
                'success' => true,
                'data' => $this->formatInvoiceData($regeneratedInvoice, true),
                'message' => 'Invoice regenerated successfully.',
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_REGENERATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_REGENERATION_ERROR',
                    'message' => 'Failed to regenerate invoice.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get invoice statistics and analytics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'payment_status' => 'sometimes|string|in:pending,paid,partially_paid,overdue,cancelled,refunded',
        ]);

        $filters = $request->only(['date_from', 'date_to', 'payment_status']);
        $statistics = $this->invoiceService->getInvoiceStatistics($filters);

        // Add additional analytics
        $analytics = $this->getInvoiceAnalytics($filters);

        return response()->json([
            'success' => true,
            'data' => [
                'statistics' => $statistics,
                'analytics' => $analytics,
                'filters_applied' => $filters,
            ],
        ]);
    }

    /**
     * Get invoice preview/estimation for an event.
     */
    public function preview(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'include_services' => 'sometimes|boolean',
            'include_inventory' => 'sometimes|boolean',
            'discount_percentage' => 'sometimes|numeric|min:0|max:100',
            'custom_items' => 'sometimes|array',
            'custom_items.*.description' => 'required_with:custom_items|string|max:500',
            'custom_items.*.quantity' => 'required_with:custom_items|integer|min:1',
            'custom_items.*.unit_price' => 'required_with:custom_items|numeric|min:0',
        ]);

        try {
            $options = $request->only([
                'include_services', 'include_inventory', 
                'discount_percentage', 'custom_items'
            ]);

            $estimation = $this->invoiceService->calculateEstimatedTotal($event, $options);

            return response()->json([
                'success' => true,
                'data' => [
                    'event' => [
                        'id' => $event->id,
                        'name' => $event->venue_name,
                        'date' => $event->event_date?->format('Y-m-d'),
                        'client' => $event->client->name,
                        'status' => $event->status,
                    ],
                    'estimation' => $estimation,
                    'formatted_estimation' => [
                        'services' => number_format($estimation['services'], 2),
                        'inventory' => number_format($estimation['inventory'], 2),
                        'custom' => number_format($estimation['custom'], 2),
                        'subtotal' => number_format($estimation['subtotal'], 2),
                        'discount_amount' => number_format($estimation['discount_amount'], 2),
                        'taxable_amount' => number_format($estimation['taxable_amount'], 2),
                        'tax_amount' => number_format($estimation['tax_amount'], 2),
                        'total_amount' => number_format($estimation['total_amount'], 2),
                    ],
                    'options_applied' => $options,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_PREVIEW_FAILED',
                    'message' => 'Failed to generate invoice preview.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get detailed invoice breakdown.
     */
    public function breakdown(EventInvoice $invoice): JsonResponse
    {
        try {
            $breakdown = $this->invoiceService->calculateInvoiceTotals($invoice);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice' => [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->getFormattedInvoiceNumber(),
                        'status' => $invoice->payment_status,
                    ],
                    'breakdown' => $breakdown,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVOICE_BREAKDOWN_FAILED',
                    'message' => 'Failed to calculate invoice breakdown.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Apply filters to invoice query.
     */
    private function applyInvoiceFilters(Builder $query, Request $request): void
    {
        // Payment status filter
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Invoice status filter
        if ($request->filled('invoice_status')) {
            $query->where('invoice_status', $request->invoice_status);
        }

        // Date range filters
        if ($request->filled('date_from')) {
            $query->where('generated_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('generated_at', '<=', $request->date_to);
        }

        // Due date filters
        if ($request->filled('due_from')) {
            $query->where('due_date', '>=', $request->due_from);
        }

        if ($request->filled('due_to')) {
            $query->where('due_date', '<=', $request->due_to);
        }

        // Amount range filters
        if ($request->filled('min_amount')) {
            $query->where('total_amount', '>=', $request->min_amount);
        }

        if ($request->filled('max_amount')) {
            $query->where('total_amount', '<=', $request->max_amount);
        }

        // Client filter
        if ($request->filled('client_id')) {
            $query->whereHas('event', function ($q) use ($request) {
                $q->where('client_id', $request->client_id);
            });
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('event', function ($eventQuery) use ($search) {
                      $eventQuery->where('venue_name', 'like', "%{$search}%")
                                ->orWhereHas('client', function ($clientQuery) use ($search) {
                                    $clientQuery->where('name', 'like', "%{$search}%");
                                });
                  });
            });
        }

        // Overdue filter
        if ($request->filled('overdue') && $request->overdue) {
            $query->overdue();
        }

        // Generated by filter
        if ($request->filled('generated_by')) {
            $query->where('generated_by', $request->generated_by);
        }
    }

    /**
     * Get applied invoice filters.
     */
    private function getAppliedInvoiceFilters(Request $request): array
    {
        $filters = [];
        
        $filterFields = [
            'payment_status', 'invoice_status', 'date_from', 'date_to',
            'due_from', 'due_to', 'min_amount', 'max_amount', 'client_id',
            'search', 'overdue', 'generated_by'
        ];

        foreach ($filterFields as $field) {
            if ($request->filled($field)) {
                $filters[$field] = $request->get($field);
            }
        }

        return $filters;
    }

    /**
     * Get invoices summary.
     */
    private function getInvoicesSummary($invoices): array
    {
        return [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('total_amount'),
            'paid_invoices' => $invoices->where('payment_status', 'paid')->count(),
            'paid_amount' => $invoices->where('payment_status', 'paid')->sum('total_amount'),
            'pending_invoices' => $invoices->where('payment_status', 'pending')->count(),
            'pending_amount' => $invoices->where('payment_status', 'pending')->sum('total_amount'),
            'overdue_invoices' => $invoices->filter->isOverdue()->count(),
            'overdue_amount' => $invoices->filter->isOverdue()->sum('total_amount'),
            'average_amount' => $invoices->count() > 0 ? $invoices->avg('total_amount') : 0,
        ];
    }

    /**
     * Get additional invoice analytics.
     */
    private function getInvoiceAnalytics(array $filters): array
    {
        $query = EventInvoice::query();

        // Apply same filters as statistics
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
            'payment_methods' => $invoices->whereNotNull('payment_method')
                                        ->groupBy('payment_method')
                                        ->map->count(),
            'monthly_revenue' => $invoices->where('payment_status', 'paid')
                                        ->groupBy(function ($invoice) {
                                            return $invoice->payment_date?->format('Y-m') ?? 'unknown';
                                        })
                                        ->map->sum('total_amount'),
            'average_days_to_payment' => $this->calculateAverageDaysToPayment($invoices),
            'top_clients_by_revenue' => $this->getTopClientsByRevenue($invoices),
        ];
    }

    /**
     * Calculate average days to payment.
     */
    private function calculateAverageDaysToPayment($invoices): float
    {
        $paidInvoices = $invoices->where('payment_status', 'paid')
                               ->whereNotNull('payment_date');

        if ($paidInvoices->isEmpty()) {
            return 0;
        }

        $totalDays = $paidInvoices->sum(function ($invoice) {
            return $invoice->generated_at->diffInDays($invoice->payment_date);
        });

        return $totalDays / $paidInvoices->count();
    }

    /**
     * Get top clients by revenue.
     */
    private function getTopClientsByRevenue($invoices, int $limit = 5): array
    {
        return $invoices->where('payment_status', 'paid')
                       ->load('event.client')
                       ->groupBy('event.client.id')
                       ->map(function ($clientInvoices) {
                           $client = $clientInvoices->first()->event->client;
                           return [
                               'client_id' => $client->id,
                               'client_name' => $client->name,
                               'total_revenue' => $clientInvoices->sum('total_amount'),
                               'invoice_count' => $clientInvoices->count(),
                           ];
                       })
                       ->sortByDesc('total_revenue')
                       ->take($limit)
                       ->values()
                       ->toArray();
    }

    /**
     * Get all events that have invoices, sorted by event_date (most recent first).
     * Used by the admin invoices page to list clickable event entries.
     */
    public function eventsWithInvoices(Request $request): JsonResponse
    {
        try {
            $query = Event::query()
                ->whereHas('invoice')
                ->with([
                    'client',
                    'invoice',
                ]);

            // Apply search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    // Event table actually has event_name, venue_name,
                    // venue_address directly (confirmed via Event fillable).
                    $q->where('event_name', 'like', "%{$search}%")
                      ->orWhere('venue_name', 'like', "%{$search}%")
                      ->orWhere('venue_address', 'like', "%{$search}%")
                      ->orWhereHas('client', function ($clientQuery) use ($search) {
                          // Client has firstname/lastname/email (NOT `name`).
                          $clientQuery->where('firstname', 'like', "%{$search}%")
                                      ->orWhere('lastname', 'like', "%{$search}%")
                                      ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Apply status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('payment_status')) {
                $query->whereHas('invoice', function ($q) use ($request) {
                    $q->where('payment_status', $request->payment_status);
                });
            }

            // Sort by event_date descending (most recent first)
            $query->orderBy('event_date', 'desc')
                  ->orderBy('start_time', 'desc');

            // Pagination
            $perPage = min($request->get('per_page', 20), 100);
            $events = $query->paginate($perPage);

            $eventsData = $events->getCollection()->map(function ($event) {
                $invoice = $event->invoice;
                // Defensive: both guest_number and number_of_guests are used
                // across the codebase; fall back gracefully. Note that Event
                // fillable only declares `number_of_guests`.
                $guestCount = $event->number_of_guests ?? $event->guest_number ?? null;

                // Venue columns exist BOTH directly on Event (venue_name,
                // venue_address) AND optionally in a separate events_venue
                // 1:1 relation. Prefer the direct columns; fall back to the
                // eager-loaded relation for extra data safety.
                $venueLoaded = method_exists($event, 'venue') && $event->relationLoaded('venue');
                $venue = $venueLoaded ? $event->venue : null;
                $venueName    = $event->venue_name    ?? optional($venue)->venue_name;
                $venueAddress = $event->venue_address ?? optional($venue)->venue_address;

                // Client display name: concat firstname + lastname.
                $client = $event->client;
                $clientName = $client
                    ? trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''))
                    : 'Unknown Client';

                return [
                    'event_id'      => $event->id,
                    // event_name is the actual primary display key; fall back
                    // to venue name if blank (e.g. legacy rows without title).
                    'event_name'    => $event->event_name ?: ($venueName ?: 'Untitled Event'),
                    'venue_name'    => $venueName,
                    'venue_address' => $venueAddress,
                    'event_date'    => $event->event_date?->format('Y-m-d'),
                    'start_time'    => $event->start_time?->format('H:i:s'),
                    'end_time'      => $event->end_time?->format('H:i:s'),
                    'status'        => $event->status,
                    'status_label'  => ucfirst($event->status),
                    'guest_number'  => $guestCount,
                    'client'        => [
                        'id'        => optional($client)->id,
                        'name'      => $clientName,
                        'firstname' => optional($client)->firstname,
                        'lastname'  => optional($client)->lastname,
                        'email'     => optional($client)->email,
                        'phone'     => optional($client)->phone,
                    ],
                    'invoice'       => [
                        'id'                        => $invoice->id,
                        'invoice_number'            => $invoice->getFormattedInvoiceNumber(),
                        'total_amount'              => (float) ($invoice->total_amount ?? 0),
                        'total_amount_formatted'    => number_format((float) ($invoice->total_amount ?? 0), 2),
                        'payment_status'            => $invoice->payment_status,
                        'payment_status_label'      => $invoice->getStatusLabel(),
                        'payment_status_color'      => $invoice->getStatusColor(),
                        'is_paid'                   => $invoice->isPaid(),
                        'is_overdue'                => $invoice->isOverdue(),
                        'due_date'                  => $invoice->due_date?->format('Y-m-d'),
                        'generated_at'              => $invoice->generated_at?->format('Y-m-d H:i:s'),
                    ],
                ];
            });

            // Calculate summary stats
            $allEvents = $query->get();
            $totalRevenue = $allEvents->sum(fn($e) => $e->invoice?->total_amount ?? 0);
            $paidCount = $allEvents->filter(fn($e) => $e->invoice?->isPaid())->count();
            $pendingCount = $allEvents->filter(fn($e) => $e->invoice && !$e->invoice->isPaid())->count();
            $overdueCount = $allEvents->filter(fn($e) => $e->invoice?->isOverdue())->count();

            return response()->json([
                'success' => true,
                'data' => $eventsData,
                'meta' => [
                    'pagination' => [
                        'current_page' => $events->currentPage(),
                        'total_pages' => $events->lastPage(),
                        'total_items' => $events->total(),
                        'per_page' => $events->perPage(),
                        'from' => $events->firstItem(),
                        'to' => $events->lastItem(),
                    ],
                    'filters_applied' => $request->only(['search', 'status', 'payment_status']),
                    'summary' => [
                        'total_events' => $events->total(),
                        'total_revenue' => $totalRevenue,
                        'total_revenue_formatted' => number_format($totalRevenue, 2),
                        'paid_invoices' => $paidCount,
                        'pending_invoices' => $pendingCount,
                        'overdue_invoices' => $overdueCount,
                        'average_invoice' => $events->total() > 0 ? round($totalRevenue / $events->total(), 2) : 0,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENTS_WITH_INVOICES_FAILED',
                    'message' => 'Failed to load events with invoices.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Format invoice data for API response.
     */
    private function formatInvoiceData(EventInvoice $invoice, bool $includeItems = false): array
    {
        $data = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->getFormattedInvoiceNumber(),
            'event' => [
                'id'   => $invoice->event->id,
                // Event display name: prefer event_name (schema). Falls back to
                // the events_venue table's venue_name only if the relation is
                // already loaded (keeps behavior consistent without extra SQL).
                'name' => $invoice->event->event_name
                    ?? (method_exists($invoice->event, 'venue') && $invoice->event->relationLoaded('venue')
                        ? optional($invoice->event->venue)->venue_name
                        : null),
                'event_name' => $invoice->event->event_name,
                'date'       => $invoice->event->event_date?->format('Y-m-d'),
                'event_date' => $invoice->event->event_date?->format('Y-m-d'),
                'status'     => $invoice->event->status,
                'client'     => [
                    'id'        => $invoice->event->client->id,
                    // Client table uses firstname + lastname (NOT `name`).
                    'name'      => trim(($invoice->event->client->firstname ?? '') . ' ' . ($invoice->event->client->lastname ?? '')),
                    'firstname' => $invoice->event->client->firstname,
                    'lastname'  => $invoice->event->client->lastname,
                    'email'     => $invoice->event->client->email,
                    'phone'     => $invoice->event->client->phone,
                ],
            ],
            'amounts' => [
                'subtotal' => number_format($invoice->subtotal, 2),
                'subtotal_raw' => $invoice->subtotal,
                'discount_percentage' => $invoice->discount_percentage,
                'discount_amount' => number_format($invoice->discount_amount, 2),
                'discount_amount_raw' => $invoice->discount_amount,
                'tax_rate' => number_format($invoice->tax_rate * 100, 2) . '%',
                'tax_rate_raw' => $invoice->tax_rate,
                'tax_amount' => number_format($invoice->tax_amount, 2),
                'tax_amount_raw' => $invoice->tax_amount,
                'total_amount' => number_format($invoice->total_amount, 2),
                'total_amount_raw' => $invoice->total_amount,
                'amount_due' => number_format($invoice->getAmountDue(), 2),
                'amount_due_raw' => $invoice->getAmountDue(),
            ],
            'status' => [
                'payment_status' => $invoice->payment_status,
                'payment_status_label' => $invoice->getStatusLabel(),
                'payment_status_color' => $invoice->getStatusColor(),
                'invoice_status' => $invoice->invoice_status,
                'is_paid' => $invoice->isPaid(),
                'is_overdue' => $invoice->isOverdue(),
                'is_viewed' => $invoice->isViewed(),
                'is_approved' => $invoice->isApproved(),
            ],
            'dates' => [
                'generated_at' => $invoice->generated_at?->format('Y-m-d H:i:s'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'payment_date' => $invoice->payment_date?->format('Y-m-d'),
                'sent_at' => $invoice->sent_at?->format('Y-m-d H:i:s'),
                'viewed_at' => $invoice->viewed_at?->format('Y-m-d H:i:s'),
                'approved_at' => $invoice->approved_at?->format('Y-m-d H:i:s'),
                'days_until_due' => $invoice->getDaysUntilDue(),
                'days_overdue' => $invoice->getDaysOverdue(),
            ],
            'payment' => [
                'payment_method' => $invoice->payment_method,
                'currency' => $invoice->currency,
            ],
            'metadata' => [
                'notes' => $invoice->notes,
                'terms_and_conditions' => $invoice->terms_and_conditions,
                'generated_by' => $invoice->generatedBy ? [
                    'id' => $invoice->generatedBy->id,
                    'name' => $invoice->generatedBy->name,
                ] : null,
                'approved_by' => $invoice->approvedBy ? [
                    'id' => $invoice->approvedBy->id,
                    'name' => $invoice->approvedBy->name,
                ] : null,
            ],
            'capabilities' => [
                'can_be_edited' => $invoice->canBeEdited(),
                'can_be_cancelled' => $invoice->canBeCancelled(),
                'can_be_refunded' => $invoice->canBeRefunded(),
            ],
        ];

        if ($includeItems && $invoice->relationLoaded('items')) {
            $data['items'] = $invoice->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->item_type,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => number_format($item->unit_price, 2),
                    'unit_price_raw' => $item->unit_price,
                    'total_price' => number_format($item->total_price, 2),
                    'total_price_raw' => $item->total_price,
                    'discount_percentage' => $item->discount_percentage,
                    'discount_amount' => number_format($item->discount_amount, 2),
                    'tax_amount' => number_format($item->tax_amount, 2),
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                    'item_details' => $item->getItemDetails(),
                ];
            });

            $data['items_summary'] = [
                'total_items' => $invoice->items->count(),
                'service_items' => $invoice->items->where('item_type', 'service')->count(),
                'inventory_items' => $invoice->items->where('item_type', 'inventory')->count(),
                'custom_items' => $invoice->items->where('item_type', 'custom')->count(),
                'service_total' => number_format($invoice->getServiceTotal(), 2),
                'inventory_total' => number_format($invoice->getInventoryTotal(), 2),
                'custom_total' => number_format($invoice->getCustomTotal(), 2),
            ];
        }

        return $data;
    }
}