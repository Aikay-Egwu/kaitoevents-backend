<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\JobGroupCreated;
use App\Http\Requests\JobGroupMemberRequest;
use App\Http\Requests\JobGroupRequest;
use App\Http\Requests\JobTaskRequest;
use App\Models\Event;
use App\Models\JobGroup;
use App\Models\JobTask;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * JobController — admin job management (groups + members + tasks).
 *
 * All endpoints are nested under /api/admin/events/{event}/groups/...
 * Route-model binding automatically resolves {event} and {group} parameters,
 * and the controller ensures the group actually belongs to the given event.
 *
 * Responsibilities:
 *   - Groups  : list / show / create / update / delete
 *   - Members : list / add / remove / promote to lead
 *   - Tasks   : list / show / create / update / delete (assigned to whole group)
 *   - Email   : on group creation, fires JobGroupCreated event which sends
 *               a notification per member via SendJobGroupCreatedNotifications
 */
final class JobController extends Controller
{
    // ------------------------- Groups --------------------------------

    /** List every group for an event, with members, team lead and tasks loaded. */
    public function indexGroups(Event $event): JsonResponse
    {
        $groups = $event->jobGroups()
            ->with(['members:id,name,email,type', 'teamLead:id,name,email', 'tasks'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /** Fetch single group with all relations loaded. */
    public function showGroup(Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404, 'Group not found for event');

        $group->load(['members:id,name,email,type', 'teamLead:id,name,email', 'tasks']);

        return response()->json([
            'success' => true,
            'data' => $group,
        ]);
    }

    /**
     * Create a group, optionally with initial members + tasks in one call,
     * then fire the JobGroupCreated event so listeners notify every member.
     */
    public function storeGroup(JobGroupRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();

        /** @var array<int> $memberIds */
        $memberIds = $request->input('member_ids', []);
        /** @var array $initialTasks */
        $initialTasks = $request->input('tasks', []);

        $teamLeadId = (int) $validated['team_lead_user_id'];

        // Ensure the team lead is always included in the member list
        if (! in_array($teamLeadId, $memberIds, false)) {
            $memberIds[] = $teamLeadId;
        }

        try {
            $group = DB::transaction(function () use ($validated, $event, $memberIds, $teamLeadId, $initialTasks) {
                /** @var JobGroup $group */
                $group = $event->jobGroups()->create([
                    'name'               => $validated['name'],
                    'team_lead_user_id'  => $teamLeadId,
                    'description'        => $validated['description'] ?? null,
                ]);

                // Attach members; mark the designated team lead in the pivot
                $pivot = collect($memberIds)
                    ->unique()
                    ->mapWithKeys(fn(int $uid) => [
                        $uid => ['is_team_lead' => $uid === $teamLeadId],
                    ])
                    ->all();
                $group->members()->sync($pivot);

                // Bulk-create any initial tasks (each inherits event + group)
                if (count($initialTasks)) {
                    $tasks = collect($initialTasks)->map(function (array $t) use ($event, $group) {
                        return [
                            'event_id'           => $event->id,
                            'event_job_group_id' => $group->id,
                            'title'              => $t['title'],
                            'instructions'       => $t['instructions'] ?? null,
                            'status'             => $t['status'] ?? 'pending',
                            'location'           => $t['location'],
                            'duration_minutes'   => $t['duration_minutes'] ?? null,
                        ];
                    })->all();

                    JobTask::insert($tasks);
                }

                return $group;
            });
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create group',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // Freshly reload relations so any listener has a complete snapshot.
        $group->load(['event', 'members', 'teamLead:id,name,email', 'tasks']);

        // Fire the event — actual email delivery is the responsibility of
        // SendJobGroupCreatedNotifications (decouples controller from transport)
        event(new JobGroupCreated($group));

        return response()->json([
            'success' => true,
            'data'    => $group,
            'message' => sprintf(
                'Group created — notification sent to %d member(s)',
                $group->members->count(),
            ),
        ], 201);
    }

    /** Update group name / description / team lead. */
    public function updateGroup(JobGroupRequest $request, Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404, 'Group not found for event');

        $validated = $request->validated();
        $teamLeadId = (int) $validated['team_lead_user_id'];

        // Clear the lead flag for previous members
        $group->members()->syncWithoutDetaching([$teamLeadId => ['is_team_lead' => true]]);
        DB::table('event_job_group_members')
            ->where('event_job_group_id', $group->id)
            ->where('user_id', '!=', $teamLeadId)
            ->update(['is_team_lead' => false]);

        $group->update([
            'name'               => $validated['name'],
            'team_lead_user_id'  => $teamLeadId,
            'description'        => $validated['description'] ?? null,
        ]);

        $group->load(['members:id,name,email,type', 'teamLead:id,name,email', 'tasks']);

        return response()->json([
            'success' => true,
            'data'    => $group,
            'message' => 'Group updated',
        ]);
    }

    /** Delete a group (pivot + tasks cascade via FK). */
    public function destroyGroup(Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404, 'Group not found for event');

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Group deleted',
        ]);
    }

    // ------------------------- Members --------------------------------

    /** Get all members in the group. */
    public function listMembers(Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);
        $group->load('members:id,name,email,type');

        return response()->json([
            'success' => true,
            'data' => $group->members,
        ]);
    }

    /** Add a single member to the group (optionally as team lead). */
    public function addMember(JobGroupMemberRequest $request, Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);

        $userId   = (int) $request->input('user_id');
        $asLead   = (bool) $request->input('is_team_lead', false);

        $already = $group->members()->where('user_id', $userId)->exists();
        if ($already) {
            return response()->json([
                'success' => false,
                'message' => 'User is already a member of this group',
            ], 422);
        }

        $group->members()->attach($userId, ['is_team_lead' => $asLead]);

        // If they were added as team lead, update the group's FK too
        if ($asLead) {
            $group->update(['team_lead_user_id' => $userId]);
            DB::table('event_job_group_members')
                ->where('event_job_group_id', $group->id)
                ->where('user_id', '!=', $userId)
                ->update(['is_team_lead' => false]);
        }

        $group->load(['members:id,name,email,type', 'teamLead:id,name,email']);

        return response()->json([
            'success' => true,
            'data'    => $group,
            'message' => 'Member added',
        ], 201);
    }

    /** Remove a member from the group. */
    public function removeMember(Event $event, JobGroup $group, int $userId): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);

        /** @var User $user */
        $user = User::findOrFail($userId);

        if (! $group->members()->where('user_id', $userId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this group',
            ], 422);
        }

        $group->members()->detach($userId);

        // If the removed user was the lead, clear the lead FK (new lead must be promoted separately)
        if ((int) $group->team_lead_user_id === $userId) {
            $group->update(['team_lead_user_id' => null]);
        }

        $group->load(['members:id,name,email,type', 'teamLead:id,name,email']);

        return response()->json([
            'success' => true,
            'data'    => $group,
            'message' => "{$user->name} removed from group",
        ]);
    }

    // ------------------------- Tasks ----------------------------------

    /** Get all tasks across all groups of an event. */
    public function indexTasks(Event $event): JsonResponse
    {
        $tasks = $event->jobTasks()
            ->with(['group', 'group.members:id,name,email'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tasks,
        ]);
    }

    /** Create a task inside the given group (assigned to the whole group). */
    public function storeTask(JobTaskRequest $request, Event $event, JobGroup $group): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);

        $validated = $request->validated();

        /** @var JobTask $task */
        $task = $group->tasks()->create([
            'event_id'           => $event->id,
            'title'              => $validated['title'],
            'instructions'       => $validated['instructions'] ?? null,
            'status'             => $validated['status'] ?? 'pending',
            'location'           => $validated['location'],
            'duration_minutes'   => $validated['duration_minutes'] ?? null,
        ]);

        $task->load(['group', 'group.members:id,name,email']);

        return response()->json([
            'success' => true,
            'data'    => $task,
            'message' => 'Task created',
        ], 201);
    }

    /** Update a task. */
    public function updateTask(JobTaskRequest $request, Event $event, JobGroup $group, JobTask $task): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);
        abort_unless($task->event_job_group_id === $group->id, 404, 'Task not found in group');

        $validated = $request->validated();

        $task->update([
            'event_job_group_id' => $validated['event_job_group_id'] ?? $group->id,
            'title'              => $validated['title'],
            'instructions'       => $validated['instructions'] ?? null,
            'status'             => $validated['status'] ?? $task->status,
            'location'           => $validated['location'],
            'duration_minutes'   => $validated['duration_minutes'] ?? null,
        ]);

        $task->load(['group', 'group.members:id,name,email']);

        return response()->json([
            'success' => true,
            'data'    => $task,
            'message' => 'Task updated',
        ]);
    }

    /** Delete a task. */
    public function destroyTask(Event $event, JobGroup $group, JobTask $task): JsonResponse
    {
        abort_unless($this->groupBelongsToEvent($event, $group), 404);
        abort_unless($task->event_job_group_id === $group->id, 404, 'Task not found in group');

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted',
        ]);
    }

    // ------------------------- Helpers --------------------------------

    /** Guard: confirm the group actually belongs to the event in the URL. */
    private function groupBelongsToEvent(Event $event, JobGroup $group): bool
    {
        return (int) $group->event_id === (int) $event->id;
    }
}
