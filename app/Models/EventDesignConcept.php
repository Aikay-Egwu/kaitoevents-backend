<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventDesignConcept — The creative blueprint for an event.
 *
 * Stores the concept identity (name, direction, narrative), a six-slot colour
 * palette, a six-dimensional aesthetic direction, and free-form reference
 * notes. Each event has at most one design concept (hasOne relationship).
 *
 * JSON columns:
 *   - color_palette       → { primary, secondary, accent, metal, neutral, avoid }
 *   - aesthetic_direction  → { overall_style, texture, floral_style, lighting_mood, formality, guest_feeling }
 *
 * @property int    $id
 * @property int    $event_id
 * @property string|null $concept_name
 * @property string|null $design_direction
 * @property string|null $concept_narrative
 * @property string|null $key_reference_notes
 * @property array|null  $color_palette
 * @property array|null  $aesthetic_direction
 */
class EventDesignConcept extends Model
{
    protected $fillable = [
        'event_id',
        'concept_name',
        'design_direction',
        'concept_narrative',
        'key_reference_notes',
        'color_palette',
        'aesthetic_direction',
    ];

    protected $casts = [
        'color_palette' => 'array',
        'aesthetic_direction' => 'array',
    ];

    /**
     * Get the event that owns the design concept.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
