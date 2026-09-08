<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates member management requests against a JobGroup.
 *
 * Add:    POST    /admin/events/{event}/groups/{group}/members      { user_id }
 * Remove: DELETE  /admin/events/{event}/groups/{group}/members/{userId}
 *
 * Also supports `is_team_lead` promotion flag in the pivot.
 */
class JobGroupMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'user_id'      => 'required|exists:users,id',
            'is_team_lead' => 'sometimes|boolean',
        ];
    }
}
