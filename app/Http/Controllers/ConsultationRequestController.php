<?php

namespace App\Http\Controllers;


use App\Events\ClientQualifiedForConsultation;
use App\Events\ClientRoutedToSelfService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEventRequest;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventType;
use App\Models\EventVenue;
use App\Services\ConsultationRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;



class ConsultationRequestController extends Controller
{
    /**
     * Submit a consultation request.
     */
    public function requestConsultation(CreateEventRequest $request, ConsultationRoutingService $consultationRoutingService): JsonResponse
    {

        try {
            // Create or find client
            $client = Client::firstOrCreate(
                ['email' => $request->email],
                [
                    'firstname' => $request->firstName,
                    'lastname' => $request->lastName,
                    'phone' => $request->phone,
                ]
            );


            // Store consultation request (you might want to create a ConsultationRequest model)
            $event = Event::create(array_merge(["client_id" => $client->id], $request->eventDetails()));

            $venue = EventVenue::create(array_merge(["event_id" => $event->id], $request->getVenueData()));

            // For now, we'll log this or send an email to admin
            // In a full implementation, you'd store this in a consultation_requests table
            $eventType = EventType::find($event->event_type_id);
            // Send notification email to admin
            ///$this->sendConsultationRequestEmail($client, $consultationData);

            $event_client = [
                "id" => $client->id,
                "firstName" => $request->firstName,
                "lastName" => $request->lastName,
                "phone" => $request->phone,
                "email" => $request->email,
                "eventDate" => $request->eventDate,
                "guestCount" => $request->guestCount,
                "startTime" => $request->startTime ?? "",
                "eventType" => $eventType->title,
                "hasVenue" => $request->hasVenue,
                "venueName" => $request->venueName ?? '',
                "venueAddress" => $request->venueAddress ?? '',
                "colorScheme" => $request->colorScheme ?? "",
                "specialInstructions" => $request->specialInstructions ?? "",
                "budget" => $request->budget,
            ];

            $response_option = $consultationRoutingService->processFormSubmission($request->guestCount, $request->budget);

            switch ($response_option) {
                case 'option_a':
                    //Event::dispatch(new ClientQualifiedForConsultation($client));
                    break;
                case 'option_b':
                    //Event::dispatch(new ClientRoutedToSelfService($client));
                    break;

                default:
                    # code...
                    break;
            }
            //event(new ClientEventCreated($event_client));
            //ClientEventCreated::dispatch($client);

            return response()->json([
                "option" => $response_option,
                'success' => true,
                'message' => 'Consultation request submitted successfully! We will contact you within 24 hours.',
                'next_steps' => [
                    'Our event planning team will review your request',
                    'We will contact you within 24 hours using your preferred method',
                    'We will schedule a consultation at your convenience',
                    'During the consultation, we will discuss your vision and provide a detailed quote',
                ],
                'contact_info' => [
                    'phone' => config('app.contact_phone', '01915659575'),
                    'email' => config('app.contact_email', 'chioma@kaitoevents.co.ukpany.com'),
                    'business_hours' => 'Monday - Friday: 9:00 AM - 6:00 PM',
                ],
            ], 201);
        } catch (\Exception $e) {
            // Log the exception details
            Log::error('Consultation request submission failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONSULTATION_REQUEST_FAILED',
                    'message' => 'Failed to submit consultation request. Please try again.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }
}
