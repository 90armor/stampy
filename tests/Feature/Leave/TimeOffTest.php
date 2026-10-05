<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Livewire\Leave\RequestModal;
use App\Livewire\Leave\TimeOff;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — the Time off page and the request modal. The numbers come from
 * the leave services; these tests check the wiring: who sees what, the
 * two-step review, errors on fields, and the "New" marker. The clock is
 * Mon 15 Jun 2026; the schedule is Mon–Fri with a 12:00 break.
 */
class TimeOffTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6]);
        LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special', 'max_days_per_request' => 7]);
        LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);   // Annual 2026: 18 + 2 = 20
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null, array $attributes = []): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, ...$attributes]);
    }

    private function modal(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->employee->user)->test(RequestModal::class);
    }

    // ── Access ─────────────────────────────────────────────────────────

    public function test_who_can_open_time_off_and_the_sidebar_follows_the_same_ability(): void
    {
        $this->actingAs($this->employee->user)->get(route('time-off.index'))->assertOk()->assertSee('Balances');
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertSee(route('time-off.index'));

        // An admin without an employee record files for others; no balance of their own.
        $this->actingAs($this->admin)->get(route('time-off.index'))
            ->assertOk()
            ->assertSee("Your account isn't linked to an employee record", false)
            ->assertSee('File for an employee');

        // A login with neither: no page, no link.
        $nobody = User::factory()->create()->assignRole('manager');
        $this->actingAs($nobody)->get(route('time-off.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('dashboard'))->assertDontSee(route('time-off.index'));
    }

    // ── Balances ───────────────────────────────────────────────────────

    public function test_balances_show_each_type_and_the_no_balance_types_below(): void
    {
        $html = Livewire::actingAs($this->employee->user)->test(TimeOff::class)
            ->assertSee('Also available: Special (deducted from Annual), Unpaid.')
            ->html();
        // Annual has its own row (the shared balances partial); Special and Unpaid don't.
        $this->assertMatchesRegularExpression('/<td class="px-6 py-4 font-medium[^"]*">\s*Annual\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/<td class="px-6 py-4 font-medium[^"]*">\s*(Special|Unpaid)\s*</', $html);
    }

    public function test_a_type_not_yet_usable_says_when_and_how_much_is_earned(): void
    {
        $joiner = $this->person('Joiner', 'employee', $this->manager, ['join_date' => '2026-03-01']);

        // 1 Mar – 15 Jun: 18 × 107/365 = 5.28 → 5.5 earned so far.
        Livewire::actingAs($joiner->user)->test(TimeOff::class)
            ->assertSee('Usable from Mon 1 Mar 2027 · 5.5 days earned so far');
    }

    // ── Requesting ─────────────────────────────────────────────────────

    public function test_review_shows_the_cost_and_effect_then_submit_writes(): void
    {
        $modal = $this->modal()
            ->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-19')
            ->set('end_date', '2026-06-23')
            ->call('review')
            ->assertHasNoErrors()
            ->assertSet('step', 'review')
            // Fri 19, Mon 22, Tue 23: the weekend between isn't charged.
            ->assertSee('Uses')->assertSee('3 days')
            ->assertSee('Available 20 → ')->assertSee('17')
            ->assertSee('Not charged: Sat 20 Jun (Saturday), Sun 21 Jun (Sunday).');
        $this->assertSame(0, Leave::count());

        $modal->call('submit')->assertDispatched('leave-saved')->assertSet('showModal', false);

        $leave = Leave::sole();
        $this->assertSame(LeaveStatus::Pending, $leave->status);
        $this->assertSame('2026-06-23', $leave->end_date->format('Y-m-d'));
    }

    public function test_dismiss_labels_never_say_a_bare_cancel_and_date_errors_sit_under_the_date_row(): void
    {
        app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->employee->user);

        // The cancel-leave confirmation: "Keep leave" / "Cancel leave".
        Livewire::actingAs($this->employee->user)->test(TimeOff::class)
            ->assertSeeHtml("confirmText: 'Cancel leave'")
            ->assertSeeHtml("cancelText: 'Keep leave'");

        // The overlap error: in the full-width slot after the date row, not inside the From column.
        $html = $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-22')
            ->call('review')
            ->html();
        $this->assertMatchesRegularExpression('/wire:click="close"[^>]*>\s*Close\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Cancel\s*<\/button>/', $html);
        // The To picker, the end of its column, the end of the grid row, then the error list.
        $this->assertMatchesRegularExpression('/id="leave_end-native".*?<\/div>\s*<\/div>\s*<\/div>(?:(?!<div).)*<li>These dates overlap your pending Annual leave/s', $html);
    }

    public function test_service_errors_land_on_their_fields(): void
    {
        // More than the 20 available: the balance error is the form's own.
        $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-22')
            ->set('end_date', '2026-07-20')
            ->call('review')
            ->assertHasErrors(['leave'])
            ->assertSet('step', 'form')
            ->assertSee('Not enough Annual leave for 2026: this needs 21, 20 available (1 short).');

        // Before the 30-day window: on the From field.
        $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-05-01')
            ->call('review')
            ->assertHasErrors(['start_date']);
    }

    public function test_a_change_between_review_and_submit_shows_the_review_again_and_writes_nothing(): void
    {
        $modal = $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-22')
            ->set('end_date', '2026-06-24')
            ->call('review');

        Holiday::factory()->create(['date' => '2026-06-23', 'name' => 'Company day']);

        $modal->call('submit')
            ->assertSet('step', 'review')
            ->assertSee('Something changed since you reviewed this request')
            ->assertSee('Company day');
        $this->assertSame(0, Leave::count());

        $modal->call('submit')->assertSet('showModal', false);
        $this->assertSame(1, Leave::count());
    }

    public function test_a_half_day_and_a_cross_year_review(): void
    {
        $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-22')
            ->set('half', 'am')
            ->call('review')
            ->assertSee('Mon 22 Jun · AM')
            ->assertSee('0.5 day');

        LeaveEntitlement::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2027, 'days' => '21.0']);

        $this->modal()->call('open')
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-12-30')
            ->set('end_date', '2027-01-05')
            ->call('review')
            ->assertSee('Crosses New Year')
            // Wed 30 – Thu 31 Dec in 2026; Fri 1, Mon 4, Tue 5 Jan in 2027.
            // 2027: 21 granted + 6 carried from 2026 (the cap) = 27 available.
            ->assertSeeInOrder(['2026: uses', '2 days', 'available 20', '18', '2027: uses', '3 days', 'available 27', '24']);
    }

    // ── Filing for an employee ─────────────────────────────────────────

    public function test_an_admin_finds_an_employee_without_a_login_and_files_leave_approved_immediately(): void
    {
        $noLogin = $this->person('Su Su Hlaing', null, $this->manager, ['employee_code' => 'EMP-0003']);

        $modal = Livewire::actingAs($this->admin)->test(RequestModal::class)
            ->call('openForOthers')
            ->set('employeeSearch', 'EMP-0003')
            ->assertSee('Su Su Hlaing')
            ->assertSee('no login')
            ->call('selectEmployee', $noLogin->id)
            ->set('leave_type_id', $this->annual->id)
            ->set('start_date', '2026-06-22')
            ->call('review')
            ->assertSee('this leave is approved immediately');

        $modal->call('submit');

        $this->assertSame(LeaveStatus::Approved, Leave::sole()->status);
        $this->assertSame($noLogin->id, Leave::sole()->employee_id);
    }

    public function test_only_an_admin_can_file_for_others(): void
    {
        $this->modal($this->manager->user)->call('openForOthers')->assertForbidden();
    }

    // ── Requests list ──────────────────────────────────────────────────

    public function test_the_requester_cancels_a_future_leave_from_the_list(): void
    {
        $service = app(LeaveRequestService::class);
        $leave = $service->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->employee->user)['leave'];
        $started = $service->submit($this->employee, $this->annual, Carbon::parse('2026-06-15'), Carbon::parse('2026-06-15'), null, null, $this->admin)['leave'];

        Livewire::actingAs($this->employee->user)->test(TimeOff::class)
            ->assertSeeHtml('aria-label="Cancel Annual leave, Mon 22 Jun"')
            ->assertDontSeeHtml('aria-label="Cancel Annual leave, Mon 15 Jun"')
            ->call('cancel', $leave->id)
            ->assertSee('Cancelled your Annual leave.');

        $this->assertSame(LeaveStatus::Cancelled, $leave->fresh()->status);
        $this->assertSame(LeaveStatus::Approved, $started->fresh()->status);
    }

    public function test_a_request_decided_since_the_last_visit_is_marked_new(): void
    {
        $service = app(LeaveRequestService::class);
        $leave = $service->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->employee->user)['leave'];

        // First visit: nothing is "new", and the visit is recorded.
        Livewire::actingAs($this->employee->user)->test(TimeOff::class)->assertSet('newIds', []);

        $this->travelTo(Carbon::parse('2026-06-15 13:00:00'));
        $service->reject($leave, $this->manager->user, 'Team offsite that week');

        Livewire::actingAs($this->employee->user->fresh())->test(TimeOff::class)
            ->assertSet('newIds', [$leave->id])
            ->assertSee('New')
            ->assertSee('“Team offsite that week” — Manager', false);

        // Seen now: not new on the next visit.
        Livewire::actingAs($this->employee->user->fresh())->test(TimeOff::class)->assertSet('newIds', []);
    }
}
