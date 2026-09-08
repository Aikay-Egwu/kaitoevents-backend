<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates create / update payloads for individual JobTasks.
 */
class JobTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'event_job_group_id' => 'sometimes|exists:event_job_groups,id',
            'title'              => 'required|string|max:255',
            'instructions'       => 'sometimes|nullable|string',
            'status'             => 'sometimes|in:pending,in_progress,completed,blocked',
            'location'           => 'required|in:on_site,in_house',
            'duration_minutes'   => 'sometimes|nullable|integer|min:1',
        ];
    }
}
