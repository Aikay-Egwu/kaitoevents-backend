<?php

namespace App\Services;

use App\Events\ClientQualifiedForConsultation; // Triggers Com-A1
use App\Events\ClientRoutedToSelfServe;      // Triggers Com-B1
use App\Events\ClientRoutedToSelfService;
use App\Models\Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class ConsultationRoutingService
{
    /**
     * Process the form submission payload from Next.js.
     * 
     * @param int $guest_count
     * @param float $budget
     * @return string
     */
    public function processFormSubmission(int $guest_count, float $budget): string
    {

        // 2. Evaluate conditions A-E
        $isOptionA = $this->evaluatesToOptionA($guest_count, $budget);

        // 3. Route the client
        if ($isOptionA) {
            //return route to option_a
            return 'option_a';
        } else {
            //return route to option_b
            return 'option_b';
        }

        //return $client;
    }

    /**
     * Checks if the client meets any of the conditions A - E.
     * Note: Adjusted boundary overlaps (e.g., 50-79, 80-149) to prevent logic conflicts.
     */
    private function evaluatesToOptionA(int $guests, float $budget): bool
    {
        // Condition A: Below 50 guests -> Min £700
        if ($guests < 50) {
            return $budget >= 700;
        }

        // Condition B: 50 to 79 guests -> Min £1,500
        if ($guests >= 50 && $guests < 80) {
            return $budget >= 1500;
        }

        // Condition C: 80 to 149 guests -> Min £2,500
        if ($guests >= 80 && $guests < 150) {
            return $budget >= 2500;
        }

        // Condition D: 150 to 300 guests -> Min £3,500
        if ($guests >= 150 && $guests <= 300) {
            return $budget >= 3500;
        }

        // Condition E: Above 300 guests -> Min £5,000
        if ($guests > 300) {
            return $budget >= 5000;
        }

        return false;
    }

    /**
     * Handle Option A: Full Nurture Sequence
     */
    private function routeToOptionA(Client $client): void
    {
        $client->update(['route_status' => 'option_a']);

        Log::info("Client {$client->id} qualified for Option A (Consultation).");

        // Fire an event to trigger Com-A1 (Booking Link)
        // A listener for this event will send the email and start the Com-AA reminder jobs.
        Event::dispatch(new ClientQualifiedForConsultation($client));
    }

    /**
     * Handle Option B: Client does not meet minimums
     */
    private function routeToOptionB(Client $client): void
    {
        $client->update(['route_status' => 'option_b']);

        Log::info("Client {$client->id} routed to Option B (Self-Serve).");

        // Fire an event to trigger Com-B1 (Self-Serve Content)
        Event::dispatch(new ClientRoutedToSelfService($client));
    }
}
