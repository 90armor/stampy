<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Livewire\Employees\FormModal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F15 — sign in with a username, an employee code or an email
 * (LoginIdentifier): trimmed, any case, looked up in that order, and never a
 * guess between two people. Forgot password takes the same identifiers; the
 * throttle counts per person, whichever identifier they type; a leaver is
 * refused by code as by username; and a username and another person's
 * employee code can't be made equal (UsernameCodeCollision). The clock is Wed
 * 21 Oct 2026 10:00.
 */
class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG = 'Incorrect username, employee code or password.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    /** Kyaw Kyaw Naing: username kyaw.kyaw.naing, code EMP-0002, an email. */
    private function kyaw(array $employee = []): User
    {
        $user = User::factory()->create(['username' => 'kyaw.kyaw.naing', 'email' => 'kyaw@example.com', 'password' => 'password'])->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id, 'employee_code' => 'EMP-0002', 'join_date' => '2024-02-01', ...$employee]);

        return $user;
    }

    public static function identifiers(): array
    {
        return [
            'username' => ['kyaw.kyaw.naing'],
            'username, other case and spaces' => ['  Kyaw.Kyaw.Naing '],
            'employee code' => ['EMP-0002'],
            'employee code, lower case and spaces' => [' emp-0002  '],
            'email' => ['kyaw@example.com'],
            'email, other case' => ['KYAW@Example.com'],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_any_identifier_signs_the_same_person_in(string $identifier): void
    {
        $kyaw = $this->kyaw();

        $this->post('/login', ['email' => $identifier, 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($kyaw);
    }

    #[DataProvider('identifiers')]
    public function test_any_identifier_with_a_wrong_password_gets_the_one_message(string $identifier): void
    {
        $this->kyaw();

        $this->post('/login', ['email' => $identifier, 'password' => 'wrong'])->assertSessionHasErrors(['auth' => self::WRONG]);

        $this->assertGuest();
    }

    public function test_the_form_asks_for_a_username_or_employee_code(): void
    {
        $this->get('/login')
            ->assertSee('Username or employee code')
            ->assertSee('placeholder="Username or employee code"', false)
            ->assertDontSee('Email or username');
    }

    public function test_the_code_of_an_employee_with_no_login_signs_nobody_in(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0009']);

        $this->post('/login', ['email' => 'EMP-0009', 'password' => 'password'])->assertSessionHasErrors(['auth' => self::WRONG]);

        $this->assertGuest();
    }

    public function test_an_identifier_naming_two_people_signs_neither_in_and_is_logged(): void
    {
        // Data from before collisions were refused: one person's username is
        // another's employee code.
        $kyaw = $this->kyaw();
        $other = User::factory()->create(['username' => 'other', 'password' => 'password'])->assignRole('employee');
        DB::table('users')->where('id', $other->id)->update(['username' => 'emp-0002']);
        Log::spy();

        $this->post('/login', ['email' => 'EMP-0002', 'password' => 'password'])->assertSessionHasErrors(['auth' => self::WRONG]);

        $this->assertGuest();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'names more than one user')
            && str_contains($message, "username: user #{$other->id}") && str_contains($message, "employee code: user #{$kyaw->id}"));
    }

    public function test_the_throttle_counts_per_person_whichever_identifier_they_type(): void
    {
        $this->kyaw();

        foreach (['kyaw.kyaw.naing', 'EMP-0002', 'kyaw@example.com', 'emp-0002', 'KYAW.KYAW.NAING'] as $identifier) {
            $this->post('/login', ['email' => $identifier, 'password' => 'wrong'])->assertSessionHasErrors(['auth' => self::WRONG]);
        }

        // Five wrong passwords, five spellings: the sixth try is refused, even
        // with the right password, whichever identifier it uses.
        $this->post('/login', ['email' => 'EMP-0002', 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertGuest();
    }

    public function test_a_leaver_is_refused_by_code_as_by_username(): void
    {
        $this->kyaw(['status' => 'inactive', 'left_on' => '2026-10-16']);

        $this->post('/login', ['email' => 'emp-0002', 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => 'Your employment ended on Fri 16 Oct. Contact HR if you need access.']);

        $this->assertGuest();
    }

    #[DataProvider('identifiers')]
    public function test_forgot_password_takes_the_same_identifiers(string $identifier): void
    {
        Notification::fake();
        $kyaw = $this->kyaw();

        $this->post('/forgot-password', ['email' => $identifier])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', PasswordResetLinkController::SENT);

        Notification::assertSentTo($kyaw, ResetPassword::class);
    }

    public function test_forgot_password_sends_nothing_for_an_identifier_naming_two_people(): void
    {
        Notification::fake();
        $this->kyaw();
        $other = User::factory()->create(['username' => 'other', 'email' => 'other@example.com'])->assignRole('employee');
        DB::table('users')->where('id', $other->id)->update(['username' => 'EMP-0002']);

        $this->post('/forgot-password', ['email' => 'EMP-0002'])->assertSessionHas('status', PasswordResetLinkController::SENT);

        Notification::assertNothingSent();
    }

    // ── Collisions: a username and another person's employee code ────────

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /** The employee form, filled for a new employee. */
    private function newEmployeeForm(string $code)
    {
        return Livewire::actingAs($this->admin())->test(FormModal::class)
            ->call('create')
            ->set('full_name', 'Sophea Chan')
            ->set('employee_code', $code)
            ->set('department_id', Department::factory()->create()->id)
            ->set('position_id', Position::factory()->create()->id)
            ->set('join_date', '2026-10-01');
    }

    public function test_a_new_username_that_is_another_employees_code_is_refused(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0031', 'full_name' => 'Aung Kyaw Moe']);

        $this->newEmployeeForm('EMP-0101')
            ->set('create_user', true)
            ->set('username', 'emp-0031')
            ->set('email', 'sophea@example.com')
            ->call('save')
            ->assertHasErrors(['username']);

        $this->assertNull(User::query()->where('email', 'sophea@example.com')->first());
    }

    public function test_a_username_may_be_the_employees_own_code(): void
    {
        $this->newEmployeeForm('EMP-0101')
            ->set('create_user', true)
            ->set('username', 'EMP-0101')
            ->set('email', 'sophea@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('EMP-0101', User::query()->where('email', 'sophea@example.com')->value('username'));
    }

    public function test_a_new_employee_code_that_is_another_users_username_is_refused(): void
    {
        $this->kyaw();

        $page = $this->newEmployeeForm('Kyaw.Kyaw.Naing')->call('save')->assertHasErrors(['employee_code']);

        $this->assertStringEndsWith("'s username, and people sign in with either. Choose a different employee code.", $page->errors()->first('employee_code'));
        $this->assertNull(Employee::query()->where('full_name', 'Sophea Chan')->first());
    }

    public function test_editing_a_code_into_another_users_username_is_refused_but_keeping_ones_own_is_not(): void
    {
        $kyaw = $this->kyaw();
        $other = User::factory()->create(['username' => 'su.su', 'name' => 'Su Su Hlaing'])->assignRole('employee');
        $employee = $kyaw->employee;

        $page = Livewire::actingAs($this->admin())->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('employee_code', 'SU.SU')
            ->call('save')
            ->assertHasErrors(['employee_code']);
        $this->assertSame('"SU.SU" is Su Su Hlaing\'s username, and people sign in with either. Choose a different employee code.', $page->errors()->first('employee_code'));
        $this->assertSame('EMP-0002', $employee->fresh()->employee_code);

        // Their own username as their code is fine.
        Livewire::actingAs($this->admin())->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('employee_code', 'kyaw.kyaw.naing')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('kyaw.kyaw.naing', $employee->fresh()->employee_code);
        $this->assertNotNull($other->fresh());
    }

    public function test_giving_an_existing_employee_a_login_checks_the_username_too(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0031', 'full_name' => 'Aung Kyaw Moe']);
        $employee = Employee::factory()->create(['employee_code' => 'EMP-0040']);

        $page = Livewire::actingAs($this->admin())->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('create_user', true)
            ->set('username', 'EMP-0031')
            ->set('email', 'new@example.com')
            ->call('save')
            ->assertHasErrors(['username']);

        $this->assertSame('"EMP-0031" is Aung Kyaw Moe\'s employee code, and people sign in with either. Choose a different username.', $page->errors()->first('username'));
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_create_admin_refuses_a_username_that_is_another_employees_code(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0031', 'full_name' => 'Aung Kyaw Moe']);

        $this->artisan('user:create-admin')
            ->expectsQuestion('Name', 'Second Admin')
            ->expectsQuestion('Username', 'emp-0031')
            ->expectsQuestion('Email (optional, press Enter to skip)', '')
            ->expectsQuestion('Password', 'Correct-Horse-9')
            ->expectsQuestion('Password again', 'Correct-Horse-9')
            ->expectsOutputToContain('"emp-0031" is Aung Kyaw Moe\'s employee code')
            ->assertFailed();

        $this->assertNull(User::query()->where('name', 'Second Admin')->first());
    }
}
