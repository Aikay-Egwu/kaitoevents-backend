<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RestrictionType — Predefined venue restriction categories.
 *
 * Seed examples: Open flames, fixing to walls, confetti/glitter, etc.
 * Used in the event_venue_restrictions pivot to flag known concerns
 * with a venue and capture their position and design impact.
 */
class RestrictionType extends Model
{
    protected $fillable = ['name', 'slug', 'description'];
}
