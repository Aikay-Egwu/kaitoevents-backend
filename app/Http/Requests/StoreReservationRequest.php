<?php

namespace App\Http\Requests;

use App\Http\Requests\AppRequest;

class StoreReservationRequest extends AppRequest
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
            'prop_id'      => ['required','integer','exists:props,id'],
            'customer_id'  => ['nullable','integer','exists:customers,id'],
            'quantity'     => ['required','integer','min:1'],
            'start_date'   => ['required','date'],
            'end_date'     => ['required','date','after_or_equal:start_date'],
            'status'       => ['nullable','in:tentative,confirmed,cancelled,fulfilled'],
            'notes'        => ['nullable','string'],
        ];
    }
}
