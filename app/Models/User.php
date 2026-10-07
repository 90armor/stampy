<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function passwordResetBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'password_reset_by');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * True once a temporary password (set by resetPassword()) has passed
     * its 48h window — checked against the stored timestamp itself, not
     * inferred from updated_at, which any unrelated row change would reset.
     */
    public function hasExpiredTemporaryPassword(): bool
    {
        return $this->must_change_password
            && $this->temporary_password_expires_at !== null
            && $this->temporary_password_expires_at->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'temporary_password_expires_at' => 'datetime',
            'time_off_seen_at' => 'datetime',
            'overtime_seen_at' => 'datetime',
        ];
    }
}
