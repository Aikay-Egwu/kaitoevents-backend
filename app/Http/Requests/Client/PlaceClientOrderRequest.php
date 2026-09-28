<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class PlaceClientOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_full_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:40', 'regex:/^[+\d\s()\-]{10,18}$/'],
            'billing_address_line_1' => ['required', 'string', 'max:200'],
            'billing_address_line_2' => ['nullable', 'string', 'max:200'],
            'billing_city' => ['required', 'string', 'max:120'],
            'billing_postcode' => ['required', 'string', 'max:20', 'regex:/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i'],
            'billing_country' => ['required', 'string', 'max:80'],
            'event_date' => ['nullable', 'date'],
            'event_venue' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'in:card,bank_transfer,on_arrival'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
