<?php

namespace App\Listeners;

use App\Events\JobGroupCreated;
use App\Services\EmailBuilder;

/**
 * Sends a notification email to every member of a newly-created job group.
 *
 * Matches the project-wide email pattern used by NotifyClientEventCreated:
 *   - EmailBuilder service (Brevo SMTP API)
 *   - Branded sender (`do_not_reply@kaitoevents.co.uk` / `Kaito Events`)
 *   - Blade view rendered with view(...)->with('data', $payload)->render()
 *   - One `to` recipient per call (1 sendEmail() call per group member)
 */
class SendJobGroupCreatedNotifications
{
    public function handle(JobGroupCreated $event): void
    {
        $group = $event->group;

        // Controller guarantees these are pre-loaded, but re-assert to be safe.
        $group->loadMissing(['event', 'members', 'teamLead:id,name,email', 'tasks']);

        $eventModel = $group->event;
        $tasks = $group->tasks
            ->map(static fn ($t) => [
                'title'            => $t->title,
                'instructions'     => $t->instructions,
                'status'           => $t->status,
                'location'         => $t->location,
                'duration_minutes' => $t->duration_minutes,
            ])
            ->toArray();

        $shared = [
            'group_name'        => $group->name,
            'group_description' => $group->description,
            'team_lead'         => [
                'name'  => $group->teamLead?->name  ?? 'TBD',
                'email' => $group->teamLead?->email ?? '—',
            ],
            'event' => [
                'title' => $eventModel?->event_name       ?? 'TBD',
                'date'  => $eventModel?->event_date?->toFormattedDateString() ?? 'TBD',
                'venue' => $eventModel?->venue?->venue_name ?? 'TBD',
            ],
            'member_count' => $group->members->count(),
            'tasks'        => $tasks,
        ];

        $subject = sprintf(
            "You've been added to the %s team — %s",
            $shared['group_name'],
            $shared['event']['title'],
        );

        $emailBuilder = app(EmailBuilder::class);

        foreach ($group->members as $member) {
            $viewData = array_merge($shared, [
                'recipient_name'  => $member->name,
                'recipient_email' => $member->email,
            ]);

            $emailBuilder->sendEmail([
                'subject'     => $subject,
                'senderEmail' => 'do_not_reply@kaitoevents.co.uk',
                'senderName'  => 'Kaito Events',
                'htmlContent' => view('emails.job-group-created')
                    ->with('data', $viewData)
                    ->render(),
                'to' => [
                    [
                        'email' => $member->email,
                        'name'  => $member->name,
                    ],
                ],
            ]);
        }
    }
}
