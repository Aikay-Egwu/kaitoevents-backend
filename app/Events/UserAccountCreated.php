<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UserAccountCreated event — fired when a new admin user account is created.
 *
 * Carries the User model and the plain-text password so the listener
 * can include the temporary password in the welcome email.
 */
class UserAccountCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The newly created user.
     */
    public User $user;

    /**
     * The plain-text password (before hashing).
     */
    public string $plainTextPassword;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, string $plainTextPassword)
    {
        $this->user = $user;
        $this->plainTextPassword = $plainTextPassword;
    }
}
