<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\ConsultationRequestMail;
use App\Mail\ConsultationConfirmationMail;

class EmailService
{
    private EmailBuilder $emailBuilder;

    public function __construct(EmailBuilder $emailBuilder)
    {
        $this->emailBuilder = $emailBuilder;
    }

    /**
     * Send consultation request emails using Laravel Mail (for queue support)
     */
    public function sendConsultationRequest($consultationData)
    {
        try {
            // Send email to admin
            Mail::to(config('mail.admin_email', 'admin@kaitoevents.co.uk'))
                ->queue(new ConsultationRequestMail($consultationData));

            // Send confirmation email to client
            Mail::to($consultationData['email'])
                ->queue(new ConsultationConfirmationMail($consultationData));

            return ['success' => true, 'message' => 'Emails sent successfully'];
        } catch (\Exception $e) {
            Log::error('Email sending failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send emails'];
        }
    }

    /**
     * Send consultation request emails directly via Brevo (immediate sending)
     */
    public function sendConsultationRequestDirect($consultationData)
    {
        try {
            // Send email to admin
            $adminResult = $this->emailBuilder->sendConsultationRequest($consultationData);
            
            // Send confirmation email to client
            $clientResult = $this->emailBuilder->sendConsultationConfirmation($consultationData);

            if ($adminResult['success'] && $clientResult['success']) {
                return ['success' => true, 'message' => 'Emails sent successfully via Brevo'];
            } else {
                $errors = [];
                if (!$adminResult['success']) $errors[] = 'Admin email: ' . $adminResult['message'];
                if (!$clientResult['success']) $errors[] = 'Client email: ' . $clientResult['message'];
                
                return ['success' => false, 'message' => 'Some emails failed: ' . implode(', ', $errors)];
            }
        } catch (\Exception $e) {
            Log::error('Direct email sending failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send emails directly'];
        }
    }

    /**
     * Test email functionality using Brevo directly
     */
    public function testEmail()
    {
        $consultationData = [
            "firstName" => "Ikenna",
            "lastName" => "Egwu",
            "phone" => "07442025899",
            "email" => "justaikay@gmail.com",
            "eventDate" => "22nd December 2026",
            "guestCount" => 222,
            "eventType" => 'wedding',
            "hasVenue" => "no"
        ];

        // Use direct Brevo sending for testing
        return $this->sendConsultationRequestDirect($consultationData);
    }

    /**
     * Send a custom email via Brevo
     */
    public function sendCustomEmail(array $params)
    {
        return $this->emailBuilder->sendEmail($params);
    }
}