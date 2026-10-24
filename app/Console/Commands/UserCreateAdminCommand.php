<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * The supported way to create an admin, the first one included (Phase 5a;
 * docs/GO_LIVE.md, step 3). Interactive: name, username, an optional email
 * and a password typed twice, checked by the app's own password rules
 * (Password::defaults(), as the password-change forms use). --employee links
 * the account to an existing employee record, whose full_name the account's
 * name then mirrors (the users/employees rule in CLAUDE.md).
 *
 * Runs in production — that's its purpose. Every refusal happens before
 * anything is written, and the user, its role and the employee link are one
 * transaction. It never edits an existing account: promoting a user stays in
 * the employee form.
 */
class UserCreateAdminCommand extends Command
{
    protected $signature = 'user:create-admin
        {--employee= : Link the account to the employee with this employee_code}';

    protected $description = 'Create an admin account, optionally linked to an employee record.';

    public function handle(): int
    {
        if (! Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            return $this->refuse('There is no admin role yet. Run the configuration seeders first — see docs/GO_LIVE.md, step 2.');
        }

        $employee = null;
        $code = $this->option('employee');

        if ($code !== null) {
            $employee = Employee::query()->where('employee_code', $code)->first();

            if ($employee === null) {
                return $this->refuse("No employee found with code \"{$code}\".");
            }

            if ($employee->user_id !== null) {
                return $this->refuse("{$employee->full_name} ({$employee->employee_code}) already has a login. Change its role in the employee form instead.");
            }
        }

        $input = [
            'name' => trim((string) $this->ask('Name', $employee?->full_name)),
            'username' => trim((string) $this->ask('Username')),
            'email' => trim((string) $this->ask('Email (optional, press Enter to skip)')) ?: null,
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Password again'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'username.unique' => 'The username ":input" is already taken.',
            'email.unique' => 'The email ":input" is already taken.',
            'password.confirmed' => "The two passwords don't match.",
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return $this->refuse('Nothing was created.', asError: false);
        }

        // A linked account's name is the employee record's (CLAUDE.md, User/HR data split).
        $name = $employee?->full_name ?? $input['name'];

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $name,
                'username' => $input['username'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            // The operator chose the password, so there's nothing to change at first login.
            $user->forceFill(['must_change_password' => false])->save();
            $user->assignRole('admin');

            if ($employee !== null) {
                $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);

                if ($locked->user_id !== null) {
                    // Given a login while the prompts were open.
                    DB::rollBack();

                    return $this->refuse("{$employee->full_name} ({$employee->employee_code}) was given a login meanwhile. Nothing was created.");
                }

                $locked->update(['user_id' => $user->id]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->info("Created admin {$user->name} — username \"{$user->username}\"".($user->email ? ", email {$user->email}" : ', no email').'.');

        if ($employee !== null) {
            $this->line("Linked to {$employee->full_name} ({$employee->employee_code}).");

            if ($input['name'] !== $employee->full_name) {
                $this->warn("The account's name is \"{$employee->full_name}\", not \"{$input['name']}\" as typed: a linked account's name mirrors its employee record. Change it on the employee record.");
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message, bool $asError = true): int
    {
        $asError ? $this->error($message) : $this->line($message);

        return self::FAILURE;
    }
}
