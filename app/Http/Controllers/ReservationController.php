<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $q = Reservation::query()
            ->when($request->filled('prop_id'), fn($qq) => $qq->where('prop_id', $request->integer('prop_id')))
            ->when($request->filled('customer_id'), fn($qq) => $qq->where('customer_id', $request->integer('customer_id')));

        return response()->json($q->latest()->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreReservationRequest $request)
    {
        $res = Reservation::create($request->validated());
        return response()->json($res, 201);
    }

    public function show(Reservation $reservation)
    {
        return response()->json($reservation);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation)
    {
        $reservation->update($request->validated());
        return response()->json($reservation);
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();
        return response()->noContent();
    }
}
