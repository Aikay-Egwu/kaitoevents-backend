<?php

namespace Tests\Feature\Jobs;

use App\Events\JobGroupCreated;
use App\Mail\JobGroupCreatedMail;
use App\Models\Client;
use App\Models\Event as EventModel;
use App\Models\EventType;
use App\Models\JobGroup;
use App\Models\JobTask;
use App\Models\User;
use App\Services\EmailBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the Job/Team Management system.
 *
 * Coverage:
 *   1. Group CRUD + validation (Workflow 1)
 *   2. Member addition / removal at any time (Workflow 2)
 *   3. Task creation / edit / delete / assignment to a group (Workflow 3)
 *   4. Email notification dispatched to every member on group creation (Workflow 4)
 *   5. Referential integrity (FK constraints + route guards)
 *   6. Optional duration_minutes field validation
 */
class JobControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User   $admin;
    protected User   $lead;
    protected User   $memberA;
    protected User   $memberB;
    protected EventModel  $event;

    /**
     * @var array<int, array<string,mixed>> All `sendEmail()` payloads captured by
     *      the EmailBuilder spy installed in setUp. Always use the shared spy so
     *      we never make real (Brevo) SMTP API calls during test runs.
     */
    protected array $emailCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Users — Admin acts as the authenticated requester.
        $this->admin    = User::factory()->create(['type' => 'Admin']);
        $this->lead     = User::factory()->create(['type' => 'Manager']);
        $this->memberA  = User::factory()->create(['type' => 'Staff']);
        $this->memberB  = User::factory()->create(['type' => 'Staff']);

        // Note: ClientFactory and EventTypeFactory are empty (no required fields).
        // We create them manually with all required columns.
        $client = Client::create([
            'firstname' => 'Jane',
            'lastname'  => 'Doe',
            'email'     => 'jane.doe.jobtest@example.com',
            'phone'     => '+44 000 0000000',
        ]);

        $eventType = EventType::create([
            'title'       => 'Corporate Gala',
            'slug'        => 'corporate-gala-jobtest-' . uniqid(),
            'tag'         => 'premium',
            'description' => 'Corporate function test fixture',
            'status'      => 'active',
        ]);

        $this->event = EventModel::create([
            'client_id'          => $client->id,
            'event_type_id'      => $eventType->id,
            'event_name'         => 'JobSystem Test Event',
            'event_date'         => now()->addMonth()->toDateString(),
            'start_time'         => '10:00:00',
            'end_time'           => '18:00:00',
            'budget'             => '10000.00',
            'number_of_guests'   => 100,
            'special_instructions' => 'No special instructions',
            'status'             => 'confirmed',
        ]);

        // Replace the real EmailBuilder with a no-op spy so that NO test ever
        // tries to hit the Brevo SMTP API. Captured payloads are appended to
        // $this->emailCalls for any test that needs to assert on them.
        $spy = new class($this->emailCalls) extends EmailBuilder {
            /** @var array<int, array<string,mixed>> $ref Reference to outer array */
            private array $ref;
            public function __construct(array &$outer)
            {
                $this->ref = &$outer;
            }
            /** @param array<string,mixed> $params */
            public function sendEmail(array $params): array
            {
                $this->ref[] = $params;
                return ['success' => true, 'id' => 'spy-' . count($this->ref)];
            }
        };
        $this->app->instance(EmailBuilder::class, $spy);

        // Auth::login would suffice for Sanctum session-guard;
        // actingAs via Sanctum token gives us the middleware path.
        Sanctum::actingAs($this->admin);
    }

    protected function groupsUrl(): string
    {
        return "/api/admin/events/{$this->event->id}/groups";
    }

    // ===================== Workflow 1 – Group CRUD =====================

    /** @test */
    public function admins_can_create_a_group_with_members_and_initial_tasks(): void
    {
        Mail::fake();

        $payload = [
            'name'              => 'Décor Team',
            'team_lead_user_id' => $this->lead->id,
            'description'       => 'Handles all decoration and venue styling',
            'member_ids'        => [$this->lead->id, $this->memberA->id],
            'tasks' => [
                [
                    'title'            => 'Set up centrepieces',
                    'location'         => 'on_site',
                    'status'           => 'pending',
                    'instructions'     => '10 tables, gold vases',
                    'duration_minutes' => 90,
                ],
                [
                    'title'    => 'Prepare ribbon samples',
                    'location' => 'in_house',
                ],
            ],
        ];

        $res = $this->postJson($this->groupsUrl(), $payload);

        $res->assertStatus(201)
            ->assertJsonPath('data.name', 'Décor Team')
            ->assertJsonPath('data.team_lead_user_id', $this->lead->id)
            ->assertJsonCount(2, 'data.tasks')
            ->assertJsonCount(2, 'data.members');

        $groupId = $res->json('data.id');

        // Team lead must always be auto-included in member_ids list
        $this->assertDatabaseHas('event_job_groups', [
            'id'                => $groupId,
            'event_id'          => $this->event->id,
            'name'              => 'Décor Team',
            'team_lead_user_id' => $this->lead->id,
        ]);

        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $groupId,
            'user_id'            => $this->memberA->id,
            'is_team_lead'       => false,
        ]);
        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $groupId,
            'user_id'            => $this->lead->id,
            'is_team_lead'       => true,
        ]);

        $this->assertDatabaseHas('event_job_tasks', [
            'event_job_group_id' => $groupId,
            'title'              => 'Set up centrepieces',
            'location'           => 'on_site',
            'duration_minutes'   => 90,
        ]);
        $this->assertDatabaseHas('event_job_tasks', [
            'event_job_group_id' => $groupId,
            'title'              => 'Prepare ribbon samples',
            'location'           => 'in_house',
            'duration_minutes'   => null,
            'status'             => 'pending',
        ]);
    }

    /** @test */
    public function creating_group_without_required_fields_returns_validation_errors(): void
    {
        $res = $this->postJson($this->groupsUrl(), []);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'team_lead_user_id']);
    }

    /** @test */
    public function creating_group_validates_task_nested_fields(): void
    {
        $res = $this->postJson($this->groupsUrl(), [
            'name'              => 'Team X',
            'team_lead_user_id' => $this->lead->id,
            'member_ids'        => [$this->lead->id],
            'tasks' => [
                ['instructions' => 'missing title and location'],
            ],
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['tasks.0.title', 'tasks.0.location']);
    }

    /** @test */
    public function admins_can_update_group_details_and_switch_lead(): void
    {
        $group = JobGroup::factory()->for(
            $this->event,
            'event',
        )->create([
            'name'              => 'Old Team',
            'team_lead_user_id' => $this->lead->id,
            'description'       => 'first',
        ]);
        $group->members()->sync([
            $this->lead->id    => ['is_team_lead' => true],
            $this->memberA->id => ['is_team_lead' => false],
        ]);

        $res = $this->putJson("{$this->groupsUrl()}/{$group->id}", [
            'name'              => 'Renamed Team',
            'team_lead_user_id' => $this->memberA->id,
            'description'       => 'second',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Team')
            ->assertJsonPath('data.team_lead_user_id', $this->memberA->id);

        // Pivot lead flag should have been moved to memberA
        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $group->id,
            'user_id'            => $this->memberA->id,
            'is_team_lead'       => true,
        ]);
        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $group->id,
            'user_id'            => $this->lead->id,
            'is_team_lead'       => false,
        ]);
    }

    /** @test */
    public function admins_can_delete_a_group_and_cascade_removes_members_and_tasks(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create([
            'name'              => 'Doomed Team',
            'team_lead_user_id' => $this->lead->id,
        ]);
        $group->members()->sync([$this->lead->id => ['is_team_lead' => true]]);
        $group->tasks()->create([
            'event_id' => $this->event->id,
            'title'    => 'Vanish me',
            'location' => 'in_house',
        ]);

        $groupId   = $group->id;
        $taskId    = $group->tasks->first()->id;

        $res = $this->deleteJson("{$this->groupsUrl()}/{$groupId}");
        $res->assertStatus(200);

        $this->assertDatabaseMissing('event_job_groups', ['id' => $groupId]);
        $this->assertDatabaseMissing('event_job_group_members', ['event_job_group_id' => $groupId]);
        $this->assertDatabaseMissing('event_job_tasks', ['id' => $taskId]);
    }

    /** @test */
    public function accessing_a_group_from_a_different_event_returns_404(): void
    {
        $otherClient = Client::create([
            'firstname' => 'Jim',
            'lastname'  => 'Smith',
            'email'     => 'other.client.jobtest@example.com',
        ]);
        $otherType = EventType::create([
            'title'       => 'Birthday Party',
            'slug'        => 'birthday-jobtest-' . uniqid(),
            'description' => 'Other',
            'status'      => 'active',
        ]);

        $otherEvent = EventModel::create([
            'client_id'          => $otherClient->id,
            'event_type_id'      => $otherType->id,
            'event_name'         => 'Other Event',
            'event_date'         => now()->addMonths(2)->toDateString(),
            'start_time'         => '09:00:00',
            'end_time'           => '17:00:00',
            'budget'             => '5000.00',
            'number_of_guests'   => 50,
            'status'             => 'inquiry',
        ]);
        $group = JobGroup::factory()->for($otherEvent, 'event')->create();

        $res = $this->getJson("{$this->groupsUrl()}/{$group->id}");
        $res->assertStatus(404);
    }

    // ===================== Workflow 2 – Member management =====================

    /** @test */
    public function admins_can_add_members_to_an_existing_group(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create([
            'name'              => 'Growing Team',
            'team_lead_user_id' => $this->lead->id,
        ]);
        $group->members()->sync([$this->lead->id => ['is_team_lead' => true]]);

        $res = $this->postJson("{$this->groupsUrl()}/{$group->id}/members", [
            'user_id' => $this->memberA->id,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $group->id,
            'user_id'            => $this->memberA->id,
            'is_team_lead'       => false,
        ]);
    }

    /** @test */
    public function adding_the_same_member_twice_is_rejected(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();
        $group->members()->attach($this->memberA->id);

        $res = $this->postJson("{$this->groupsUrl()}/{$group->id}/members", [
            'user_id' => $this->memberA->id,
        ]);

        $res->assertStatus(422);
        $this->assertEquals(
            1,
            $group->members()->where('user_id', $this->memberA->id)->count(),
        );
    }

    /** @test */
    public function admins_can_remove_members_from_a_group(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create([
            'team_lead_user_id' => $this->lead->id,
        ]);
        $group->members()->sync([
            $this->lead->id    => ['is_team_lead' => true],
            $this->memberA->id => ['is_team_lead' => false],
        ]);

        $res = $this->deleteJson("{$this->groupsUrl()}/{$group->id}/members/{$this->memberA->id}");
        $res->assertStatus(200);

        $this->assertDatabaseMissing('event_job_group_members', [
            'event_job_group_id' => $group->id,
            'user_id'            => $this->memberA->id,
        ]);
        // But team lead is still there
        $this->assertDatabaseHas('event_job_group_members', [
            'event_job_group_id' => $group->id,
            'user_id'            => $this->lead->id,
        ]);
    }

    /** @test */
    public function removing_the_team_lead_clears_the_groups_lead_fk(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create([
            'team_lead_user_id' => $this->lead->id,
        ]);
        $group->members()->sync([$this->lead->id => ['is_team_lead' => true]]);

        $this->deleteJson("{$this->groupsUrl()}/{$group->id}/members/{$this->lead->id}")
            ->assertStatus(200);

        $group->refresh();
        $this->assertNull($group->team_lead_user_id);
    }

    /** @test */
    public function admins_can_promote_a_new_member_to_lead_when_adding(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create([
            'team_lead_user_id' => $this->lead->id,
        ]);
        $group->members()->sync([$this->lead->id => ['is_team_lead' => true]]);

        $res = $this->postJson("{$this->groupsUrl()}/{$group->id}/members", [
            'user_id'      => $this->memberA->id,
            'is_team_lead' => true,
        ]);

        $res->assertStatus(201);
        $group->refresh();

        $this->assertEquals($this->memberA->id, $group->team_lead_user_id);

        // Only one lead flag should be set after promotion
        $this->assertSame(
            1,
            $group->members()->wherePivot('is_team_lead', true)->count(),
        );
    }

    // ===================== Workflow 3 – Tasks =====================

    /** @test */
    public function admins_can_create_and_assign_a_task_to_a_whole_group(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();

        $res = $this->postJson("{$this->groupsUrl()}/{$group->id}/tasks", [
            'title'            => 'Catering final tasting',
            'instructions'     => 'Bring wine pairings',
            'status'           => 'in_progress',
            'location'         => 'on_site',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.title', 'Catering final tasting')
            ->assertJsonPath('data.event_job_group_id', $group->id)
            ->assertJsonPath('data.event_id', $this->event->id)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.location', 'on_site')
            ->assertJsonPath('data.duration_minutes', 120);

        $this->assertDatabaseHas('event_job_tasks', [
            'event_job_group_id' => $group->id,
            'event_id'           => $this->event->id,
            'title'              => 'Catering final tasting',
        ]);
    }

    /** @test */
    public function task_creation_validates_all_required_fields(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();

        $this->postJson("{$this->groupsUrl()}/{$group->id}/tasks", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'location']);

        $this->postJson("{$this->groupsUrl()}/{$group->id}/tasks", [
            'title'    => 'X',
            'location' => 'mars', // invalid enum
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['location']);
    }

    /** @test */
    public function optional_duration_field_only_accepts_positive_integers(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();

        // Null accepted (optional)
        $this->postJson("{$this->groupsUrl()}/{$group->id}/tasks", [
            'title'            => 'No duration task',
            'location'         => 'in_house',
            'duration_minutes' => null,
        ])->assertStatus(201);

        // Min 1 rejected for 0
        $this->postJson("{$this->groupsUrl()}/{$group->id}/tasks", [
            'title'            => 'Zero duration',
            'location'         => 'in_house',
            'duration_minutes' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['duration_minutes']);
    }

    /** @test */
    public function admins_can_update_a_task(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();
        $task  = $group->tasks()->create([
            'event_id' => $this->event->id,
            'title'    => 'First',
            'location' => 'in_house',
        ]);

        $res = $this->putJson("{$this->groupsUrl()}/{$group->id}/tasks/{$task->id}", [
            'title'            => 'Renamed task',
            'location'         => 'on_site',
            'status'           => 'completed',
            'duration_minutes' => 45,
            'instructions'     => 'Wipe surfaces after',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.title', 'Renamed task')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.duration_minutes', 45);

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('on_site', $task->location);
    }

    /** @test */
    public function admins_can_delete_a_task(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();
        $task  = $group->tasks()->create([
            'event_id' => $this->event->id,
            'title'    => 'Temp',
            'location' => 'in_house',
        ]);

        $this->deleteJson("{$this->groupsUrl()}/{$group->id}/tasks/{$task->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('event_job_tasks', ['id' => $task->id]);
    }

    /** @test */
    public function tasks_are_always_bound_to_the_event_via_group_delete_cascade(): void
    {
        $group = JobGroup::factory()->for($this->event, 'event')->create();
        $group->tasks()->create([
            'event_id' => $this->event->id,
            'title'    => 'Event-binded task',
            'location' => 'in_house',
        ]);

        // Sanity: Event relation exists directly on the task
        $this->assertEquals(1, JobTask::where('event_id', $this->event->id)->count());

        // Deleting the event cascades to groups and tasks
        $this->event->delete();

        $this->assertEquals(0, JobGroup::count());
        $this->assertEquals(0, JobTask::count());
    }

    // ===================== Workflow 4 – Email notifications =====================

    /** @test */
    public function creating_a_group_dispatches_the_job_group_created_event(): void
    {
        // Event::fake() stops listeners from running — we only want to assert
        // the event was fired here. The separate test below runs the listener
        // against our EmailBuilder spy to verify payloads and rendering.
        Event::fake([JobGroupCreated::class]);

        $this->postJson($this->groupsUrl(), [
            'name'              => 'Lighting Team',
            'team_lead_user_id' => $this->lead->id,
            'member_ids'        => [$this->lead->id, $this->memberA->id],
            'tasks' => [
                ['title' => 'Spot-check rig', 'location' => 'on_site', 'duration_minutes' => 45],
            ],
        ])->assertStatus(201);

        // 1. Event was dispatched exactly once
        Event::assertDispatched(JobGroupCreated::class, 1);

        // 2. Event payload has the saved group with expected FKs / counts
        Event::assertDispatched(
            JobGroupCreated::class,
            function (JobGroupCreated $e): bool {
                $g = $e->group;
                $this->assertSame('Lighting Team', $g->name);
                $this->assertSame($this->lead->id, (int) $g->team_lead_user_id);
                $this->assertTrue($g->relationLoaded('members'));
                $this->assertTrue($g->relationLoaded('tasks'));
                $this->assertCount(2, $g->members);
                $this->assertCount(1, $g->tasks);
                $this->assertSame('Spot-check rig', $g->tasks->first()->title);
                return true;
            },
        );
    }

    /** @test */
    public function creating_a_group_dispatches_an_email_to_every_member_with_full_details(): void
    {
        // Spy + $emailCalls capture are already installed in setUp.
        // Reset the buffer so this test's count is deterministic (no spillover
        // from other tests that also create groups).
        $this->emailCalls = [];

        $this->postJson($this->groupsUrl(), [
            'name'              => 'Audio/Visual Team',
            'team_lead_user_id' => $this->lead->id,
            'description'       => 'Microphones, screens, lights.',
            'member_ids'        => [$this->lead->id, $this->memberA->id, $this->memberB->id],
            'tasks' => [
                [
                    'title'            => 'Rig stage mics',
                    'location'         => 'on_site',
                    'duration_minutes' => 60,
                ],
                [
                    'title'    => 'Charge camera batteries',
                    'location' => 'in_house',
                ],
            ],
        ])->assertStatus(201);

        // 1. 3 emails dispatched — one per member (including the lead)
        $this->assertCount(3, $this->emailCalls, 'Expected one sendEmail() call per member');

        // 2. Each payload targets the correct member email/name + branded sender
        $expectedRecipients = [$this->lead, $this->memberA, $this->memberB];
        foreach ($expectedRecipients as $idx => $user) {
            $this->assertSame('do_not_reply@kaitoevents.co.uk', $this->emailCalls[$idx]['senderEmail']);
            $this->assertSame('Kaito Events',                  $this->emailCalls[$idx]['senderName']);
            $this->assertSame($user->email,                    $this->emailCalls[$idx]['to'][0]['email']);
            $this->assertSame($user->name,                     $this->emailCalls[$idx]['to'][0]['name']);
            $this->assertStringContainsString('Audio/Visual Team', (string) $this->emailCalls[$idx]['subject']);
            $this->assertStringContainsString((string) $this->event->event_name, (string) $this->emailCalls[$idx]['subject']);
        }

        // 3. HTML content has all group/event/task details rendered in the blade
        $leadEmailHtml = (string) $this->emailCalls[0]['htmlContent'];
        $this->assertStringContainsString('Audio/Visual Team',                 $leadEmailHtml);
        $this->assertStringContainsString('Microphones, screens, lights.',     $leadEmailHtml);
        $this->assertStringContainsString($this->lead->name,                   $leadEmailHtml);
        $this->assertStringContainsString($this->lead->email,                  $leadEmailHtml);
        $this->assertStringContainsString((string) $this->event->event_name,   $leadEmailHtml);
        $this->assertStringContainsString('Rig stage mics',                    $leadEmailHtml);
        $this->assertStringContainsString('Charge camera batteries',           $leadEmailHtml);
        $this->assertStringContainsString('On-site',                           $leadEmailHtml);
        $this->assertStringContainsString('In-house',                          $leadEmailHtml);
        $this->assertStringContainsString('60 min',                            $leadEmailHtml);
        $this->assertMatchesRegularExpression('/3\s+member\(s\)/',             $leadEmailHtml);
    }

    /** @test */
    public function unauthenticated_requests_are_rejected(): void
    {
        // Temporarily clear the authenticated user set by setUp's
        // Sanctum::actingAs so the next two requests hit 401 as expected.
        $auth = $this->app->make('auth');
        try {
            $auth->guard('sanctum')->forgetUser();
        } catch (\Throwable) {
            // Guard may not implement forgetUser
        }
        try {
            $auth->guard('web')->forgetUser();
        } catch (\Throwable) {
            // Session guard not bound
        }

        $this->getJson($this->groupsUrl())->assertStatus(401);
        $this->postJson($this->groupsUrl(), [])->assertStatus(401);

        // Restore the authenticated user so subsequent tests remain valid.
        Sanctum::actingAs($this->admin);
    }

    /** @test */
    public function list_endpoint_returns_groups_with_relations_loaded(): void
    {
        // Avoid factory/Factory attribute merge quirks — build then save.
        $group = new JobGroup();
        $group->event_id          = $this->event->id;
        $group->name              = 'Listed Team';
        $group->team_lead_user_id = $this->lead->id;
        $group->description       = 'Eager-load test';
        $group->save();
        $this->assertSame($this->lead->id, (int) $group->fresh()->team_lead_user_id);

        $group->members()->sync([
            $this->lead->id    => ['is_team_lead' => true],
            $this->memberA->id => ['is_team_lead' => false],
        ]);
        $group->tasks()->create([
            'event_id' => $this->event->id,
            'title'    => 'List task',
            'location' => 'in_house',
        ]);

        $res = $this->getJson($this->groupsUrl());
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Listed Team')
            ->assertJsonCount(2, 'data.0.members')
            ->assertJsonCount(1, 'data.0.tasks')
            // Relationship names are snake_cased when toArray() is called for JSON
            ->assertJsonPath('data.0.team_lead.id', $this->lead->id)
            ->assertJsonPath('data.0.team_lead.name', $this->lead->name);
    }
}
