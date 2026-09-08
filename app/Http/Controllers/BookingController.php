<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\User;
use App\Models\Prop;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Requests\CustomerBookingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $query = Booking::with(['customer', 'bookingItems.prop']);

        // Search functionality
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('booking_number', 'like', "%{$searchTerm}%")
                  ->orWhereHas('customer', function ($customerQuery) use ($searchTerm) {
                      $customerQuery->where('name', 'like', "%{$searchTerm}%")
                                   ->orWhere('email', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by date range
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('event_date', [
                $request->date_from, 
                $request->date_to
            ]);
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bookings = $query->paginate($request->get('per_page', 15));

        // Add items_count to each booking
        $bookings->getCollection()->transform(function ($booking) {
            $booking->items_count = $booking->bookingItems->count();
            return $booking;
        });

        return response()->json([
            'bookings' => $bookings,
            'meta' => [
                'total' => $bookings->total(),
                'per_page' => $bookings->perPage(),
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage()
            ]
        ]);
    }

    public function store(StoreBookingRequest $request)
    {
        $validatedData = $request->validated();

        try {
            DB::beginTransaction();

            // Calculate days between event and return date
            $eventDate = Carbon::parse($validatedData['event_date']);
            $returnDate = Carbon::parse($validatedData['return_date']);
            $days = max(1, $eventDate->diffInDays($returnDate) + 1);

            // Create booking
            $booking = Booking::create([
                'customer_id' => $validatedData['customer_id'],
                'event_date' => $validatedData['event_date'],
                'return_date' => $validatedData['return_date'],
                'setup_time' => $validatedData['setup_time'] ?? null,
                'delivery_address' => $validatedData['delivery_address'],
                'delivery_city' => $validatedData['delivery_city'],
                'delivery_postcode' => $validatedData['delivery_postcode'],
                'contact_phone' => $validatedData['contact_phone'],
                'event_type' => $validatedData['event_type'] ?? null,
                'special_instructions' => $validatedData['special_instructions'] ?? null,
                'delivery_fee' => $validatedData['delivery_fee'] ?? 0,
                'setup_fee' => $validatedData['setup_fee'] ?? 0,
                'subtotal' => 0, // Will be calculated
                'tax_amount' => 0, // Will be calculated
                'total_amount' => 0, // Will be calculated
                'status' => 'pending',
                'payment_status' => 'pending'
            ]);

            // Generate booking number
            $booking->booking_number = $booking->generateBookingNumber();
            $booking->save();

            // Create booking items
            $subtotal = 0;
            foreach ($validatedData['items'] as $itemData) {
                $prop = Prop::findOrFail($itemData['prop_id']);
                
                // Check availability
                if (!$prop->isAvailable($itemData['quantity'], $validatedData['event_date'], $validatedData['return_date'])) {
                    throw new \Exception("Prop '{$prop->name}' is not available for the requested quantity and dates.");
                }

                $totalPrice = $prop->price_per_day * $itemData['quantity'] * $days;

                BookingItem::create([
                    'booking_id' => $booking->id,
                    'prop_id' => $prop->id,
                    'quantity' => $itemData['quantity'],
                    'price_per_day' => $prop->price_per_day,
                    'days' => $days,
                    'total_price' => $totalPrice,
                    'notes' => null
                ]);

                $subtotal += $totalPrice;
            }

            // Calculate totals
            $taxRate = 0.2;
            $taxAmount = $subtotal * $taxRate;
            $totalAmount = $subtotal + $taxAmount + $booking->delivery_fee;

            $booking->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount
            ]);

            DB::commit();

            $booking->load(['customer', 'bookingItems.prop']);

            return response()->json([
                'message' => 'Booking request submitted successfully. We will contact you shortly to confirm.',
                'booking' => $booking
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to create booking request: ' . $e->getMessage()
            ], 422);
        }
    }

    public function show(Booking $booking)
    {
        $booking->load(['customer', 'bookingItems.prop.images']);
        
        return response()->json([
            'booking' => $booking
        ]);
    }

    public function update(UpdateBookingRequest $request, Booking $booking)
    {
        $validatedData = $request->validated();

        if ($booking->status === 'completed' || $booking->status === 'cancelled') {
            return response()->json([
                'message' => 'Cannot update a completed or cancelled booking'
            ], 422);
        }

        $booking->update($validatedData);
        $booking->load(['customer', 'bookingItems.prop']);

        return response()->json([
            'message' => 'Booking updated successfully',
            'booking' => $booking
        ]);
    }

    public function destroy(Booking $booking)
    {
        if ($booking->status === 'delivered' || $booking->status === 'completed') {
            return response()->json([
                'message' => 'Cannot delete a delivered or completed booking'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Restore prop quantities
            foreach ($booking->bookingItems as $item) {
                $prop = $item->prop;
                $prop->quantity_available += $item->quantity;
                $prop->save();
            }

            $booking->delete();

            DB::commit();

            return response()->json([
                'message' => 'Booking deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to delete booking: ' . $e->getMessage()
            ], 422);
        }
    }

    public function updateStatus(Request $request, Booking $booking)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,delivered,completed,cancelled'
        ]);

        $newStatus = $request->status;
        $oldStatus = $booking->status;

        // Validate status transitions
        $validTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['delivered', 'cancelled'],
            'delivered' => ['completed'],
            'completed' => [],
            'cancelled' => []
        ];

        if (!in_array($newStatus, $validTransitions[$oldStatus] ?? [])) {
            return response()->json([
                'message' => "Cannot change status from {$oldStatus} to {$newStatus}"
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Handle status-specific logic
            switch ($newStatus) {
                case 'confirmed':
                    $booking->confirmed_at = now();
                    break;
                
                case 'delivered':
                    $booking->delivered_at = now();
                    break;
                
                case 'completed':
                    $booking->returned_at = now();
                    // Restore prop quantities
                    foreach ($booking->bookingItems as $item) {
                        $prop = $item->prop;
                        $prop->quantity_available += $item->quantity;
                        $prop->save();
                    }
                    break;
                
                case 'cancelled':
                    // Restore prop quantities if not already delivered
                    if ($oldStatus !== 'delivered') {
                        foreach ($booking->bookingItems as $item) {
                            $prop = $item->prop;
                            $prop->quantity_available += $item->quantity;
                            $prop->save();
                        }
                    }
                    break;
            }

            $booking->status = $newStatus;
            $booking->save();

            DB::commit();

            return response()->json([
                'message' => 'Booking status updated successfully',
                'booking' => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to update booking status: ' . $e->getMessage()
            ], 422);
        }
    }

    public function confirm(Request $request, Booking $booking)
    {
        if ($booking->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending bookings can be confirmed'
            ], 422);
        }

        $booking->update([
            'status' => 'confirmed',
            'confirmed_at' => now()
        ]);

        return response()->json([
            'message' => 'Booking confirmed successfully',
            'booking' => $booking
        ]);
    }

    public function complete(Request $request, Booking $booking)
    {
        if ($booking->status !== 'delivered') {
            return response()->json([
                'message' => 'Only delivered bookings can be completed'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Restore prop quantities
            foreach ($booking->bookingItems as $item) {
                $prop = $item->prop;
                $prop->quantity_available += $item->quantity;
                $prop->save();
            }

            $booking->update([
                'status' => 'completed',
                'returned_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Booking completed successfully',
                'booking' => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to complete booking: ' . $e->getMessage()
            ], 422);
        }
    }

    public function cancel(Request $request, Booking $booking)
    {
        if (!$booking->canBeCancelled()) {
            return response()->json([
                'message' => 'This booking cannot be cancelled'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Restore prop quantities if not delivered
            if ($booking->status !== 'delivered') {
                foreach ($booking->bookingItems as $item) {
                    $prop = $item->prop;
                    $prop->quantity_available += $item->quantity;
                    $prop->save();
                }
            }

            $booking->update([
                'status' => 'cancelled'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Booking cancelled successfully',
                'booking' => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to cancel booking: ' . $e->getMessage()
            ], 422);
        }
    }

    public function calendar(Request $request, $year, $month)
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->endOfDay();

        $bookings = Booking::with(['customer', 'bookingItems'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('event_date', [$startDate, $endDate])
                      ->orWhereBetween('return_date', [$startDate, $endDate])
                      ->orWhere(function ($q) use ($startDate, $endDate) {
                          $q->where('event_date', '<=', $startDate)
                            ->where('return_date', '>=', $endDate);
                      });
            })
            ->where('status', '!=', 'cancelled')
            ->get();

        $calendar = [];
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $dateBookings = $bookings->filter(function ($booking) use ($current) {
                $eventDate = Carbon::parse($booking->event_date);
                $returnDate = Carbon::parse($booking->return_date);
                return $current->between($eventDate, $returnDate);
            });

            $calendar[] = [
                'date' => $current->format('Y-m-d'),
                'bookings' => $dateBookings->values(),
                'booking_count' => $dateBookings->count()
            ];

            $current->addDay();
        }

        return response()->json([
            'calendar' => $calendar
        ]);
    }

    public function byDate($date)
    {
        $bookings = Booking::with(['customer', 'bookingItems.prop'])
            ->whereDate('event_date', '<=', $date)
            ->whereDate('return_date', '>=', $date)
            ->where('status', '!=', 'cancelled')
            ->get();

        return response()->json([
            'bookings' => $bookings
        ]);
    }

    public function customerBooking(CustomerBookingRequest $request)
    {
        $validatedData = $request->validated();

        try {
            DB::beginTransaction();

            // Find or create customer
            $customer = User::where('email', $validatedData['customer_email'])->first();
            
            if (!$customer) {
                $customer = User::create([
                    'name' => $validatedData['customer_name'],
                    'email' => $validatedData['customer_email'],
                    'phone' => $validatedData['customer_phone'],
                    'role' => 'customer',
                    'password' => bcrypt(str()->random(12)) // Temporary password
                ]);
            }

            // Calculate days
            $eventDate = Carbon::parse($validatedData['event_date']);
            $returnDate = Carbon::parse($validatedData['return_date']);
            $days = max(1, $eventDate->diffInDays($returnDate) + 1);

            // Create booking
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'event_date' => $validatedData['event_date'],
                'return_date' => $validatedData['return_date'],
                'setup_time' => $validatedData['setup_time'] ?? null,
                'delivery_address' => $validatedData['delivery_address'],
                'delivery_city' => $validatedData['delivery_city'],
                'delivery_postcode' => $validatedData['delivery_postcode'],
                'contact_phone' => $validatedData['customer_phone'],
                'event_type' => $validatedData['event_type'] ?? null,
                'special_instructions' => $validatedData['special_instructions'] ?? null,
                'delivery_fee' => 25.00, // Standard delivery fee
                'setup_fee' => 0,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'status' => 'pending',
                'payment_status' => 'pending'
            ]);

            $booking->booking_number = $booking->generateBookingNumber();
            $booking->save();

            // Create booking items
            $subtotal = 0;
            foreach ($validatedData['items'] as $itemData) {
                $prop = Prop::findOrFail($itemData['prop_id']);
                
                // Check availability
                if (!$prop->isAvailable($itemData['quantity'], $validatedData['event_date'], $validatedData['return_date'])) {
                    throw new \Exception("Prop '{$prop->name}' is not available for the requested quantity and dates.");
                }

                $totalPrice = $prop->price_per_day * $itemData['quantity'] * $days;

                BookingItem::create([
                    'booking_id' => $booking->id,
                    'prop_id' => $prop->id,
                    'quantity' => $itemData['quantity'],
                    'price_per_day' => $prop->price_per_day,
                    'days' => $days,
                    'total_price' => $totalPrice,
                    'notes' => null
                ]);

                $subtotal += $totalPrice;
            }

            // Calculate totals
            $taxRate = 0.2;
            $taxAmount = $subtotal * $taxRate;
            $totalAmount = $subtotal + $taxAmount + $booking->delivery_fee;

            $booking->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount
            ]);

            DB::commit();

            $booking->load(['customer', 'bookingItems.prop']);

            return response()->json([
                'message' => 'Booking request submitted successfully. We will contact you shortly to confirm.',
                'booking' => $booking
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to create booking request: ' . $e->getMessage()
            ], 422);
        }
    }
}