<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class AppRequest extends FormRequest
{
    public function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();
        $list = [];
        foreach ($errors as $value) {
            if(is_array($value)) {
                foreach ($value as $item) {
                    $list[] = $item;
                }
            }
        }

        throw new HttpResponseException(
            response()->json(['status' => 0, 'message' => $list], Response::HTTP_BAD_REQUEST)
        );
    }

}
