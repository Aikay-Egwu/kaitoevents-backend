<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOption extends Model
{
    protected $fillable = ['option_name', 'option_type', 'option_price', 'choices', 'is_required'];

    protected $casts = [
        'choices' => 'array', // Automatically handles JSON encoding/decoding
        'is_required' => 'boolean',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
