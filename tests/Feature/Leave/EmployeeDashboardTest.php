<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveHalf;
use App\Livewire\Attendance\Show;
use App\Livewire\Leave\TimeOff;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Leave\LeaveRequestService;
use App\Support\AttendanceSummary;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — the dashboard's leave parts: the employee dashboard (balances,
 * this month, pending requests) and the approvers' Pending approvals card.
 * The clock is Wed 17 Jun 2026.
 */
class EmployeeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Employee $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'seniority_bonus' => false, 'min_service_months' => null]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
    }

    private function person(string $name, string $role, ?Employee $manager = null, string $joined = '2020-01-01'): Employee
    {
        $user = User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user->id, 'manager_id' => $manager?->id, 'join_date' => $joined]);
    }

    private function request(Employee $employee, string $from, string $to): void
    {
        app(LeaveRequestService::class)->submit($employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, null, $employee->user);
    }

    public function test_an_employee_sees_their_balance_and_pending_requests(): void
    {
        $this->request($this->employee, '2026-06-22', '2026-06-24');

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Leave balance')
            // 18 granted, 3 reserved by the pending request — Time off's available().
            ->assertSeeInOrder(['Annual', '15', 'available'])
            ->assertSee('My pending requests')
            ->assertSee('1 waiting')
            ->assertSeeInOrder(['Annual · ', '22–24 Jun'])
            ->assertSee('3 days · Waiting for manager')
            ->assertSee(route('time-off.index'))
            // No team figures, and no approver card.
            ->assertDontSee('Needs attention')
            ->assertDontSee('Pending approvals');
    }

    public function test_a_type_not_usable_yet_says_when_it_will_be(): void
    {
        $this->annual->update(['min_service_months' => 12]);
        $newcomer = $this->person('Newcomer', 'employee', null, '2026-03-01');

        $this->actingAs($newcomer->user)->get(route('dashboard'))
            ->assertSee('Usable from '.DisplayDate::compact(Carbon::parse('2027-03-01')))
            ->assertSee('earned so far')
            ->assertSee('Nothing waiting for a decision.');
    }

    public function test_this_month_is_the_same_summary_as_my_attendance(): void
    {
        // Mon 1 – Tue 16 Jun: present on the 1st and late on the 2nd, the
        // rest absent, and an approved day of leave on the 10th.
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-01 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-01 17:00:00', 'punch_type' => 'out']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-02 08:40:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-02 17:00:00', 'punch_type' => 'out']);
        $this->request($this->employee, '2026-06-10', '2026-06-10');
        $leave = $this->employee->leaves()->first();
        $service = app(LeaveRequestService::class);
        $service->approve($leave, $this->manager->user);
        $service->approve($leave->fresh(), User::factory()->create()->assignRole('admin'));
        app(DailySummaryBuilder::class)->rebuildBetween($this->employee->fresh(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-16'));

        $summary = AttendanceSummary::forMonth($this->employee, today());
        $this->assertSame(12, $summary['workdays']);
        $this->assertSame(12, $summary['present'] + $summary['absent'] + $summary['incomplete'] + $summary['leave']);
        $this->assertSame(1, $summary['leave']);
        $this->assertSame(1, $summary['late']);

        // My attendance shows exactly these counts (one definition).
        Livewire::actingAs($this->employee->user)->test(Show::class)
            ->assertViewHas('summary', $summary);

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertSee('This month')
            ->assertSee(DisplayDate::month(today()))
            ->assertSeeInOrder(['Calculated workdays', '12', 'Present', 'of which 1 late', '2', 'Absent', '9', 'Incomplete', '0', 'On leave', '1'])
            ->assertSee('Leave taken: 1 day');
    }

    public function test_the_pending_approvals_card_follows_the_inbox_and_hides_at_zero(): void
    {
        $this->actingAs($this->manager->user)->get(route('dashboard'))
            ->assertSee('Needs attention')
            ->assertDontSee('Pending approvals');

        $this->request($this->employee, '2026-06-22', '2026-06-22');

        $this->actingAs($this->manager->user)->get(route('dashboard'))
            ->assertSee('Pending approvals')
            ->assertSeeText('1 leave request waiting on you')
            ->assertSee(route('approvals.index'));

        // A manager without an employee record has no inbox (LeavePolicy::decideAny).
        $this->actingAs(User::factory()->create()->assignRole('manager'))->get(route('dashboard'))
            ->assertDontSee('Pending approvals');
    }

    public function test_decisions_since_the_last_time_off_visit_show_with_the_rejection_note_until_time_off_is_opened(): void
    {
        $this->request($this->employee, '2026-06-22', '2026-06-22');

        // Before a first visit to Time off nothing is "new" — everything would be.
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertDontSee('Decided since your last visit');

        Livewire::actingAs($this->employee->user)->test(TimeOff::class);
        $this->travelTo(now()->addHour());
        app(LeaveRequestService::class)->reject($this->employee->leaves()->sole(), $this->manager->user, 'Stocktake that day');

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertSee('Decided since your last visit')
            ->assertSee('1 new')
            ->assertSeeInOrder(['Annual · ', 'Mon 22 Jun', 'Rejected', 'New'])
            ->assertSee('“Stocktake that day”', false)
            ->assertSee('— Manager');

        // The dashboard doesn't mark them seen; opening Time off does.
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertSee('Decided since your last visit');
        Livewire::actingAs($this->employee->user)->test(TimeOff::class)->assertSee('New');
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertDontSee('Decided since your last visit');
    }

    public function test_coming_up_is_the_next_approved_leave(): void
    {
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertSee('No approved leave coming up.');

        $admin = User::factory()->create()->assignRole('admin');
        $service = app(LeaveRequestService::class);
        // Filed by an admin: approved on submit. The later one isn't "next"; a pending one doesn't count.
        $service->submit($this->employee, $this->annual, Carbon::parse('2026-07-06'), Carbon::parse('2026-07-07'), null, null, $admin);
        $service->submit($this->employee, $this->annual, Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'), LeaveHalf::Am, null, $admin);
        $this->request($this->employee, '2026-06-19', '2026-06-19');

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertSeeInOrder(['Coming up', 'Annual · ', 'Wed 24 Jun · AM', '0.5 day · Starts in 7 days', 'This month'])
            ->assertDontSee('No approved leave coming up.');
    }

    public function test_someone_without_an_employee_record_keeps_the_plain_card(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('employee'))->get(route('dashboard'))
            ->assertSee('Your attendance workspace')
            ->assertDontSee('Leave balance');
    }
}
