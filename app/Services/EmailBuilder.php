<?php

namespace App\Services;

use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Exception;

class EmailBuilder
{
    private TransactionalEmailsApi $apiInstance;
    private string $defaultSenderName;
    private string $defaultSenderEmail;

    public function __construct()
    {
        // Configure API key from environment
        $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', config('services.brevo.api_key'));
        
        // Initialize the API instance
        $this->apiInstance = new TransactionalEmailsApi(
            new Client(),
            $config
        );

        // Set default sender information
        $this->defaultSenderName = config('mail.from.name', 'Kaito Events');
        $this->defaultSenderEmail = config('mail.from.address', 'hello@kaitoevents.co.uk');
    }

    /**
     * Send a transactional email
     *
     * @param array $params Email parameters
     * @return array Result of the email send operation
     */
    public function sendEmail(array $params): array
    {
        //dd($params);
        try {
            // Handle sender parameter - support both nested array and separate keys
            $senderName = $params['senderName'] ?? $params['sender']['name'] ?? $this->defaultSenderName;
            $senderEmail = $params['senderEmail'] ?? $params['sender']['email'] ?? $this->defaultSenderEmail;
            
            // Handle replyTo parameter
            $replyToName = $params['replyToName'] ?? $params['replyTo']['name'] ?? $senderName;
            $replyToEmail = $params['replyToEmail'] ?? $params['replyTo']['email'] ?? $senderEmail;

            $sendSmtpEmail = new SendSmtpEmail([
                'subject' => $params['subject'],
                'sender' => [
                    'name' => $senderName,
                    'email' => $senderEmail
                ],
                /* 'replyTo' => [
                    'name' => $replyToName,
                    'email' => $replyToEmail
                ], */
                'to' => $this->formatRecipients($params['to']),
                'htmlContent' => $params['htmlContent'],
                'textContent' => $params['textContent'] ?? null,
                //'params' => $params['templateParams'] ?? []
            ]);

            /* $sendSmtpEmail = new SendSmtpEmail([
                'subject' => $headers['subject'],
                'sender' => ['name' => $headers['sender_name'], 'email' => $headers['sender_email']],
                'to' => [[ 'name' => $headers['to_name'] ?? "", 'email' => $headers['to_email']]],
                'htmlContent' => $headers['html_content'],
                "attachment" => $headers['attachment'] ?? null
                //'params' => $headers['params'],
                //'attachmentUrls' => $headers['attachmentUrls'],
            ]); */
    
            

            // Add CC recipients if provided
            if (isset($params['cc']) && !empty($params['cc'])) {
                $sendSmtpEmail->setCc($this->formatRecipients($params['cc']));
            }

            // Add BCC recipients if provided
            if (isset($params['bcc']) && !empty($params['bcc'])) {
                $sendSmtpEmail->setBcc($this->formatRecipients($params['bcc']));
            }

            try {
                //$apiInstance->sendTransacEmail($sendSmtpEmail);
                $result = $this->apiInstance->sendTransacEmail($sendSmtpEmail);
                //print_r($result);
            } catch (Exception $e) {
                echo 'Exception when calling TransactionalEmailsApi->sendTransacEmail: ', $e->getMessage(), PHP_EOL;
            }
            
            Log::info('Email sent successfully via Brevo', [
                'message_id' => $result->getMessageId(),
                'subject' => $params['subject'],
                'recipients' => $params['to']
            ]);

            return [
                'success' => true,
                'message_id' => $result->getMessageId(),
                'message' => 'Email sent successfully'
            ];

        } catch (Exception $e) {
            Log::error('Failed to send email via Brevo', [
                'error' => $e->getMessage(),
                'subject' => $params['subject'] ?? 'Unknown',
                'recipients' => $params['to'] ?? []
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Failed to send email'
            ];
        }
    }

    /**
     * Send consultation request email to admin
     *
     * @param array $consultationData
     * @return array
     */
    public function sendConsultationRequest(array $consultationData): array
    {
        $htmlContent = $this->buildConsultationRequestHtml($consultationData);
        
        return $this->sendEmail([
            'subject' => 'New Consultation Request - ' . $consultationData['eventType'],
            'to' => [['email' => config('mail.admin_email', 'admin@kaitoevents.co.uk')]],
            'htmlContent' => $htmlContent,
            'templateParams' => $consultationData
        ]);
    }

    /**
     * Send consultation confirmation email to client
     *
     * @param array $consultationData
     * @return array
     */
    public function sendConsultationConfirmation(array $consultationData): array
    {
        $htmlContent = $this->buildConsultationConfirmationHtml($consultationData);
        
        return $this->sendEmail([
            'subject' => 'Thank you for your consultation request - Kaito Events',
            'to' => [['email' => $consultationData['email'], 'name' => $consultationData['firstName'] . ' ' . $consultationData['lastName']]],
            'htmlContent' => $htmlContent,
            'templateParams' => $consultationData
        ]);
    }

    /**
     * Format recipients array for Brevo API
     *
     * @param array $recipients
     * @return array
     */
    private function formatRecipients(array $recipients): array
    {
        $formatted = [];
        
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $formatted[] = ['email' => $recipient];
            } elseif (is_array($recipient)) {
                $formatted[] = [
                    'email' => $recipient['email'],
                    'name' => $recipient['name'] ?? null
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * Build HTML content for consultation request email
     *
     * @param array $data
     * @return string
     */
    private function buildConsultationRequestHtml(array $data): string
    {
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>
                    New Consultation Request
                </h2>
                
                <div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2c3e50;'>Client Information</h3>
                    <p><strong>Name:</strong> {$data['firstName']} {$data['lastName']}</p>
                    <p><strong>Email:</strong> {$data['email']}</p>
                    <p><strong>Phone:</strong> {$data['phone']}</p>
                </div>
                
                <div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2c3e50;'>Event Details</h3>
                    <p><strong>Event Type:</strong> " . ucfirst($data['eventType']) . "</p>
                    <p><strong>Event Date:</strong> {$data['eventDate']}</p>
                    <p><strong>Guest Count:</strong> {$data['guestCount']}</p>
                    <p><strong>Has Venue:</strong> " . ucfirst($data['hasVenue']) . "</p>
                </div>
                
                <div style='margin-top: 30px; padding: 20px; background-color: #e8f4fd; border-radius: 5px;'>
                    <p style='margin: 0; font-size: 14px; color: #2c3e50;'>
                        Please respond to this consultation request within 24 hours.
                    </p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Build HTML content for consultation confirmation email
     *
     * @param array $data
     * @return string
     */
    private function buildConsultationConfirmationHtml(array $data): string
    {
        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>
                    Thank You for Your Consultation Request
                </h2>
                
                <p>Dear {$data['firstName']},</p>
                
                <p>Thank you for reaching out to Kaito Events! We have received your consultation request for your upcoming " . strtolower($data['eventType']) . " and are excited to help make your special day unforgettable.</p>
                
                <div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2c3e50;'>Your Request Summary</h3>
                    <p><strong>Event Type:</strong> " . ucfirst($data['eventType']) . "</p>
                    <p><strong>Event Date:</strong> {$data['eventDate']}</p>
                    <p><strong>Guest Count:</strong> {$data['guestCount']}</p>
                    <p><strong>Venue Status:</strong> " . ($data['hasVenue'] === 'yes' ? 'Venue secured' : 'Venue assistance needed') . "</p>
                </div>
                
                <div style='background-color: #e8f4fd; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2c3e50;'>What Happens Next?</h3>
                    <ul style='margin: 0; padding-left: 20px;'>
                        <li>Our team will review your request within 24 hours</li>
                        <li>We'll contact you to schedule a detailed consultation</li>
                        <li>Together, we'll create a customized plan for your event</li>
                    </ul>
                </div>
                
                <p>If you have any immediate questions or need to make changes to your request, please don't hesitate to contact us.</p>
                
                <div style='margin-top: 30px; padding: 20px; background-color: #2c3e50; color: white; border-radius: 5px; text-align: center;'>
                    <h3 style='margin-top: 0;'>Kaito Events</h3>
                    <p style='margin: 5px 0;'>Creating Unforgettable Moments</p>
                    <p style='margin: 5px 0;'>Email: hello@kaitoevents.co.uk</p>
                    <p style='margin: 5px 0;'>Website: www.kaitoevents.co.uk</p>
                </div>
            </div>
        </body>
        </html>";
    }
}