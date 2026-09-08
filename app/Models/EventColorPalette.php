<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventColorPalette extends Model
{
    
    protected $fillable = [
        'event_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'metal_color',
        'neutral_color',
        'avoid_color',
        'color_palette_notes',

    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
