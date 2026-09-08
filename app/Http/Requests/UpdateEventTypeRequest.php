<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventTypeRequest extends FormRequest
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
        $eventTypeId = $this->route('eventType')->id ?? null;
        
        return [
            'title' => 'sometimes|string|max:255|unique:event_types,title,' . $eventTypeId,
            'description' => 'sometimes|nullable|string|max:1000',
            'status' => 'sometimes|string|in:active,inactive',
        ];
    }

    public function messages(): array
    {
        return [
            'title.unique' => 'An event type with this title already exists.',
            'title.max' => 'The title may not be greater than 255 characters.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            'status.in' => 'The status must be either active or inactive.',
        ];
    }
}