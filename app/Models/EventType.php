<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    /** @use HasFactory<\Database\Factories\EventTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'parent_id',
        'image',
        'tag',
        'slug',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    // Relationships
    public function events()
    {
        return $this->hasMany(Event::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    // Accessors & Mutators
    public function getTypeNameAttribute()
    {
        return $this->title;
    }

    public function setTypeNameAttribute($value)
    {
        $this->attributes['title'] = $value;
    }
}
