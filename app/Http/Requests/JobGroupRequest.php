<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates create / update payloads for a JobGroup.
 *
 * Body shape (members + tasks are optional arrays accepted on create for bulk
 * initial population; they're ignored on pure name/lead updates since member
 * and task management uses their own dedicated endpoints):
 * {
 *   name: "Florist Team",
 *   team_lead_user_id: 3,                 | required:must exist in users
 *   description?: "Handles all décor...",
 *   member_ids?: [1, 2, 3],                | optional array of user ids
 *   tasks?: [{title,location,status?,duration_minutes?,instructions?}]
 * }
 * event_id is authoritative from route parameter (JobController merges it).
 */
class JobGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name'               => 'required|string|max:255',
            'team_lead_user_id'  => 'required|exists:users,id',
            'description'        => 'sometimes|nullable|string',
            'member_ids'         => 'sometimes|array',
            'member_ids.*'       => 'exists:users,id',
            'tasks'              => 'sometimes|array',
            'tasks.*.title'      => 'required_with:tasks|string|max:255',
            'tasks.*.instructions' => 'sometimes|nullable|string',
            'tasks.*.status'     => 'sometimes|in:pending,in_progress,completed,blocked',
            'tasks.*.location'   => 'required_with:tasks|in:on_site,in_house',
            'tasks.*.duration_minutes' => 'sometimes|nullable|integer|min:1',
        ];
    }
}
