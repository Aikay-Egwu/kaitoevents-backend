<?php

namespace App\Http\Requests;

use App\Http\Requests\AppRequest;

class UpdateReservationRequest extends AppRequest
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
            'prop_id'      => ['sometimes','integer','exists:props,id'],
            'customer_id'  => ['sometimes','nullable','integer','exists:customers,id'],
            'quantity'     => ['sometimes','integer','min:1'],
            'start_date'   => ['sometimes','date'],
            'end_date'     => ['sometimes','date','after_or_equal:start_date'],
            'status'       => ['sometimes','in:tentative,confirmed,cancelled,fulfilled'],
            'notes'        => ['sometimes','nullable','string'],
        ];
    }
}
