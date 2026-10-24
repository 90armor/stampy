<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * php artisan user:create-admin (Phase 5a): the supported way to create an
 * admin. Every refusal writes nothing; a success is one user with the admin
 * role, optionally linked to an employee record.
 */
class UserCreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL_PROMPT = 'Email (optional, press Enter to skip)';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    /** @param array{name?: string, username?: string, email?: string, password?: string, again?: string} $answers */
    private function createAdmin(array $answers = [], array $options = []): PendingCommand
    {
        $answers += ['name' => 'Sophea Chan', 'username' => 'sophea.admin', 'email' => '', 'password' => 'correct-horse-9', 'again' => null];

        return $this->artisan('user:create-admin', $options)
            ->expectsQuestion('Name', $answers['name'])
            ->expectsQuestion('Username', $answers['username'])
            ->expectsQuestion(self::EMAIL_PROMPT, $answers['email'])
            ->expectsQuestion('Password', $answers['password'])
            ->expectsQuestion('Password again', $answers['again'] ?? $answers['password']);
    }

    /** What a refusal must leave untouched. */
    private function snapshot(): array
    {
        return [
            'users' => User::query()->orderBy('id')->pluck('username')->all(),
            'roles' => DB::table('model_has_roles')->count(),
            'links' => Employee::query()->orderBy('id')->pluck('user_id', 'id')->all(),
        ];
    }

    public function test_it_creates_an_admin_who_can_log_in_with_the_username(): void
    {
        $this->createAdmin(['email' => ''])
            ->expectsOutputToContain('Created admin Sophea Chan — username "sophea.admin", no email.')
            ->assertSuccessful();

        $user = User::query()->where('username', 'sophea.admin')->sole();
        $this->assertSame('Sophea Chan', $user->name);
        $this->assertNull($user->email);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame(['admin'], $user->getRoleNames()->all());
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->employee);

        $this->post('/login', ['email' => 'sophea.admin', 'password' => 'correct-horse-9'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_it_links_an_employee_and_the_name_mirrors_the_employee_record(): void
    {
        $employee = Employee::factory()->create(['employee_code' => 'EMP-0100', 'full_name' => 'Chan Sophea']);

        $this->createAdmin(['name' => 'Sophea Chan', 'email' => 'sophea@company.test'], ['--employee' => 'EMP-0100'])
            ->expectsOutputToContain('Created admin Chan Sophea — username "sophea.admin", email sophea@company.test.')
            ->expectsOutputToContain('Linked to Chan Sophea (EMP-0100).')
            ->expectsOutputToContain('The account\'s name is "Chan Sophea", not "Sophea Chan" as typed')
            ->assertSuccessful();

        $user = User::query()->where('username', 'sophea.admin')->sole();
        $this->assertSame('Chan Sophea', $user->name);
        $this->assertSame('sophea@company.test', $user->email);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($employee->fresh()->user->is($user));
    }

    public function test_a_linked_name_typed_as_on_the_record_gets_no_note(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0100', 'full_name' => 'Chan Sophea']);

        $this->createAdmin(['name' => 'Chan Sophea'], ['--employee' => 'EMP-0100'])
            ->doesntExpectOutputToContain('as typed')
            ->assertSuccessful();
    }

    public function test_it_refuses_without_an_admin_role_and_writes_nothing(): void
    {
        Role::query()->where('name', 'admin')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $before = $this->snapshot();

        $this->artisan('user:create-admin')
            ->expectsOutputToContain('There is no admin role yet. Run the configuration seeders first — see docs/GO_LIVE.md, step 2.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_refuses_a_taken_username_or_email_and_writes_nothing(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@company.test']);
        $before = $this->snapshot();

        $this->createAdmin(['username' => 'taken'])
            ->expectsOutputToContain('The username "taken" is already taken.')
            ->expectsOutputToContain('Nothing was created.')
            ->assertFailed();
        $this->createAdmin(['email' => 'taken@company.test'])
            ->expectsOutputToContain('The email "taken@company.test" is already taken.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_refuses_an_unknown_employee_or_one_with_a_login_and_writes_nothing(): void
    {
        $linked = Employee::factory()->create([
            'employee_code' => 'EMP-0200',
            'full_name' => 'Has Login',
            'user_id' => User::factory()->create()->id,
        ]);
        $before = $this->snapshot();

        $this->artisan('user:create-admin', ['--employee' => 'EMP-9999'])
            ->expectsOutputToContain('No employee found with code "EMP-9999".')
            ->assertFailed();
        $this->artisan('user:create-admin', ['--employee' => 'EMP-0200'])
            ->expectsOutputToContain('Has Login (EMP-0200) already has a login.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertNotNull($linked->fresh()->user_id);
    }

    public function test_it_refuses_a_password_that_fails_the_app_rules_or_doesnt_match(): void
    {
        $before = $this->snapshot();

        // Password::defaults(), the same rule the password-change forms use.
        $this->createAdmin(['password' => 'short'])
            ->expectsOutputToContain('The password field must be at least 8 characters.')
            ->assertFailed();
        $this->createAdmin(['password' => 'correct-horse-9', 'again' => 'correct-horse-8'])
            ->expectsOutputToContain("The two passwords don't match.")
            ->assertFailed();
        // Nothing answered (e.g. --no-interaction).
        $this->createAdmin(['name' => '', 'username' => ''])
            ->expectsOutputToContain('The name field is required.')
            ->expectsOutputToContain('The username field is required.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_runs_in_production(): void
    {
        // Its purpose is the first admin of a real install (docs/GO_LIVE.md).
        $this->app['env'] = 'production';
        $this->assertTrue(app()->isProduction());

        $this->createAdmin()->assertSuccessful();

        $this->assertTrue(User::query()->where('username', 'sophea.admin')->sole()->hasRole('admin'));
    }

    public function test_it_never_edits_an_existing_account(): void
    {
        $existing = User::factory()->create(['username' => 'sophea.admin', 'name' => 'Before']);
        $existing->assignRole('employee');

        $this->createAdmin()->assertFailed();

        $existing->refresh();
        $this->assertSame('Before', $existing->name);
        $this->assertSame(['employee'], $existing->getRoleNames()->all());
    }
}
