<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAestheticSelection extends Model
{
    protected $fillable = [
        'event_id',
        'aesthetic_dimension_id',
        'design_direction',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function aestheticDimension()
    {
        return $this->belongsTo(AestheticDimension::class);
    }
}
