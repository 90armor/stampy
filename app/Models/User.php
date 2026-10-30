<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\DisplayDate;
use Carbon\CarbonInterface;
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
     * The last day of employment, once it has passed — the account has no
     * access from the day after (Phase 6b). left_on is the last working day,
     * so they can still sign in on it. Null for a current employee and for a
     * user with no employee record (an unlinked admin is never cut off).
     * Reads the employee relation, cached on this instance, so the sign-in
     * check and EnsureStillEmployed cost at most the one query the sidebar
     * makes anyway.
     */
    public function employmentEndedOn(): ?CarbonInterface
    {
        $leftOn = $this->employee?->left_on;

        return $leftOn !== null && $leftOn->lt(today()) ? $leftOn : null;
    }

    /** What a leaver is told at sign-in, and when their open session is ended. */
    public static function employmentEndedMessage(CarbonInterface $leftOn): string
    {
        return 'Your employment ended on '.DisplayDate::compact($leftOn).'. Contact HR if you need access.';
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
