<?php

namespace Tests\Feature\Leave;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveValidationException;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3c — submitting a leave request (CLAUDE.md, Phase 3, rules 8–13).
 * Each rule: the boundary that passes and the violation that fails. The
 * clock is Mon 15 Jun 2026; the schedule is Mon–Fri with a 12:00 break.
 */
class LeaveSubmitTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $service;

    private LeaveType $annual;

    private LeaveType $special;

    private LeaveType $maternity;

    private LeaveType $unpaid;

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
        $this->special = LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special', 'max_days_per_request' => 7]);
        $this->maternity = LeaveType::factory()->withoutBalance()->calendarDays()->withoutHalfDays()->create(['name' => 'Maternity', 'max_days_per_request' => 90]);
        $this->unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);

        // Joined 2020: Annual 2026 = 18 + 2 seniority = 20, granted at creation.
        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');

        $this->service = app(LeaveRequestService::class);
    }

    private function person(string $name, ?string $role, ?Employee $manager = null, array $attributes = []): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, ...$attributes]);
    }

    private function submit(string $from, string $to, ?LeaveType $type = null, ?Employee $employee = null, ?User $actor = null, ?LeaveHalf $half = null): Leave
    {
        $employee ??= $this->employee;

        return $this->service->submit($employee, $type ?? $this->annual, Carbon::parse($from), Carbon::parse($to), $half, null, $actor ?? $employee->user)['leave'];
    }

    /**
     * @return array<string, list<string>>
     */
    private function refused(callable $submit): array
    {
        try {
            $submit();
        } catch (LeaveValidationException $e) {
            return $e->errors();
        }

        $this->fail('The request should have been refused.');
    }

    private function message(array $errors): string
    {
        return collect($errors)->flatten()->implode(' | ');
    }

    // ── Outcomes ───────────────────────────────────────────────────────

    public function test_an_employees_request_waits_for_their_manager(): void
    {
        $leave = $this->submit('2026-06-22', '2026-06-26');

        $this->assertSame(LeaveStatus::Pending, $leave->status);
        $this->assertSame(1, $leave->current_step);
        $this->assertSame($this->employee->user_id, $leave->requested_by);
        $this->assertSame(0, $leave->approvalSteps()->count());
    }

    public function test_no_manager_records_step_one_as_skipped_and_waits_for_an_admin(): void
    {
        $loner = $this->person('Loner', 'employee');

        $leave = $this->submit('2026-06-22', '2026-06-22', employee: $loner);

        $this->assertSame(2, $leave->current_step);
        $step = $leave->approvalSteps()->first();
        $this->assertSame(ApprovalOutcome::Skipped, $step->outcome);
        $this->assertNull($step->decided_by);
        $this->assertSame('No manager is assigned to Loner.', $step->note);
    }

    public function test_an_admin_filing_for_someone_else_is_final_and_rebuilds_the_past_days(): void
    {
        $this->mock(DailySummaryBuilder::class, fn ($mock) => $mock->shouldReceive('rebuildBetween')->once()
            ->withArgs(fn ($employee, $from, $to) => $employee->is($this->employee) && $from->format('Y-m-d') === '2026-06-01' && $to->format('Y-m-d') === '2026-06-05')
            ->andReturn(5));
        // Resolve again so the service gets the mocked builder.
        $this->service = app(LeaveRequestService::class);

        $leave = $this->submit('2026-06-01', '2026-06-05', actor: $this->admin);

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertNull($leave->current_step);
        $this->assertSame([ApprovalOutcome::Approved, ApprovalOutcome::Approved], $leave->approvalSteps->pluck('outcome')->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $leave->approvalSteps->pluck('decided_by')->all());
    }

    public function test_the_sole_admins_own_request_without_a_manager_is_self_approved(): void
    {
        $this->admin->delete();
        $soleAdmin = $this->person('Sole Admin', 'admin');

        $leave = $this->submit('2026-06-22', '2026-06-22', employee: $soleAdmin);

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertSame([ApprovalOutcome::Skipped, ApprovalOutcome::SelfApproved], $leave->approvalSteps->pluck('outcome')->all());
        $this->assertSame($soleAdmin->user_id, $leave->approvalSteps->last()->decided_by);
    }

    public function test_the_sole_admins_own_request_with_a_manager_waits_for_that_manager_first(): void
    {
        $this->admin->delete();
        $soleAdmin = $this->person('Sole Admin', 'admin', $this->manager);

        $leave = $this->submit('2026-06-22', '2026-06-22', employee: $soleAdmin);

        $this->assertSame(LeaveStatus::Pending, $leave->status);
        $this->assertSame(1, $leave->current_step);
    }

    // ── Type and actor ─────────────────────────────────────────────────

    public function test_an_inactive_type_is_refused(): void
    {
        $this->unpaid->update(['is_active' => false]);

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', $this->unpaid));

        $this->assertSame(['Unpaid leave is no longer offered.'], $errors['leave_type_id']);
    }

    public function test_only_the_employee_or_an_admin_may_file(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->submit('2026-06-22', '2026-06-22', actor: $this->manager->user);
    }

    // ── Dates ──────────────────────────────────────────────────────────

    public function test_the_end_cannot_precede_the_start(): void
    {
        $this->assertArrayHasKey('end_date', $this->refused(fn () => $this->submit('2026-06-22', '2026-06-19')));
    }

    public function test_the_leave_must_fall_within_the_employment_period(): void
    {
        $joiner = $this->person('Joiner', 'employee', $this->manager, ['join_date' => '2026-06-10']);
        $errors = $this->refused(fn () => $this->submit('2026-06-09', '2026-06-10', $this->unpaid, $joiner));
        $this->assertSame(["Leave can't start before Joiner joined (Wed 10 Jun)."], $errors['start_date']);
        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-10', '2026-06-10', $this->unpaid, $joiner)->status);

        $leaver = $this->person('Leaver', null, null, ['status' => 'inactive', 'left_on' => '2026-06-12']);
        $errors = $this->refused(fn () => $this->submit('2026-06-12', '2026-06-15', $this->unpaid, $leaver, $this->admin));
        $this->assertSame(["Leave can't run past Leaver's last day (Fri 12 Jun)."], $errors['end_date']);
        $this->assertSame(LeaveStatus::Approved, $this->submit('2026-06-12', '2026-06-12', $this->unpaid, $leaver, $this->admin)->status);
    }

    public function test_an_employee_can_go_back_30_days_and_an_admin_to_the_join_date(): void
    {
        // 30 days back is Sat 16 May; the leave's one workday is Mon 18 May.
        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-05-16', '2026-05-18')->status);

        $errors = $this->refused(fn () => $this->submit('2026-05-15', '2026-05-15'));
        $this->assertSame(['Leave can be requested at most 30 days back — from Sat 16 May.'], $errors['start_date']);

        $this->assertSame(LeaveStatus::Approved, $this->submit('2026-02-02', '2026-02-02', actor: $this->admin)->status);
    }

    public function test_the_forward_limit_is_31_december_unless_next_years_leave_is_granted(): void
    {
        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-12-31', '2026-12-31', $this->unpaid)->status);

        $errors = $this->refused(fn () => $this->submit('2026-12-31', '2027-01-04', $this->unpaid));
        $this->assertSame(["Leave in 2027 can be requested once 2027's leave has been granted."], $errors['end_date']);

        LeaveEntitlement::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2027, 'days' => '20.0']);
        $this->assertSame(LeaveStatus::Pending, $this->submit('2027-01-04', '2027-01-04', $this->unpaid)->status);

        $errors = $this->refused(fn () => $this->submit('2028-01-03', '2028-01-03', $this->unpaid));
        $this->assertSame(['Leave can be requested up to 31 Dec 2027.'], $errors['end_date']);
    }

    // ── Eligibility ────────────────────────────────────────────────────

    public function test_annual_and_special_wait_for_the_eligibility_date_and_unpaid_does_not(): void
    {
        $joiner = $this->person('Joiner', 'employee', $this->manager, ['join_date' => '2026-01-05']);

        foreach ([$this->annual, $this->special] as $type) {
            $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', $type, $joiner));
            $this->assertSame(['Annual leave can be used from Tue 5 Jan 2027.'], $errors['start_date']);
        }

        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-06-22', $this->unpaid, $joiner)->status);
    }

    // ── Half days ──────────────────────────────────────────────────────

    public function test_half_day_rules(): void
    {
        $this->assertSame(LeaveHalf::Am, $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Am)->half);

        $errors = $this->refused(fn () => $this->submit('2026-06-23', '2026-06-23', $this->maternity, half: LeaveHalf::Am));
        $this->assertSame(["Maternity leave can't be taken as a half day."], $errors['half']);

        $errors = $this->refused(fn () => $this->submit('2026-06-23', '2026-06-24', half: LeaveHalf::Am));
        $this->assertSame(['A half day covers a single date.'], $errors['half']);
    }

    public function test_a_half_day_needs_a_break_time_on_that_dates_schedule(): void
    {
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break', 'break_minutes' => 0]);
        $this->employee->scheduleAssignments()->create(['work_schedule_id' => $noBreak->id, 'effective_from' => '2026-06-22']);

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Pm, employee: $this->employee->fresh()));

        // Only an admin can fix it, so only an admin is told how.
        $this->assertSame(["Half-day leave isn't available on your schedule yet. Ask HR to set it up."], $errors['half']);
        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Pm, employee: $this->employee->fresh(), actor: $this->admin));
        $this->assertSame(["Employee's schedule on Mon 22 Jun has no break time set, so the day can't be split into halves. Set \"Break starts\" on the No break schedule in Policies → Schedules first."], $errors['half']);
        // The week before, on the old schedule, is fine.
        $this->assertSame(LeaveHalf::Pm, $this->submit('2026-06-19', '2026-06-19', half: LeaveHalf::Pm, employee: $this->employee->fresh())->half);
    }

    public function test_the_missing_break_message_suits_whoever_files_their_own_half_day(): void
    {
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break', 'break_minutes' => 0]);
        $manager = $this->person('Second Manager', 'manager');
        $admin = $this->person('Staff Admin', 'admin');
        foreach ([$manager, $admin] as $person) {
            $person->scheduleAssignments()->create(['work_schedule_id' => $noBreak->id, 'effective_from' => '2026-06-22']);
        }

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Am, employee: $manager->fresh()));
        $this->assertSame(["Half-day leave isn't available on your schedule yet. Ask HR to set it up."], $errors['half']);

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Am, employee: $admin->fresh()));
        $this->assertSame(["Your schedule on Mon 22 Jun has no break time set, so the day can't be split into halves. Set \"Break starts\" on the No break schedule in Policies → Schedules first."], $errors['half']);
    }

    // ── Cost ───────────────────────────────────────────────────────────

    public function test_a_leave_that_costs_nothing_is_refused(): void
    {
        Holiday::factory()->create(['date' => '2026-06-24']);

        foreach ([['2026-06-20', '2026-06-21'], ['2026-06-24', '2026-06-24']] as [$from, $to]) {
            $errors = $this->refused(fn () => $this->submit($from, $to));
            $this->assertSame(['These dates cover no working days — every date is a day off or a holiday.'], $errors['end_date']);
        }
    }

    public function test_the_per_request_maximum_is_measured_in_the_types_own_counting(): void
    {
        // Special: 7 working days (Mon 22 Jun – Tue 30 Jun) passes; 8 doesn't.
        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-06-30', $this->special)->status);
        $errors = $this->refused(fn () => $this->submit('2026-07-06', '2026-07-15', $this->special));
        $this->assertSame(['Special leave is at most 7 working days per request; these dates are 8.'], $errors['end_date']);

        // Maternity: 90 calendar days passes; 91 doesn't.
        $mother = $this->person('Mother', 'employee', $this->manager);
        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-07-01', '2026-09-28', $this->maternity, $mother)->status);
        $other = $this->person('Other', 'employee', $this->manager);
        $errors = $this->refused(fn () => $this->submit('2026-07-01', '2026-09-29', $this->maternity, $other));
        $this->assertSame(['Maternity leave is at most 90 calendar days per request; these dates are 91.'], $errors['end_date']);
    }

    // ── Overlap ────────────────────────────────────────────────────────

    public function test_overlap_is_checked_at_half_day_granularity(): void
    {
        $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Am);
        $this->assertSame(LeaveHalf::Pm, $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Pm)->half);

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', half: LeaveHalf::Am));
        // The requester reads "your"; an admin filing for them reads the name.
        $this->assertStringContainsString('overlap your pending Annual leave (Mon 22 Jun, AM)', $this->message($errors));
        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-22', actor: $this->admin, half: LeaveHalf::Am));
        $this->assertStringContainsString("overlap Employee's pending Annual leave (Mon 22 Jun, AM)", $this->message($errors));

        // A full day overlaps both halves.
        $errors = $this->refused(fn () => $this->submit('2026-06-19', '2026-06-23'));
        $this->assertCount(2, $errors['start_date']);
    }

    public function test_rejected_and_cancelled_leaves_dont_block(): void
    {
        Leave::factory()->for($this->employee)->for($this->annual)->between('2026-06-22', '2026-06-26')->rejected()->create();
        Leave::factory()->for($this->employee)->for($this->annual)->between('2026-06-22', '2026-06-26')->cancelled()->create();

        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-06-26')->status);
    }

    // ── Balance ────────────────────────────────────────────────────────

    public function test_the_balance_must_cover_the_cost_and_the_message_gives_the_shortfall(): void
    {
        // 20 available; 21 working days is 1 short.
        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-07-20'));
        $this->assertSame(['Not enough Annual leave for 2026: this needs 21, 20 available (1 short).'], $errors['leave']);

        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-07-17')->status);
    }

    public function test_pending_requests_reserve_balance_so_the_second_of_two_that_fit_alone_fails(): void
    {
        // 12 + 12 working days, each within the 20 available alone.
        $this->submit('2026-06-22', '2026-07-07');

        $errors = $this->refused(fn () => $this->submit('2026-07-13', '2026-07-28'));
        $this->assertSame(['Not enough Annual leave for 2026: this needs 12, 8 available (4 short).'], $errors['leave']);
    }

    public function test_a_cross_year_leave_is_checked_against_each_year(): void
    {
        LeaveEntitlement::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2027, 'days' => '20.0']);
        LeaveAdjustment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => '-19.0', 'note' => 'Taken before go-live']);

        // Mon 28 Dec 2026 – Fri 8 Jan 2027: 4 days in 2026 (1 available), 6 in 2027.
        $errors = $this->refused(fn () => $this->submit('2026-12-28', '2027-01-08'));

        $this->assertSame(['Not enough Annual leave for 2026: this needs 4, 1 available (3 short).'], $errors['leave']);
    }

    public function test_special_is_checked_against_annuals_balance(): void
    {
        LeaveAdjustment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => '-18.0', 'note' => 'Taken before go-live']);

        $errors = $this->refused(fn () => $this->submit('2026-06-22', '2026-06-24', $this->special));
        $this->assertSame(['Not enough Annual leave for 2026: this needs 3, 2 available (1 short).'], $errors['leave']);

        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-06-23', $this->special)->status);
    }

    public function test_types_without_a_balance_skip_the_balance_check(): void
    {
        LeaveAdjustment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => '-20.0', 'note' => 'All used']);

        $this->assertSame(LeaveStatus::Pending, $this->submit('2026-06-22', '2026-07-31', $this->unpaid)->status);
    }
}
