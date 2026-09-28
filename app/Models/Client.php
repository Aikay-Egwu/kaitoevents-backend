<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    protected $appends = ['name'];

    public function getNameAttribute(): string
    {
        return trim($this->firstname . ' ' . $this->lastname);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function shopBasket(): HasOne
    {
        return $this->hasOne(ShopClientBasket::class)->where('status', 'open');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class);
    }
}
