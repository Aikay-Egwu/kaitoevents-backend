<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AestheticDimension extends Model
{
    protected $fillable = [
        'name',
        'placeholder',
        'display_order',
    ];

    public function eventAestheticSelections()
    {
        return $this->hasMany(EventAestheticSelection::class);
    }
}
