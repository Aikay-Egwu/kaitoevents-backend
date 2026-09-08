<?php

namespace App\Http\Requests;

use App\Models\Feedback;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization will be handled by the auth:sanctum middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * All fields are optional on update so admins can partially
     * update a feedback record (e.g. triage status only).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return collect(Feedback::rules())
            ->map(function (string $rule) {
                return str_starts_with($rule, 'required|')
                    ? 'sometimes|' . substr($rule, strlen('required|'))
                    : $rule;
            })
            ->all();
    }

    public function messages(): array
    {
        return [
            'event_date.before_or_equal' => 'The event date cannot be in the future.',
            'status.in' => 'The status must be one of: new, reviewed, archived.',
        ];
    }
}
