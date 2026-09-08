<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventClientBrief extends Model
{
    protected $fillable = [
        'event_id',
        'client_background',
        'occasion_meaning',
        'client_words_brief',
        'non_negotiables',
        'what_to_avoid',
        'cultural_religious_requirements',
        'personal_significance_motifs',
        'agreed_total_budget',
        'kaito_events_fee',
    ];

    protected $casts = [
        // Cast financial data to floats/decimals for easier math operations
        'agreed_total_budget' => 'decimal:2',
        'kaito_events_fee' => 'decimal:2',
    ];

    /**
     * Get the event that owns the client brief.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
