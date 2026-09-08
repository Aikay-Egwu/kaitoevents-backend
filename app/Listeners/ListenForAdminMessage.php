<?php

namespace App\Listeners;

use App\Events\SendAdminMessage;
use App\Services\EmailBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ListenForAdminMessage
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
    public function handle(SendAdminMessage $event): void
    {
        $emailBuilder = new EmailBuilder();



        $emailBuilder->sendEmail([
            'subject' => $event->payload['subject'],
            "senderEmail" => "do_not_reply@kaitoevents.co.uk",
            "senderName" => "Kaito Events",
            "htmlContent" => view(
                "emails.admin-message"
            )
                ->with('message', $event->payload['message'])
                ->with('name', $event->payload['name'])
                ->render(),
            "to" => [
                [
                    'email' => $event->payload['email'],
                    'name' => $event->payload['name']
                ]
            ]
        ]);
    }
}
