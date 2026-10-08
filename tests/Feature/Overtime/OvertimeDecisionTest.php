<?php

namespace Tests\Feature\Overtime;

use App\Enums\ApprovalOutcome;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Exceptions\OvertimeValidationException;
use App\Exceptions\StaleDecisionException;
use App\Livewire\Employees\StatusModal;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\EmployeeLifecycle;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4c — deciding and cancelling overtime (CLAUDE.md, Phase 4, rules
 * 4–6, 17, 19): the approval steps, compensation changes, the limit re-check,
 * rebuild-and-reconcile, cancel timing and the cancel-and-refile correction,
 * and deactivation. The clock is Mon 15 Jun 2026 12:00; the schedule is
 * Mon–Fri 08:00–17:00 with a 12:00–13:00 break.
 */
class OvertimeDecisionTest extends TestCase
{
    use RefreshDatabase;

    private OvertimeRequestService $service;

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

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $toil->id]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');

        $this->service = app(OvertimeRequestService::class);
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    /** Filed by the employee; $to before $from is the next day. */
    private function file(string $date, string $from, string $to, OvertimeCompensation $compensation = OvertimeCompensation::Pay, ?User $actor = null): OvertimeRequest
    {
        $start = Carbon::parse("{$date} {$from}");
        $end = Carbon::parse("{$date} {$to}");

        return $this->service->submit($this->employee, Carbon::parse($date), $start, $end->lte($start) ? $end->addDay() : $end, $compensation, null, $actor ?? $this->employee->user)['request'];
    }

    private function worked(string $date, string $in, string $out): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$in}", 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$out}", 'punch_type' => 'out']);
    }

    private function posted(): string
    {
        return number_format((float) LeaveAdjustment::where('employee_id', $this->employee->id)->sum('days'), 1);
    }

    // ── Steps ───────────────────────────────────────────────────────────

    public function test_the_manager_then_an_admin_approve_and_a_past_claim_is_rebuilt_and_credited(): void
    {
        // Sat 13 Jun, 08:00–13:00 worked as time off: 4h, half a day.
        $this->worked('2026-06-13', '08:00:00', '13:00:00');
        $request = $this->file('2026-06-13', '08:00', '13:00', OvertimeCompensation::TimeOff);

        $afterManager = $this->service->approve($request, $this->manager->user, 'Fine');
        $this->assertSame([OvertimeStatus::Pending, 2, null], [$afterManager['request']->status, $afterManager['request']->current_step, $afterManager['rebuildError']]);
        $this->assertSame(0, $this->employee->dailyAttendances()->count());

        $done = $this->service->approve($request, $this->admin);
        $this->assertSame([OvertimeStatus::Approved, null, null], [$done['request']->status, $done['request']->current_step, $done['rebuildError']]);
        $this->assertSame(240, $this->employee->dailyAttendances()->sole()->overtime_workday_minutes);
        $this->assertSame('0.5', $this->posted());
        $this->assertSame([[1, 'approved', 'Fine'], [2, 'approved', null]], $request->approvalSteps()->get()->map(fn ($step) => [$step->step, $step->outcome->value, $step->note])->all());
    }

    public function test_an_admin_at_step_one_decides_both_steps(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');

        $this->service->approve($request, $this->admin, 'Covering for the manager');

        $this->assertSame(OvertimeStatus::Approved, $request->fresh()->status);
        $this->assertSame([ApprovalOutcome::Approved, ApprovalOutcome::Approved], $request->approvalSteps()->pluck('outcome')->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $request->approvalSteps()->pluck('decided_by')->all());
    }

    public function test_reject_needs_a_note_and_ends_the_request(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');

        try {
            $this->service->reject($request, $this->manager->user, '  ');
            $this->fail('A rejection without a note was accepted.');
        } catch (OvertimeValidationException $e) {
            $this->assertSame(['note'], array_keys($e->errors()));
        }

        $this->service->reject($request, $this->manager->user, 'Not budgeted');

        $this->assertSame([OvertimeStatus::Rejected, null], [$request->fresh()->status, $request->fresh()->current_step]);
        $this->assertSame([ApprovalOutcome::Rejected, 'Not budgeted'], [$request->approvalSteps()->sole()->outcome, $request->approvalSteps()->sole()->note]);
    }

    public function test_no_decision_on_a_closed_request_or_a_step_that_moved_on(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');

        // The manager saw step 1, but an admin decided it meanwhile.
        $this->service->approve($request, $this->admin);

        try {
            $this->service->approve($request, $this->manager->user, null, null, expectedStep: 1);
            $this->fail('A stale decision was accepted.');
        } catch (StaleDecisionException $e) {
            $this->assertSame('This request has already been approved.', $e->getMessage());
        }

        $other = $this->file('2026-06-17', '17:00', '19:00');
        $this->service->approve($other, $this->manager->user);

        try {
            $this->service->reject($other, $this->admin, 'No', expectedStep: 1);
            $this->fail('A decision on a step that moved on was accepted.');
        } catch (StaleDecisionException $e) {
            $this->assertStringContainsString('moved on since you opened it', $e->getMessage());
        }

        $this->service->reject($other, $this->admin, 'No');

        $this->expectException(StaleDecisionException::class);
        $this->service->cancel($other->fresh(), $this->admin);
    }

    public function test_only_an_eligible_approver_decides(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');
        $stranger = $this->person('Stranger', 'manager');

        $this->expectException(AuthorizationException::class);

        $this->service->approve($request, $stranger->user);
    }

    // ── Compensation ───────────────────────────────────────────────────

    public function test_the_approver_may_change_the_compensation_both_ways_and_the_step_says_so(): void
    {
        $this->worked('2026-06-13', '08:00:00', '13:00:00');
        $toTimeOff = $this->file('2026-06-13', '08:00', '13:00');

        $this->service->approve($toTimeOff, $this->admin, 'Rather than pay', OvertimeCompensation::TimeOff);

        $this->assertSame(OvertimeCompensation::TimeOff, $toTimeOff->fresh()->compensation);
        $this->assertSame('Compensation changed: pay → time off. Rather than pay', $toTimeOff->approvalSteps()->first()->note);
        $this->assertSame('0.5', $this->posted());

        $toPay = $this->file('2026-06-16', '17:00', '19:00', OvertimeCompensation::TimeOff);
        $this->service->approve($toPay, $this->manager->user, null, OvertimeCompensation::Pay);

        $this->assertSame(OvertimeCompensation::Pay, $toPay->fresh()->compensation);
        $this->assertSame('Compensation changed: time off → pay', $toPay->approvalSteps()->sole()->note);
    }

    public function test_approving_as_time_off_needs_a_toil_type(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');
        OvertimeSettings::current()->update(['toil_leave_type_id' => null]);

        try {
            $this->service->approve($request, $this->admin, null, OvertimeCompensation::TimeOff);
            $this->fail('Approved as time off with no TOIL type.');
        } catch (OvertimeValidationException $e) {
            $this->assertSame(['compensation'], array_keys($e->errors()));
        }

        $this->assertSame([OvertimeStatus::Pending, OvertimeCompensation::Pay], [$request->fresh()->status, $request->fresh()->compensation]);
    }

    // ── The limit re-check ─────────────────────────────────────────────

    public function test_the_final_approval_checks_the_limits_again_and_needs_an_override_if_they_changed(): void
    {
        $request = $this->file('2026-06-16', '17:00', '19:00');
        OvertimeSettings::current()->update(['max_overtime_minutes_per_day' => 60]);

        // The manager's step isn't the final one: no re-check.
        $this->service->approve($request, $this->manager->user);
        $this->assertSame(2, $request->fresh()->current_step);

        try {
            $this->service->approve($request, $this->admin);
            $this->fail('Approved over a limit without an override.');
        } catch (OvertimeValidationException $e) {
            $this->assertSame(['overtime', 'limit_override_reason'], array_keys($e->errors()));
            $this->assertSame('This is 2h 00m of overtime; the limit is 1h 00m a day.', $e->errors()['overtime'][0]);
        }

        $this->service->approve($request, $this->admin, null, null, null, 'Agreed before the limit changed');

        $this->assertSame([OvertimeStatus::Approved, 'Agreed before the limit changed'], [$request->fresh()->status, $request->fresh()->limit_override_reason]);
    }

    // ── Rebuild and reconcile ──────────────────────────────────────────

    public function test_approving_and_cancelling_rebuild_a_past_or_todays_date_and_never_a_future_one(): void
    {
        // Today: in 06:00 for an early start, out 17:00.
        $this->worked('2026-06-15', '06:00:00', '17:00:00');
        $today = $this->file('2026-06-15', '06:00', '08:00', OvertimeCompensation::TimeOff);
        $this->service->approve($today, $this->admin);

        $row = $this->employee->dailyAttendances()->whereDate('work_date', '2026-06-15')->sole();
        $this->assertSame([$today->id, 120], [$row->overtime_request_id, $row->overtime_workday_minutes]);

        // Future: nothing built.
        $future = $this->file('2026-06-16', '17:00', '19:00', OvertimeCompensation::TimeOff);
        $this->assertNull($this->service->approve($future, $this->admin)['rebuildError']);
        $this->assertSame(0, $this->employee->dailyAttendances()->whereDate('work_date', '2026-06-16')->count());

        // Past, time off: credited, then taken back on cancel.
        $this->worked('2026-06-13', '08:00:00', '13:00:00');
        $past = $this->file('2026-06-13', '08:00', '13:00', OvertimeCompensation::TimeOff);
        $this->service->approve($past, $this->admin);
        $this->assertSame('0.5', $this->posted());

        $this->service->cancel($past, $this->admin);
        $this->assertSame('0.0', $this->posted());
        $this->assertSame([null, 0], [
            $this->employee->dailyAttendances()->whereDate('work_date', '2026-06-13')->sole()->overtime_request_id,
            $this->employee->dailyAttendances()->whereDate('work_date', '2026-06-13')->sole()->overtime_workday_minutes,
        ]);
    }

    public function test_a_failed_rebuild_is_reported_and_the_decision_stands(): void
    {
        $this->app->instance(DailySummaryBuilder::class, new class extends DailySummaryBuilder
        {
            public function rebuildOvertimeDate(OvertimeRequest $request): int
            {
                throw new RuntimeException('database went away');
            }
        });
        $service = app(OvertimeRequestService::class);
        $request = $this->file('2026-06-12', '17:00', '19:00');

        $result = $service->approve($request, $this->admin);

        $this->assertSame(OvertimeStatus::Approved, $request->fresh()->status);
        $this->assertSame('Not every affected day may have been rebuilt. Run: php artisan attendance:build-daily --from=2026-06-12 --to=2026-06-12 --employee='.$this->employee->employee_code, $result['rebuildError']);
    }

    // ── Cancelling ──────────────────────────────────────────────────────

    public function test_the_requester_cancels_before_the_window_starts_and_an_admin_any_time(): void
    {
        $tonight = $this->file('2026-06-15', '17:00', '19:00');
        $this->service->cancel($tonight, $this->employee->user);
        $this->assertSame(OvertimeStatus::Cancelled, $tonight->fresh()->status);
        $this->assertSame($this->employee->user_id, $tonight->fresh()->cancelled_by);

        $started = $this->file('2026-06-16', '17:00', '19:00');
        $this->travelTo(Carbon::parse('2026-06-16 17:00:00'));

        try {
            $this->service->cancel($started, $this->employee->user);
            $this->fail('The requester cancelled after the window started.');
        } catch (AuthorizationException) {
        }

        $this->service->cancel($started, $this->admin);
        $this->assertSame(OvertimeStatus::Cancelled, $started->fresh()->status);
    }

    /**
     * There's no edit: an approved window that turned out wrong is cancelled
     * and filed again by an admin, final on submit; the history shows both.
     */
    public function test_an_admin_corrects_an_approved_window_by_cancelling_and_filing_again(): void
    {
        // Saturday: approved 08:00–11:00 (3h, no TOIL yet), but they stayed until 13:00.
        $this->worked('2026-06-13', '08:00:00', '13:00:00');
        $original = $this->file('2026-06-13', '08:00', '11:00', OvertimeCompensation::TimeOff);
        $this->service->approve($original, $this->admin);
        $this->assertSame('0.0', $this->posted());

        $this->service->cancel($original, $this->admin);
        $corrected = $this->service->submit($this->employee, Carbon::parse('2026-06-13'), Carbon::parse('2026-06-13 08:00'), Carbon::parse('2026-06-13 13:00'), OvertimeCompensation::TimeOff, 'Stayed until 13:00', $this->admin)['request'];

        $this->assertSame(OvertimeStatus::Approved, $corrected->status);
        $this->assertSame('0.5', $this->posted());
        $this->assertSame(['cancelled', 'approved'], OvertimeRequest::whereDate('date', '2026-06-13')->orderBy('id')->pluck('status')->map->value->all());
    }

    // ── Deactivation ───────────────────────────────────────────────────

    public function test_deactivation_cancels_overtime_after_the_last_day_and_takes_its_time_off_back(): void
    {
        $this->worked('2026-06-13', '08:00:00', '13:00:00');
        $credited = $this->file('2026-06-13', '08:00', '13:00', OvertimeCompensation::TimeOff);
        $this->service->approve($credited, $this->admin);
        $planned = $this->file('2026-06-16', '17:00', '19:00');
        $kept = $this->file('2026-06-11', '17:00', '19:00');
        $this->assertSame('0.5', $this->posted());

        $lifecycle = app(EmployeeLifecycle::class);
        $effects = $lifecycle->deactivationEffects($this->employee, Carbon::parse('2026-06-12'));
        $this->assertSame([['overtime', $credited->id, 'approved'], ['overtime', $planned->id, 'pending']], array_map(fn ($e) => [$e['kind'], $e['id'], $e['status']], $effects));
        $this->assertSame('Sat 13 Jun, 8:00 AM – 1:00 PM', $effects[0]['dates']);

        $lifecycle->deactivate($this->employee, Carbon::parse('2026-06-12'), $this->admin, $effects);

        $this->assertSame([OvertimeStatus::Cancelled, OvertimeStatus::Cancelled, OvertimeStatus::Pending], [$credited->fresh()->status, $planned->fresh()->status, $kept->fresh()->status]);
        $this->assertSame('0.0', $this->posted());
    }

    public function test_the_deactivation_modal_lists_overtime_with_the_leave(): void
    {
        $this->file('2026-06-16', '17:00', '19:00');

        Livewire::actingAs($this->admin)->test(StatusModal::class)
            ->call('openDeactivate', $this->employee->id)
            ->set('left_on', '2026-06-15')
            ->call('confirm')
            ->assertSee('Overtime after their last day will change')
            ->assertSee('Overtime · Tue 16 Jun, 5:00 PM – 7:00 PM (pending) — cancelled')
            ->assertSee('Deactivate and adjust 1 request')
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('inactive', $this->employee->fresh()->status);
        $this->assertSame(OvertimeStatus::Cancelled, OvertimeRequest::sole()->status);
    }
}
