<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventVenueRestrictions — Pivot linking events to restriction types.
 *
 * Stores the venue_position and impact_on_design for each restriction
 * flagged during the venue walkthrough.
 *
 * Table: event_venue_restrictions
 */
class EventVenueRestrictions extends Model
{
    protected $table = 'event_venue_restrictions';

    protected $fillable = [
        'event_id',
        'restriction_type_id',
        'venue_position',
        'impact_on_design',
    ];

    /**
     * The event this restriction belongs to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The restriction type (e.g. "Open flames").
     */
    public function restrictionType(): BelongsTo
    {
        return $this->belongsTo(RestrictionType::class);
    }
}
