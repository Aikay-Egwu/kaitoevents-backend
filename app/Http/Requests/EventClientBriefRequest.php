<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventClientBriefRequest extends FormRequest
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
            'event_id' => 'required|exists:events,id',
            'client_background' => 'required|string',
            'occasion_meaning' => 'required|string',
            'client_words_brief' => 'required|string',
            'non_negotiables' => 'required|string',
            'what_to_avoid' => 'required|string',
            'cultural_religious_requirements' => 'required|string',
            'personal_significance_motifs' => 'required|string',
            'agreed_total_budget' => 'required|numeric',
            'kaito_events_fee' => 'required|numeric',
        ];
    }
}
