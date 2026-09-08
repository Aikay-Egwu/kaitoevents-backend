<?php

namespace App\Http\Controllers;

use App\Events\ClientEventCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventType;
use App\Models\EventVenue;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ClientEventController extends Controller
{
    /**
     * Create a new event request from a client.
     */
    public function bookConsultation(CreateEventRequest $request): JsonResponse
    {
        //return 
        try {
            DB::beginTransaction();

            $validatedData = $request->validated();
            //first check if the email exists
            $client = Client::firstOrCreate(
                ['email' => $validatedData['email']],
                [
                    'firstname' => $validatedData['firstName'],
                    'lastname' => $validatedData['lastName'],
                    'phone' => $validatedData['phone'],
                ]
            );

            return response()->json($client);
            // Handle client creation or retrieval
            $client = $this->handleClientData($validatedData);
            $validatedData['client_id'] = $client->id;

            // Remove client creation fields from event data
            unset($validatedData['client_name'], $validatedData['client_email'], $validatedData['client_phone']);

            // Set default status for client-created events
            $validatedData['status'] = 'pending';

            // Create the event
            $event = Event::create($validatedData);
            $event->load(['client', 'eventType']);

            DB::commit();

            // Send confirmation email (if configured)
            $this->sendEventConfirmationEmail($event);

            return response()->json([
                'success' => true,
                'data' => new EventResource($event),
                'message' => 'Event request submitted successfully! We will contact you within 24 hours to confirm details.',
                'next_steps' => [
                    'Our team will review your event request',
                    'We will contact you within 24 hours to discuss details',
                    'You will receive a detailed quote and timeline',
                    'Once confirmed, we will begin planning your event',
                ],
                'contact_info' => [
                    'phone' => config('app.contact_phone', '(555) 123-4567'),
                    'email' => config('app.contact_email', 'events@company.com'),
                    'business_hours' => 'Monday - Friday: 9:00 AM - 6:00 PM',
                ],
                'reference_number' => $this->generateReferenceNumber($event),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EVENT_CREATION_FAILED',
                    'message' => 'Failed to submit event request. Please try again.',
                    'details' => config('app.debug') ? $e->getMessage() : null,
                ],
            ], 500);
        }
    }

    /**
     * Get event status for client (with token or email verification).
     */
    public function show(Request $request, Event $event): JsonResponse
    {
        // Validate client access
        if (!$this->canClientAccessEvent($request, $event)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ACCESS_DENIED',
                    'message' => 'You do not have permission to view this event.',
                ],
            ], 403);
        }

        $event->load(['client', 'eventType', 'services', 'inventories', 'invoice']);

        // Create a limited resource for client view
        $eventData = [
            'id' => $event->id,
            'reference_number' => $this->generateReferenceNumber($event),
            'event_date' => $event->event_date?->format('Y-m-d'),
            'start_time' => $event->start_time?->format('H:i'),
            'end_time' => $event->end_time?->format('H:i'),
            'venue_name' => $event->venue_name,
            'venue_address' => $event->venue_address,
            'guest_number' => $event->guest_number,
            'budget' => $event->budget ? number_format($event->budget, 2) : null,
            'special_instructions' => $event->special_instructions,
            'status' => $event->status,
            'status_label' => $this->getStatusLabel($event->status),
            'status_description' => $this->getStatusDescription($event->status),
            'created_at' => $event->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $event->updated_at?->format('Y-m-d H:i:s'),

            'event_type' => [
                'name' => $event->eventType->event_type_name,
                'description' => $event->eventType->event_type_description,
            ],

            'client' => [
                'name' => $event->client->name,
                'email' => $event->client->email,
                'phone' => $event->client->phone,
            ],

            'services_count' => $event->services->count(),
            'inventories_count' => $event->inventories->count(),

            'invoice' => $event->invoice ? [
                'invoice_number' => $event->invoice->invoice_number,
                'total_amount' => number_format($event->invoice->total_amount, 2),
                'payment_status' => $event->invoice->payment_status,
                'due_date' => $event->invoice->due_date?->format('Y-m-d'),
                'is_paid' => $event->invoice->isPaid(),
                'is_overdue' => $event->invoice->isOverdue(),
            ] : null,
        ];

        return response()->json([
            'success' => true,
            'data' => $eventData,
            'timeline' => $this->getEventTimeline($event),
            'next_steps' => $this->getNextSteps($event),
            'contact_info' => [
                'phone' => config('app.contact_phone', '(555) 123-4567'),
                'email' => config('app.contact_email', 'events@company.com'),
                'business_hours' => 'Monday - Friday: 9:00 AM - 6:00 PM',
            ],
        ]);
    }

    /**
     * Get available event types for client selection.
     */
    public function eventTypes(): JsonResponse
    {
        $eventTypes = EventType::active()->get();

        return response()->json([
            'success' => true,
            'data' => $eventTypes->map(function ($eventType) {
                return [
                    'id' => $eventType->id,
                    'name' => $eventType->event_type_name,
                    'description' => $eventType->event_type_description,
                    'image' => $eventType->event_type_image,
                    'typical_duration' => $eventType->typical_duration ?? '4-6 hours',
                    'starting_price' => $eventType->starting_price ? number_format($eventType->starting_price, 2) : null,
                ];
            }),
        ]);
    }

    /**
     * Submit a consultation request.
     */
    public function requestConsultation(CreateEventRequest $request, EmailService $emailService): JsonResponse
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
          
            $client = [
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
                "venueAddress" => $request->venueAddreee ?? '',
                "colorScheme" => $request->colorScheme ?? "",
                "specialInstructions" => $request->specialInstructions ?? "",
                "budget" => $request->budget,
            ];

            event(new ClientEventCreated($client));
            //ClientEventCreated::dispatch($client);

            return response()->json([
                'success' => true,
                'message' => 'Consultation request submitted successfully! We will contact you within 24 hours.',
                'next_steps' => [
                    'Our event planning team will review your request',
                    'We will contact you within 24 hours using your preferred method',
                    'We will schedule a consultation at your convenience',
                    'During the consultation, we will discuss your vision and provide a detailed quote',
                ],
                'contact_info' => [
                    'phone' => config('app.contact_phone', '(555) 123-4567'),
                    'email' => config('app.contact_email', 'events@company.com'),
                    'business_hours' => 'Monday - Friday: 9:00 AM - 6:00 PM',
                ],
            ], 201);
        } catch (\Exception $e) {
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

    /**
     * Handle client data creation or retrieval.
     */
    private function handleClientData(array $data): Client
    {
        if (isset($data['client_id'])) {
            return Client::findOrFail($data['client_id']);
        }

        // Create new client or find existing by email
        return Client::firstOrCreate(
            ['email' => $data['client_email']],
            [
                'name' => $data['client_name'],
                'phone' => $data['client_phone'],
            ]
        );
    }

    /**
     * Check if client can access the event.
     */
    private function canClientAccessEvent(Request $request, Event $event): bool
    {
        // Check if client email matches
        if ($request->filled('email') && $event->client->email === $request->email) {
            return true;
        }

        // Check if access token is provided (you might implement this)
        if ($request->filled('token')) {
            // Implement token-based access if needed
            // return $this->validateEventAccessToken($request->token, $event);
        }

        // For now, allow access if client_id matches (in a real app, you'd need proper authentication)
        if ($request->filled('client_id') && $event->client_id == $request->client_id) {
            return true;
        }

        return false;
    }

    /**
     * Generate a reference number for the event.
     */
    private function generateReferenceNumber(Event $event): string
    {
        return 'EVT-' . $event->created_at->format('Ymd') . '-' . str_pad($event->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get human-readable status label.
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending Review',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($status),
        };
    }

    /**
     * Get status description for client.
     */
    private function getStatusDescription(string $status): string
    {
        return match ($status) {
            'pending' => 'Your event request is being reviewed by our team. We will contact you soon with details.',
            'confirmed' => 'Your event has been confirmed! Our team is preparing everything for your special day.',
            'in_progress' => 'Your event is currently taking place. We hope you are having a wonderful time!',
            'completed' => 'Your event has been completed successfully. Thank you for choosing our services!',
            'cancelled' => 'This event has been cancelled. Please contact us if you have any questions.',
            default => 'Event status: ' . ucfirst($status),
        };
    }

    /**
     * Get event timeline for client.
     */
    private function getEventTimeline(Event $event): array
    {
        $timeline = [
            [
                'date' => $event->created_at->format('Y-m-d H:i:s'),
                'title' => 'Event Request Submitted',
                'description' => 'Your event request was successfully submitted.',
                'status' => 'completed',
            ],
        ];

        if ($event->status !== 'pending') {
            $timeline[] = [
                'date' => $event->updated_at->format('Y-m-d H:i:s'),
                'title' => 'Event ' . $this->getStatusLabel($event->status),
                'description' => $this->getStatusDescription($event->status),
                'status' => 'completed',
            ];
        }

        if ($event->status === 'confirmed' && $event->event_date) {
            $timeline[] = [
                'date' => $event->event_date->format('Y-m-d') . ' ' . $event->start_time->format('H:i:s'),
                'title' => 'Event Day',
                'description' => 'Your event is scheduled to take place.',
                'status' => $event->isPast() ? 'completed' : 'upcoming',
            ];
        }

        return $timeline;
    }

    /**
     * Get next steps for client based on event status.
     */
    private function getNextSteps(Event $event): array
    {
        return match ($event->status) {
            'pending' => [
                'Wait for our team to review your request',
                'We will contact you within 24 hours',
                'Be ready to discuss any specific requirements',
            ],
            'confirmed' => [
                'Review the event details and invoice if available',
                'Make payment according to the payment schedule',
                'Contact us with any last-minute changes or questions',
                'Prepare for your event day',
            ],
            'in_progress' => [
                'Enjoy your event!',
                'Contact our on-site coordinator for any immediate needs',
            ],
            'completed' => [
                'Thank you for choosing our services',
                'We would appreciate your feedback',
                'Contact us for future events',
            ],
            'cancelled' => [
                'Contact us if you would like to reschedule',
                'Review our cancellation policy',
            ],
            default => [
                'Contact us for more information',
            ],
        };
    }

    /**
     * Send event confirmation email.
     */
    private function sendEventConfirmationEmail(Event $event): void
    {
        // Implement email sending logic here
        // You would typically use Laravel's Mail facade with a Mailable class

        try {
            // Mail::to($event->client->email)->send(new EventConfirmationMail($event));
        } catch (\Exception $e) {
            // Log email sending failure but don't fail the request
            Log::warning('Failed to send event confirmation email', [
                'event_id' => $event->id,
                'client_email' => $event->client->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send consultation request email to admin.
     */
    private function sendConsultationRequestEmail(Client $client, array $consultationData): void
    {
        try {
            // Mail::to(config('app.admin_email'))->send(new ConsultationRequestMail($client, $consultationData));
        } catch (\Exception $e) {
            // Log email sending failure but don't fail the request
            Log::warning('Failed to send consultation request email', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
