<?php

namespace Tests\Feature\Overtime;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveHalf;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Exceptions\LeaveValidationException;
use App\Exceptions\OvertimeValidationException;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4c — submitting an overtime request (CLAUDE.md, Phase 4, rules 1,
 * 4–7, 12). Each rule: the boundary that passes and the violation that
 * fails. The clock is Mon 15 Jun 2026 12:00; the schedule is Mon–Fri
 * 08:00–17:00 with a 12:00–13:00 break.
 */
class OvertimeSubmitTest extends TestCase
{
    use RefreshDatabase;

    private OvertimeRequestService $service;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    private LeaveType $annual;

    private LeaveType $toil;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $this->toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $this->toil->id]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');

        $this->service = app(OvertimeRequestService::class);
    }

    private function person(string $name, ?string $role, ?Employee $manager = null, array $attributes = []): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, ...$attributes]);
    }

    /** $to before $from is the next day. */
    private function submit(
        string $date,
        string $from,
        string $to,
        ?Employee $employee = null,
        ?User $actor = null,
        OvertimeCompensation $compensation = OvertimeCompensation::Pay,
        ?string $override = null,
    ): OvertimeRequest {
        $employee ??= $this->employee;
        $start = Carbon::parse("{$date} {$from}");
        $end = Carbon::parse("{$date} {$to}");

        return $this->service->submit(
            $employee, Carbon::parse($date), $start, $end->lte($start) ? $end->addDay() : $end,
            $compensation, null, $actor ?? $employee->user, $override,
        )['request'];
    }

    /** @return array<string, list<string>> */
    private function refused(callable $submit): array
    {
        try {
            $submit();
        } catch (OvertimeValidationException $e) {
            return $e->errors();
        }

        $this->fail('The request should have been refused.');
    }

    private function message(array $errors): string
    {
        return collect($errors)->flatten()->implode(' | ');
    }

    // ── Outcomes ───────────────────────────────────────────────────────

    public function test_a_planned_request_waits_for_the_manager_and_a_past_one_is_a_claim(): void
    {
        $planned = $this->submit('2026-06-16', '17:00', '19:00');

        $this->assertSame([OvertimeStatus::Pending, 1, OvertimeKind::Planned, $this->employee->user_id], [$planned->status, $planned->current_step, $planned->kind, $planned->requested_by]);
        $this->assertSame(0, $planned->approvalSteps()->count());

        // Started before now (even today, earlier): a claim.
        $this->assertSame(OvertimeKind::Claim, $this->submit('2026-06-12', '17:00', '19:00')->kind);
        $this->assertSame(OvertimeKind::Claim, $this->submit('2026-06-15', '06:00', '08:00')->kind);
    }

    public function test_with_nobody_to_decide_step_one_it_is_skipped(): void
    {
        $alone = $this->person('Alone', 'employee');

        $request = $this->submit('2026-06-16', '17:00', '19:00', $alone);

        $this->assertSame(2, $request->current_step);
        $this->assertSame([ApprovalOutcome::Skipped, 'No manager is assigned to Alone.'], [$request->approvalSteps()->sole()->outcome, $request->approvalSteps()->sole()->note]);
    }

    public function test_an_admin_filing_for_someone_is_final_and_a_past_date_is_rebuilt_and_credited(): void
    {
        // Sat 13 Jun 08:00–13:00 worked: 4h less the break is no 2h-cap problem on a Saturday.
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-13 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-13 13:00:00', 'punch_type' => 'out']);

        $result = $this->service->submit($this->employee, Carbon::parse('2026-06-13'), Carbon::parse('2026-06-13 08:00'), Carbon::parse('2026-06-13 13:00'), OvertimeCompensation::TimeOff, 'Stock count', $this->admin);
        $request = $result['request'];

        $this->assertNull($result['rebuildError']);
        $this->assertSame([OvertimeStatus::Approved, null, $this->admin->id], [$request->status, $request->current_step, $request->requested_by]);
        $this->assertSame([1, 2], $request->approvalSteps()->pluck('step')->all());
        $this->assertSame(240, $this->employee->dailyAttendances()->sole()->overtime_workday_minutes);
        $this->assertSame('0.5', LeaveAdjustment::where('overtime_request_id', $request->id)->sole()->days);
    }

    public function test_only_the_employee_or_an_admin_may_file(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->submit('2026-06-16', '17:00', '19:00', $this->employee, $this->manager->user);
    }

    // ── Employment, claim window, planning horizon ─────────────────────

    public function test_the_date_is_inside_the_employment_period(): void
    {
        $joiner = $this->person('Joiner', 'employee', $this->manager, ['join_date' => '2026-06-16']);

        $this->assertStringContainsString('before Joiner joined (Tue 16 Jun)', $this->message($this->refused(fn () => $this->submit('2026-06-15', '17:00', '19:00', $joiner, $this->admin))));
        $this->assertSame('2026-06-16', $this->submit('2026-06-16', '17:00', '19:00', $joiner)->date->format('Y-m-d'));
    }

    public function test_a_claim_goes_back_at_most_the_claim_window_for_everyone_but_an_admin(): void
    {
        // Seven days back: Mon 8 Jun is the earliest.
        $this->assertSame('2026-06-08', $this->submit('2026-06-08', '17:00', '19:00')->date->format('Y-m-d'));

        $errors = $this->refused(fn () => $this->submit('2026-06-05', '17:00', '19:00'));
        $this->assertSame(['date'], array_keys($errors));
        $this->assertStringContainsString('at most 7 days back — from Mon 8 Jun', $this->message($errors));

        // An admin may go back to the join date.
        $this->assertSame(OvertimeStatus::Approved, $this->submit('2026-06-05', '17:00', '19:00', actor: $this->admin)->status);
    }

    public function test_a_planned_request_is_at_most_31_days_ahead(): void
    {
        $this->assertSame('2026-07-16', $this->submit('2026-07-16', '17:00', '19:00')->date->format('Y-m-d'));

        $this->assertStringContainsString('at most 31 days ahead — up to Thu 16 Jul', $this->message($this->refused(fn () => $this->submit('2026-07-17', '17:00', '19:00'))));
    }

    // ── The window ─────────────────────────────────────────────────────

    public function test_the_window_must_have_a_sensible_shape(): void
    {
        $this->assertSame(['ends_at'], array_keys($this->refused(fn () => $this->service->submit(
            $this->employee, Carbon::parse('2026-06-16'), Carbon::parse('2026-06-16 19:00'), Carbon::parse('2026-06-16 18:00'), OvertimeCompensation::Pay, null, $this->employee->user,
        ))));
        $this->assertStringContainsString('longer than 12 hours', $this->message($this->refused(fn () => $this->service->submit(
            $this->employee, Carbon::parse('2026-06-20'), Carbon::parse('2026-06-20 06:00'), Carbon::parse('2026-06-20 18:30'), OvertimeCompensation::Pay, null, $this->employee->user,
        ))));
        $this->assertSame(['starts_at'], array_keys($this->refused(fn () => $this->service->submit(
            $this->employee, Carbon::parse('2026-06-16'), Carbon::parse('2026-06-17 00:30'), Carbon::parse('2026-06-17 01:30'), OvertimeCompensation::Pay, null, $this->employee->user,
        ))));
    }

    public function test_a_window_inside_normal_hours_is_refused_and_one_reaching_past_them_is_not(): void
    {
        $errors = $this->refused(fn () => $this->submit('2026-06-16', '09:00', '11:00'));
        $this->assertSame(['starts_at' => ['This is within normal working hours (8:00 AM – 5:00 PM).']], $errors);

        // 16:00–18:00: only 17:00–18:00 counts, but that's something.
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-16', '16:00', '18:00')->status);
    }

    public function test_a_window_crossing_22_00_is_two_hours_and_allowed(): void
    {
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-16', '21:00', '23:00')->status);
    }

    // ── Leave ───────────────────────────────────────────────────────────

    public function test_approved_or_pending_full_day_leave_refuses_overtime_and_half_day_leave_does_not(): void
    {
        Leave::factory()->for($this->employee)->for($this->annual)->approved()->between('2026-06-16', '2026-06-16')->create();
        Leave::factory()->for($this->employee)->for($this->annual)->pending()->between('2026-06-17', '2026-06-18')->create();
        Leave::factory()->for($this->employee)->for($this->annual)->approved()->between('2026-06-19', '2026-06-19')->halfDay('am')->create();

        $this->assertStringContainsString('Tue 16 Jun is covered by your approved Annual leave (Tue 16 Jun)', $this->message($this->refused(fn () => $this->submit('2026-06-16', '17:00', '19:00'))));
        $this->assertStringContainsString('Thu 18 Jun is covered by your pending Annual leave (17–18 Jun)', $this->message($this->refused(fn () => $this->submit('2026-06-18', '17:00', '19:00'))));
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-19', '17:00', '19:00')->status);
    }

    public function test_full_day_leave_is_refused_on_a_date_with_overtime_and_half_day_leave_is_not(): void
    {
        $this->submit('2026-06-17', '17:00', '19:00');
        $leaves = app(LeaveRequestService::class);
        $submitLeave = fn (string $from, string $to, ?LeaveHalf $half = null) => $leaves->submit($this->employee, $this->annual, Carbon::parse($from), Carbon::parse($to), $half, null, $this->employee->user)['leave'];

        try {
            $submitLeave('2026-06-16', '2026-06-18');
            $this->fail('A full-day leave over a date with overtime was accepted.');
        } catch (LeaveValidationException $e) {
            $this->assertSame(['start_date' => ['Wed 17 Jun has your pending overtime request (5:00 PM – 7:00 PM). Cancel it first, or take a half day.']], $e->errors());
        }

        // A half day leaves the other half worked; an AM and then a PM would make it a full day.
        $submitLeave('2026-06-17', '2026-06-17', LeaveHalf::Am);
        $this->expectException(LeaveValidationException::class);
        $submitLeave('2026-06-17', '2026-06-17', LeaveHalf::Pm);
    }

    // ── One request per date ───────────────────────────────────────────

    public function test_one_active_request_per_date_and_a_cancelled_one_frees_the_date(): void
    {
        $first = $this->submit('2026-06-16', '17:00', '19:00');

        $this->assertStringContainsString('already a pending overtime request on Tue 16 Jun (5:00 PM – 7:00 PM)', $this->message($this->refused(fn () => $this->submit('2026-06-16', '19:00', '20:00'))));

        $first->update(['status' => OvertimeStatus::Cancelled, 'current_step' => null]);
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-16', '19:00', '20:00')->status);
    }

    public function test_submit_locks_the_employee_row(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->submit('2026-06-16', '17:00', '19:00');
        DB::disableQueryLog();

        $this->assertTrue(collect(DB::getQueryLog())->pluck('query')->contains(fn (string $sql) => str_contains($sql, 'from `employees`') && str_ends_with(trim($sql), 'for update')));
    }

    // ── Limits ──────────────────────────────────────────────────────────

    public function test_a_workday_allows_two_hours_of_overtime_and_not_a_minute_more(): void
    {
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-16', '17:00', '19:00')->status);

        $errors = $this->refused(fn () => $this->submit('2026-06-17', '17:00', '19:01'));
        // A minute over 2h is a minute over the 10h day too (8h scheduled): both are named.
        $this->assertSame(['overtime' => [
            'This is 2h 01m of overtime; the limit is 2h 00m a day.',
            'With 8h 00m of scheduled work this makes 10h 01m; the limit is 10h 00m a day.',
        ]], $errors);
    }

    public function test_a_nine_hour_weekday_request_breaks_both_limits(): void
    {
        $errors = $this->refused(fn () => $this->submit('2026-06-16', '17:00', '02:00'));

        $this->assertSame([
            'This is 9h 00m of overtime; the limit is 2h 00m a day.',
            'With 8h 00m of scheduled work this makes 17h 00m; the limit is 10h 00m a day.',
        ], $errors['overtime']);
    }

    public function test_the_daily_total_counts_the_scheduled_hours_less_a_half_day_on_leave(): void
    {
        OvertimeSettings::current()->update(['max_overtime_minutes_per_day' => 300]);

        // 8h scheduled + 3h = 11h: over the 10h total.
        $this->assertStringContainsString('makes 11h 00m; the limit is 10h 00m', $this->message($this->refused(fn () => $this->submit('2026-06-16', '17:00', '20:00'))));

        // On an AM-leave day only the afternoon is scheduled: 4h + 5h = 9h.
        Leave::factory()->for($this->employee)->for($this->annual)->approved()->between('2026-06-17', '2026-06-17')->halfDay('am')->create();
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-17', '17:00', '22:00')->status);
    }

    public function test_a_day_without_scheduled_hours_has_only_the_total_limit(): void
    {
        // Saturday 08:00–17:00 is 8h less the break: allowed, no 2h cap.
        $this->assertSame(OvertimeStatus::Pending, $this->submit('2026-06-20', '08:00', '17:00')->status);

        // 07:00–19:00 is 11h: over the 10h total.
        $this->assertSame(['overtime' => ['This is 11h 00m of work; the limit is 10h 00m a day.']], $this->refused(fn () => $this->submit('2026-06-21', '07:00', '19:00')));
    }

    public function test_an_admin_may_exceed_a_limit_only_with_an_override_reason(): void
    {
        $withoutReason = $this->refused(fn () => $this->submit('2026-06-16', '17:00', '20:00', actor: $this->admin));
        $this->assertSame(['overtime', 'limit_override_reason'], array_keys($withoutReason));
        $this->assertSame(['To go over the limit, give an override reason.'], $withoutReason['limit_override_reason']);

        $this->refused(fn () => $this->submit('2026-06-16', '17:00', '20:00', actor: $this->admin, override: '   '));

        $request = $this->submit('2026-06-16', '17:00', '20:00', actor: $this->admin, override: ' Month-end close ');
        $this->assertSame([OvertimeStatus::Approved, 'Month-end close'], [$request->status, $request->limit_override_reason]);

        // Within the limits, no reason is stored.
        $this->assertNull($this->submit('2026-06-17', '17:00', '19:00', actor: $this->admin, override: 'Not needed')->limit_override_reason);

        // An employee's override reason doesn't lift a limit.
        $this->assertArrayNotHasKey('limit_override_reason', $this->refused(fn () => $this->submit('2026-06-18', '17:00', '20:00', override: 'Please')));
    }

    // ── Compensation ───────────────────────────────────────────────────

    public function test_time_off_needs_a_toil_leave_type(): void
    {
        $this->assertSame(OvertimeCompensation::TimeOff, $this->submit('2026-06-16', '17:00', '19:00', compensation: OvertimeCompensation::TimeOff)->compensation);

        OvertimeSettings::current()->update(['toil_leave_type_id' => null]);

        $this->assertSame(
            ['compensation' => ['Overtime can\'t be taken as time off until a time-off-in-lieu leave type is set in Policies → Overtime.']],
            $this->refused(fn () => $this->submit('2026-06-17', '17:00', '19:00', compensation: OvertimeCompensation::TimeOff)),
        );
    }
}
