<?php

namespace App\Livewire\Profile;

use App\Models\Employee;
use App\Models\User;
use App\Support\ScheduleSentence;
use App\Support\Tenure;
use App\Support\UserSessions;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * The signed-in user's own profile (Phase 6i): read-only, with each change in
 * a modal. Everyone may change their password; only an admin may change their
 * own email, and their name only while no employee record is linked
 * (UserPolicy::updateAccount). Everyone else asks HR.
 */
class Show extends Component
{
    public bool $showPasswordModal = false;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $showAccountModal = false;

    public string $name = '';

    public string $email = '';

    public ?string $notice = null;

    public function openPassword(): void
    {
        $this->reset(['current_password', 'password', 'password_confirmation', 'notice']);
        $this->resetErrorBag();
        $this->showPasswordModal = true;
    }

    /**
     * The same rules and messages as every other password change, plus the
     * user's other sessions signed out: a password changed because it leaked
     * should end whatever the leak let in. This browser stays signed in, and
     * keeps its "remember me" if it had one.
     */
    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $user->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'temporary_password_expires_at' => null,
        ])->save();

        UserSessions::end($user, session()->getId());

        $recaller = Auth::guard()->getRecallerName();
        if (request()->hasCookie($recaller)) {
            Cookie::queue(Cookie::forever($recaller, $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword()));
        }

        $this->reset(['current_password', 'password', 'password_confirmation', 'showPasswordModal']);
        $this->notice = "Password changed. You've been signed out on other devices.";
    }

    public function openAccount(): void
    {
        $user = Auth::user();
        $this->authorize('updateAccount', $user);

        $this->name = $user->name;
        $this->email = (string) $user->email;
        $this->notice = null;
        $this->resetErrorBag();
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->authorize('updateAccount', $user);

        $linked = $user->employee !== null;

        $this->email = strtolower(trim($this->email));

        $data = $this->validate(array_filter([
            // A linked login's name is the employee record's, so it isn't a
            // field here and nothing submitted for it is read.
            'name' => $linked ? null : ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]));

        $user->forceFill(array_filter([
            'name' => $linked ? null : $data['name'],
        ]) + ['email' => $data['email'] !== '' ? $data['email'] : null])->save();

        $this->showAccountModal = false;
        $this->notice = 'Account saved.';
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $employee = $user->employee?->load(['department', 'position', 'manager']);

        return view('livewire.profile.show', [
            'user' => $user,
            'employee' => $employee,
            'canEditAccount' => $user->can('updateAccount', $user),
            'tenure' => $employee ? Tenure::label($employee->join_date, $employee->left_on && $employee->left_on->lt(today()) ? $employee->left_on : today()) : null,
            'schedule' => $employee ? $this->schedule($employee) : null,
        ])->layout('layouts.app', ['header' => 'Profile']);
    }

    /**
     * The schedule in force today, in words, and the next change if one is
     * already assigned.
     *
     * @return array{today: string, next: ?array{from: CarbonInterface, text: string}}|null
     */
    private function schedule(Employee $employee): ?array
    {
        $assignments = $employee->scheduleAssignments()->with('workSchedule')->orderBy('effective_from')->get();

        if ($assignments->isEmpty()) {
            return null;
        }

        $current = $employee->scheduleOn(today());
        $next = $assignments->first(fn ($assignment) => $assignment->effective_from->gt(today()));

        return [
            'today' => ScheduleSentence::for($current),
            'next' => $next && $next->work_schedule_id !== $current->id
                ? ['from' => $next->effective_from, 'text' => ScheduleSentence::for($next->workSchedule)]
                : null,
        ];
    }
}
