<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookConsultationRequest extends FormRequest
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
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'event_type_id' => 'required|exists:event_types,id',
            'event_date' => 'required|date',
            'guest_number' => 'required|integer|min:1',
            'event_time' => 'nullable|date_format:H:i',
            'budget' => 'nullable|numeric|min:0',
            'venue_name' => 'required|string|max:255',
            'venue_address' => 'required|string|max:255',
        ];
    }
}