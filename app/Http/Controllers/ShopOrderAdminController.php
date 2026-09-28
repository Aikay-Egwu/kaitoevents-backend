<?php

namespace App\Http\Controllers;

// ShopOrderAdminController — admin order listing/filter, status transitions (with allowed checks), notes PATCH.

use App\Http\Resources\Shop\ShopOrderResource;
use App\Models\ShopOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ShopOrderAdminController extends Controller
{
    // Allowed transitions; any non-cancelled may become cancelled.
    private const TRANSITIONS = [
        ShopOrder::STATUS_PENDING          => [ShopOrder::STATUS_PROCESSING, ShopOrder::STATUS_CANCELLED],
        ShopOrder::STATUS_AWAITING_PAYMENT => [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PROCESSING, ShopOrder::STATUS_CANCELLED],
        ShopOrder::STATUS_PAID             => [ShopOrder::STATUS_PROCESSING, ShopOrder::STATUS_COMPLETED, ShopOrder::STATUS_CANCELLED],
        ShopOrder::STATUS_PROCESSING       => [ShopOrder::STATUS_COMPLETED, ShopOrder::STATUS_CANCELLED],
        ShopOrder::STATUS_COMPLETED        => [ShopOrder::STATUS_CANCELLED],
        ShopOrder::STATUS_CANCELLED        => [],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $builder = ShopOrder::with(['items', 'paymentTransactions']);

        if ($request->filled('status')) {
            $builder->whereIn('status', (array)$request->input('status'));
        }
        if ($request->filled('date_from')) {
            $builder->whereDate('created_at', '>=', (string)$request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $builder->whereDate('created_at', '<=', (string)$request->input('date_to'));
        }
        if ($request->filled('search_by')) {
            $kw = '%' . trim((string)$request->input('search_by')) . '%';
            $builder->where(function ($q) use ($kw) {
                $q->where('order_ref', 'like', $kw)
                  ->orWhere('customer_full_name', 'like', $kw)
                  ->orWhere('customer_email', 'like', $kw);
            });
        }

        return ShopOrderResource::collection($builder->latest('id')->paginate($perPage))
            ->additional(['success' => true]);
    }

    public function show(ShopOrder $order): JsonResponse
    {
        return (new ShopOrderResource($order->load(['items', 'paymentTransactions'])))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * PATCH /api/admin/shop/orders/{order}/status — with allowed transition validation + optional cancel_reason.
     */
    public function updateStatus(Request $request, ShopOrder $order): JsonResponse
    {
        $valid = $request->validate([
            'status'         => ['required', 'in:pending,awaiting_payment,paid,processing,completed,cancelled'],
            'cancel_reason'  => ['nullable', 'string', 'max:255'],
        ]);

        $newStatus = $valid['status'];
        $allowed = self::TRANSITIONS[$order->status] ?? [];
        // Any non-cancelled → cancelled always allowed.
        if ($order->status !== ShopOrder::STATUS_CANCELLED && $newStatus === ShopOrder::STATUS_CANCELLED) {
            // ok
        } elseif (!in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf('Cannot transition from %s → %s. Allowed: %s.',
                    $order->status,
                    $newStatus,
                    implode(', ', count($allowed) ? $allowed : ['<none>'])
                ),
            ])->errorBag('default');
        }

        $append = '';
        if (isset($valid['cancel_reason']) && trim((string)$valid['cancel_reason']) !== '') {
            $append = "\n[Cancel reason] " . $valid['cancel_reason'];
        }

        $order->update([
            'status' => $newStatus,
            'notes'  => trim(($order->notes ?? '') . $append) ?: null,
        ]);

        return (new ShopOrderResource($order->load(['items', 'paymentTransactions'])))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * PATCH /api/admin/shop/orders/{order}/notes — replace (or append) admin notes.
     */
    public function patchNotes(Request $request, ShopOrder $order): JsonResponse
    {
        $valid = $request->validate([
            'notes'   => ['required', 'string'],
            'replace' => ['nullable', 'boolean'],
        ]);

        if (empty($valid['replace']) || !$valid['replace']) {
            $existing = trim((string)$order->notes);
            $combined = $existing === '' ? $valid['notes'] : $existing . "\n\n" . $valid['notes'];
            $order->update(['notes' => $combined]);
        } else {
            $order->update(['notes' => $valid['notes']]);
        }

        return response()->json(['success' => true, 'notes' => $order->notes]);
    }
}
