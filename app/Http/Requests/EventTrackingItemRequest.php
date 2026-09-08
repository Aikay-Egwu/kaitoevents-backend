<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EventTrackingItemRequest — Validates create/update payloads for tracking items.
 *
 * Enforces:
 *  - category in [task, item], status in [done, undone, pending]
 *  - assigned_to must exist as a user id (Admin/Manager/Staff)
 *  - task_description required, non-empty string
 *  - additional_notes: optional, nullable text
 *  - event_id: SOMETIMES exists in events table if supplied in body.
 *      NOTE: The authoritative event_id always comes from the route parameter
 *      (events/{event}/tracking-items) and the Controller force-merges it into
 *      the data. This mirrors the VendorsTab pattern where the body does NOT
 *      include event_id — while still supporting the ClientBriefTab pattern
 *      where it IS included in the body for redundancy.
 */
class EventTrackingItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id'          => 'sometimes|exists:events,id',
            'task_description'  => 'required|string',
            'category'          => 'required|in:task,item',
            'status'            => 'sometimes|in:done,undone,pending',
            'additional_notes'  => 'sometimes|nullable|string',
            'assigned_to'       => 'required|exists:users,id',
        ];
    }
}
