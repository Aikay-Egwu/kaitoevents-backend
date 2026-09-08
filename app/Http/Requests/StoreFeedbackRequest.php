<?php

namespace App\Http\Requests;

use App\Models\Feedback;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * The store endpoint is public so any visitor may submit feedback;
     * authorization for admin management is handled by middleware.
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
        return Feedback::rules();
    }

    public function messages(): array
    {
        $messages = [
            'client_name.required' => 'Please tell us your name.',
            'client_name.max' => 'The client name may not be greater than 255 characters.',
            'event_date.required' => 'Please provide the date of your event.',
            'event_date.date' => 'The event date must be a valid date.',
            'event_date.before_or_equal' => 'The event date cannot be in the future.',
            'event_type_id.required' => 'Please select the type of your event.',
            'event_type_id.exists' => 'The selected event type does not exist.',
            'experience_comparison.required' => 'Please tell us how the experience compared to your expectations.',
            'experience_comparison.in' => 'The selected comparison value is invalid.',
            'likely_to_return.required' => 'Please tell us whether you are likely to use us again.',
            'likely_to_return.boolean' => 'The answer to whether you are likely to return must be yes or no.',
            'would_recommend.required' => 'Please tell us whether you would recommend us.',
            'would_recommend.boolean' => 'The answer to whether you would recommend us must be yes or no.',
            'unmet_expectations.max' => 'The unmet expectations note may not be greater than 2000 characters.',
            'stood_out.max' => 'The highlights note may not be greater than 2000 characters.',
            'client_id.exists' => 'The selected client does not exist.',
            'event_id.exists' => 'The selected event does not exist.',
        ];

        foreach (Feedback::RATING_FIELDS as $field) {
            $messages["{$field}.required"] = 'Please rate this aspect from 1 to 6.';
            $messages["{$field}.integer"] = 'The rating must be a whole number between 1 and 6.';
            $messages["{$field}.between"] = 'The rating must be between 1 and 6.';
        }

        return $messages;
    }
}
