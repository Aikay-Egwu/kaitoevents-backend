<?php

namespace App\Listeners;

use App\Events\ClientEventCreated;
use App\Services\EmailBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyClientEventCreated
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ClientEventCreated $event): void
    {
        //dd($event->client);
        //$html = "<html><body>Thank you for doing this, it was nice</body></html>";
        $emailBuilder = new EmailBuilder();
        $emailBuilder->sendEmail([
            "subject" => "Consultation Request Received - Kaito Events",
            "senderEmail" => "do_not_reply@kaitoevents.co.uk",
            "senderName" => "Kaito Events",
            "htmlContent" => view(
                "emails.consultation-confirmation"
            )->with('data', $event->client)->render(),
            "to" => [
                [
                    'email' => $event->client['email'],
                    'name' => $event->client['firstName']
                ]
            ]
        ]);

        //send to admin
        $emailBuilder->sendEmail([
            'subject' => 'New Consultation Request - ' . $event->client['eventType'],
            "senderEmail" => "do_not_reply@kaitoevents.co.uk",
            "senderName" => "Kaito Events",
            "htmlContent" => view(
                "emails.consultation-request"
            )
                ->with('data', $event->client)
                ->render(),
            "to" => [
                [
                    'email' => "chioma@kaitoevents.co.uk",
                    'name' => "Event Team"
                ]
            ]
        ]);
        $emailBuilder->sendEmail([
            'subject' => 'New Consultation Request - ' . $event->client['eventType'],
            "senderEmail" => "do_not_reply@kaitoevents.co.uk",
            "senderName" => "Kaito Events",
            "htmlContent" => view(
                "emails.consultation-request"
            )
                ->with('data', $event->client)
                ->render(),
            "to" => [
                [
                    'email' => "egwu.chioma@gmail.com",
                    'name' => "Event Team"
                ]
            ]
        ]);
    }
}
