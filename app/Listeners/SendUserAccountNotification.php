<?php

namespace App\Listeners;

use App\Events\UserAccountCreated;
use App\Services\EmailBuilder;

/**
 * SendUserAccountNotification — sends a welcome email when a new user
 * account is created, containing their email and temporary password.
 *
 * The user is prompted to log in and change their password immediately.
 */
class SendUserAccountNotification
{
    /**
     * Handle the event — build and send the welcome email.
     */
    public function handle(UserAccountCreated $event): void
    {
        $emailBuilder = new EmailBuilder();

        $emailBuilder->sendEmail([
            'subject'     => 'Your Kaito Events Admin Account Has Been Created',
            'senderEmail' => 'do_not_reply@kaitoevents.co.uk',
            'senderName'  => 'Kaito Events',
            'htmlContent' => view('emails.welcome-user')
                ->with('data', [
                    'name'     => $event->user->name,
                    'email'    => $event->user->email,
                    'password' => $event->plainTextPassword,
                ])
                ->render(),
            'to' => [
                [
                    'email' => $event->user->email,
                    'name'  => $event->user->name,
                ],
            ],
        ]);
    }
}
