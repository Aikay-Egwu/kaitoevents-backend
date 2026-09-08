<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookConsultationRequest;
use App\Models\Client;
use App\Models\Event;
use App\Services\EmailService;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    public EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function index()
    {
        $clients = Client::all();
        return response()->json($clients);
    }

    public function show(Client $client)
    {
        return response()->json($client);
    }

    public function bookConsultation(BookConsultationRequest $request)
    {
        $validatedData = $request->validated();

        // Create the client
        $client = Client::create([
            'firstname' => $validatedData['firstname'],
            'lastname' => $validatedData['lastname'],
            'email' => $validatedData['email'],
            'phone' => $validatedData['phone'],
        ]);

        // Create the event
        $event = new Event([
            'event_type_id' => $validatedData['event_type_id'],
            'event_date' => $validatedData['event_date'],
            'guest_number' => $validatedData['guest_number'],
            'event_time' => $validatedData['event_time'] ?? null,
            'budget' => $validatedData['budget'] ?? null,
            'venue_name' => $validatedData['venue_name'],
            'venue_address' => $validatedData['venue_address'],
            'status' => 'inquiry', // Set status to inquiry
        ]);

        $client->events()->save($event);

        // Send emails
        $emailResult = $this->emailService->sendConsultationRequest($validatedData);
        
        if (!$emailResult['success']) {
            // Log the error but don't fail the request
            Log::warning('Email sending failed for consultation: ' . $emailResult['message']);
        }

        return response()->json([
            'message' => 'Consultation booked successfully!', 
            'client' => $client, 
            'event' => $event,
            'email_sent' => $emailResult['success']
        ], 201);
    }
}