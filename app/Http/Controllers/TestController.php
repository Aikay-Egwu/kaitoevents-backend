<?php

namespace App\Http\Controllers;

use App\Services\EmailBuilder;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    private EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Test email functionality using Brevo
     */
    public function testEmail(EmailBuilder $emailBuilder): JsonResponse
    {
        // Check if API key is configured
        $apiKey = config('services.brevo.api_key');
        if (!$apiKey || $apiKey === 'your_brevo_api_key_here') {
            return response()->json([
                'success' => false,
                'error' => 'Brevo API key not configured. Please set BREVO_API_KEY in your .env file',
                'message' => 'Configuration error'
            ], 500);
        }
        //return response()->json($apiKey);
        $email = $emailBuilder->sendEmail([
            "subject" => "Test Email from Kaito Events",
            "senderEmail" => "do_not_reply@kaitoevents.co.uk",
            "senderName" => "Kaito Events",
            "htmlContent" => "<html><body><h1>Test Email</h1><p>This is a test email from Kaito Events!</p></body></html>",
            "to" => [
                [
                    'email' => "justaikay@gmail.com",
                    'name' => "Ikenna Egwu"
                ]
            ]
        ]);
        
        return response()->json($email, $email['success'] ? 200 : 500);
    }

    /**
     * Test custom email sending
     */
    public function testCustomEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string'
        ]);

        $result = $this->emailService->sendCustomEmail([
            'subject' => $validated['subject'],
            'to' => [['email' => $validated['to']]],
            'htmlContent' => '<html><body><p>' . nl2br(e($validated['message'])) . '</p></body></html>',
        ]);

        return response()->json($result, $result['success'] ? 200 : 500);
    }
}