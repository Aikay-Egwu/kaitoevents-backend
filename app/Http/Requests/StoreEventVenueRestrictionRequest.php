<?php

namespace App\Http\Requests;

/**
 * StoreEventVenueRestrictionRequest — Validates the creation of a venue restriction
 * for an event. Inherits failedValidation() from AppRequest so errors are returned
 * as a structured JSON response instead of a redirect.
 */
class StoreEventVenueRestrictionRequest extends AppRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id'            => 'required|exists:events,id',
            'restriction_type_id' => 'required|exists:restriction_types,id',
            'venue_position'      => 'nullable|string|max:255',
            'impact_on_design'    => 'nullable|string',
        ];
    }
}
