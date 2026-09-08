<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Client;
use App\Models\Inventory;
use App\Models\EventInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function getStats(): JsonResponse
    {
        try {
            // Get current month for revenue calculation
            $currentMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            // Count active events (not cancelled or completed)
            $activeEvents = Event::whereNotIn('status', ['cancelled', 'completed'])->count();

            // Count total clients
            $totalClients = Client::count();

            // Count total inventory items
            $totalInventory = Inventory::count();

            // Calculate monthly revenue from paid invoices
            $monthlyRevenue = EventInvoice::where('payment_status', 'paid')
                ->whereBetween('created_at', [$currentMonth, $endOfMonth])
                ->sum('total_amount');

            // Count pending consultations (events with status 'pending' or 'consultation_requested')
            $pendingConsultations = Event::whereIn('status', ['pending', 'consultation_requested'])->count();

            // Count recent events (this month)
            $recentEvents = Event::whereBetween('created_at', [$currentMonth, $endOfMonth])->count();

            return response()->json([
                'eventCount' => $activeEvents,
                'consultationCount' => $pendingConsultations,
                'messageCount' => 0, // Placeholder - implement when message system exists
                'clientCount' => $totalClients,
                'inventoryCount' => $totalInventory,
                'monthlyRevenue' => $monthlyRevenue,
                'recentEvents' => $recentEvents,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed dashboard analytics
     */
    public function getAnalytics(): JsonResponse
    {
        try {
            // Get events by status
            $eventsByStatus = Event::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Get monthly revenue for the last 6 months
            $monthlyRevenue = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = Carbon::now()->subMonths($i);
                $startOfMonth = $month->copy()->startOfMonth();
                $endOfMonth = $month->copy()->endOfMonth();
                
                $revenue = EventInvoice::where('status', 'paid')
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->sum('total_amount');

                $monthlyRevenue[] = [
                    'month' => $month->format('M Y'),
                    'revenue' => $revenue
                ];
            }

            // Get recent events
            $recentEvents = Event::with(['client', 'eventType'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($event) {
                    $clientName = 'Unknown';
                    if ($event->client) {
                        $clientName = trim($event->client->firstname . ' ' . $event->client->lastname);
                    }
                    
                    return [
                        'id' => $event->id,
                        'title' => $event->title ?? 'Event #' . $event->id,
                        'client_name' => $clientName,
                        'event_type' => $event->eventType->name ?? 'Unknown',
                        'status' => $event->status,
                        'event_date' => $event->event_date,
                        'created_at' => $event->created_at
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'eventsByStatus' => $eventsByStatus,
                    'monthlyRevenue' => $monthlyRevenue,
                    'recentEvents' => $recentEvents
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}