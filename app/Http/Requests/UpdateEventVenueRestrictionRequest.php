<?php

namespace App\Http\Requests;

/**
 * UpdateEventVenueRestrictionRequest — Validates updates to an existing venue restriction.
 * All fields are "sometimes" so partial updates are supported.
 */
class UpdateEventVenueRestrictionRequest extends AppRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restriction_type_id' => 'sometimes|exists:restriction_types,id',
            'venue_position'      => 'nullable|string|max:255',
            'impact_on_design'    => 'nullable|string',
        ];
    }
}
