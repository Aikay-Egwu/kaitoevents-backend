<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Groups where this user is designated the Team Lead (via job_groups.team_lead_user_id).
     */
    public function leadingGroups()
    {
        return $this->hasMany(JobGroup::class, 'team_lead_user_id');
    }

    /**
     * Groups that the user is a member of (through pivot event_job_group_members).
     */
    public function jobGroupMemberships()
    {
        return $this->belongsToMany(
            JobGroup::class,
            'event_job_group_members',
            'user_id',
            'event_job_group_id',
        )
            ->withPivot('is_team_lead')
            ->withTimestamps();
    }
}
