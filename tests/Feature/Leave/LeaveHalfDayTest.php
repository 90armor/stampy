<?php

namespace Tests\Feature\Leave;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveHalf;
use App\Exceptions\HalfDayLeaveNeedsBreakException;
use App\Livewire\Employees\ScheduleAssignments;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3d — half-day leave timing (CLAUDE.md, Phase 3, rules 18–19), to the
 * minute. The day is Wed 17 Jun 2026; the schedule is 08:00–17:00 with a
 * 60-minute break from 12:00 (PM start 13:00) and 10 minutes' grace.
 */
class LeaveHalfDayTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-17';

    private WorkSchedule $schedule;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check); tests move it within the day.
        $this->travelTo(Carbon::parse('2026-06-18 09:00:00'));

        $this->schedule = WorkSchedule::factory()->withBreakStart()->create(['is_default' => true, 'grace_minutes' => 10]);
        $this->employee = Employee::factory()->create();
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
    }

    private function halfDay(LeaveHalf $half, string $date = self::DAY, ?Employee $employee = null, string $state = 'approved'): Leave
    {
        return Leave::factory()->for($employee ?? $this->employee)->for($this->annual)
            ->between($date, $date)->halfDay($half->value)->{$state}()->create();
    }

    private function punches(?string $in, ?string $out, string $date = self::DAY): void
    {
        foreach (['in' => $in, 'out' => $out] as $type => $time) {
            if ($time !== null) {
                AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$time}", 'punch_type' => $type]);
            }
        }
    }

    private function build(string $date = self::DAY): DailyAttendance
    {
        return app(DailySummaryBuilder::class)->build($this->employee->fresh(), Carbon::parse($date));
    }

    // ── AM leave ───────────────────────────────────────────────────────

    public function test_am_leave_measures_late_from_the_pm_start_plus_grace(): void
    {
        $leave = $this->halfDay(LeaveHalf::Am);
        $this->punches('13:10:59', '17:00:00');

        $row = $this->build();
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame($leave->id, $row->leave_id);
        $this->assertSame(0, $row->late_minutes);

        AttendanceLog::query()->delete();
        $this->punches('13:11:00', '17:00:00');
        $this->assertSame(11, $this->build()->late_minutes);
    }

    public function test_am_leave_worked_minutes_subtract_only_the_break_they_overlap(): void
    {
        $this->halfDay(LeaveHalf::Am);
        $this->punches('13:00:00', '17:00:00');

        $this->assertSame(240, $this->build()->worked_minutes);
    }

    public function test_an_am_leave_employee_arriving_at_8_anyway_is_present_and_not_late(): void
    {
        $this->halfDay(LeaveHalf::Am);
        $this->punches('08:00:00', '17:00:00');

        $row = $this->build();
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->late_minutes);
        // 9 hours, less the hour of break they spanned.
        $this->assertSame(480, $row->worked_minutes);
    }

    public function test_a_punchless_am_leave_day_is_in_progress_until_the_schedule_end(): void
    {
        $this->halfDay(LeaveHalf::Am);

        $this->travelTo(Carbon::parse(self::DAY.' 16:59:59'));
        $this->assertSame(AttendanceStatus::InProgress, $this->build()->status);

        $this->travelTo(Carbon::parse(self::DAY.' 17:00:00'));
        $this->assertSame(AttendanceStatus::Absent, $this->build()->status);
    }

    // ── PM leave ───────────────────────────────────────────────────────

    public function test_pm_leave_measures_early_leave_against_break_start(): void
    {
        $leave = $this->halfDay(LeaveHalf::Pm);
        $this->punches('08:00:00', '12:00:00');

        $row = $this->build();
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame($leave->id, $row->leave_id);
        $this->assertSame(0, $row->early_leave_minutes);
        $this->assertSame(240, $row->worked_minutes);

        AttendanceLog::query()->delete();
        $this->punches('08:00:00', '11:55:00');
        $this->assertSame(5, $this->build()->early_leave_minutes);
    }

    public function test_a_pm_leave_employee_leaving_at_12_30_is_not_early(): void
    {
        $this->halfDay(LeaveHalf::Pm);
        $this->punches('08:00:00', '12:30:00');

        $row = $this->build();
        $this->assertSame(0, $row->early_leave_minutes);
        // 4h30, less the 30 minutes of break they stayed into.
        $this->assertSame(240, $row->worked_minutes);
    }

    public function test_a_punchless_pm_leave_day_is_in_progress_until_noon_then_absent(): void
    {
        $this->halfDay(LeaveHalf::Pm);

        $this->travelTo(Carbon::parse(self::DAY.' 11:59:59'));
        $this->assertSame(AttendanceStatus::InProgress, $this->build()->status);

        $this->travelTo(Carbon::parse(self::DAY.' 12:00:00'));
        $this->assertSame(AttendanceStatus::Absent, $this->build()->status);
    }

    public function test_a_pm_leave_in_only_day_follows_the_pairing_window(): void
    {
        $this->halfDay(LeaveHalf::Pm);
        $this->punches('08:00:00', null);

        $this->travelTo(Carbon::parse(self::DAY.' 23:00:00'));
        $this->assertSame(AttendanceStatus::InProgress, $this->build()->status);

        // Gone home sick without punching out: incomplete once the 18h window closes, like anyone else.
        $this->travelTo(Carbon::parse('2026-06-18 02:00:01'));
        $this->assertSame(AttendanceStatus::Incomplete, $this->build()->status);
    }

    public function test_a_pm_leave_approved_the_same_day_after_an_8_oclock_in_punch(): void
    {
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00'));
        $this->punches('08:00:00', null);
        $this->assertSame(AttendanceStatus::InProgress, $this->build()->status);

        $admin = User::factory()->create()->assignRole('admin');
        $unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid']);
        $leave = app(LeaveRequestService::class)->submit($this->employee, $unpaid, Carbon::parse(self::DAY), Carbon::parse(self::DAY), LeaveHalf::Pm, null, $admin)['leave'];

        $row = DailyAttendance::where('employee_id', $this->employee->id)->first();
        $this->assertSame(AttendanceStatus::InProgress, $row->status);
        $this->assertSame($leave->id, $row->leave_id);

        $this->travelTo(Carbon::parse(self::DAY.' 12:05:00'));
        $this->punches(null, '12:01:00');
        $row = $this->build();
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->early_leave_minutes);
    }

    // ── Other breaks ───────────────────────────────────────────────────

    public function test_a_half_day_on_a_schedule_with_a_30_minute_break(): void
    {
        $short = WorkSchedule::factory()->withBreakStart()->create(['break_minutes' => 30, 'grace_minutes' => 10]);
        EmployeeWorkSchedule::create(['employee_id' => $this->employee->id, 'work_schedule_id' => $short->id, 'effective_from' => '2026-06-01']);
        $this->halfDay(LeaveHalf::Am);

        $this->punches('12:40:59', '17:00:00');
        $this->assertSame(0, $this->build()->late_minutes);

        AttendanceLog::query()->delete();
        $this->punches('12:41:00', '17:00:00');
        $this->assertSame(11, $this->build()->late_minutes);

        AttendanceLog::query()->delete();
        $this->punches('12:30:00', '17:00:00');
        $this->assertSame(270, $this->build()->worked_minutes);
    }

    public function test_full_days_keep_the_flat_break_rule(): void
    {
        // Arriving at 13:00 on an ordinary day still loses the full hour, as before Phase 3d.
        $this->punches('13:00:00', '17:00:00');

        $this->assertSame(180, $this->build()->worked_minutes);
    }

    // ── A schedule without break_start ─────────────────────────────────

    public function test_the_builder_falls_back_to_ordinary_rules_and_warns_when_the_schedule_has_no_break(): void
    {
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break', 'grace_minutes' => 10]);
        EmployeeWorkSchedule::create(['employee_id' => $this->employee->id, 'work_schedule_id' => $noBreak->id, 'effective_from' => '2026-06-01']);
        $leave = $this->halfDay(LeaveHalf::Am);
        $this->punches('13:00:00', '17:00:00');
        Log::spy();

        $row = $this->build();

        // Measured against the whole day: 300 minutes late, and the leave still on the row.
        $this->assertSame(300, $row->late_minutes);
        $this->assertSame($leave->id, $row->leave_id);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, "Leave #{$leave->id}: half-day leave on 2026-06-17") && str_contains($message, 'No break'))->once();
    }

    public function test_assigning_a_schedule_without_a_break_is_refused_while_a_half_day_needs_one(): void
    {
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break']);
        $this->halfDay(LeaveHalf::Am, '2026-06-22', state: 'pending');
        $assigner = app(EmployeeScheduleAssigner::class);

        try {
            $assigner->assign($this->employee->fresh(), $noBreak, Carbon::parse('2026-06-20'));
            $this->fail('The assignment must be refused.');
        } catch (HalfDayLeaveNeedsBreakException $e) {
            $this->assertStringContainsString('Schedule "No break" has no break time set', $e->getMessage());
            $this->assertStringContainsString('pending half day on Mon 22 Jun (AM)', $e->getMessage());
            $this->assertStringContainsString('Set "Break starts" on that schedule in Policies → Schedules first.', $e->getMessage());
        }

        $this->assertSame(1, $this->employee->scheduleAssignments()->count());

        // From the day after the leave, it's fine.
        $assigner->assign($this->employee->fresh(), $noBreak, Carbon::parse('2026-06-23'));
        $this->assertSame(2, $this->employee->scheduleAssignments()->count());
    }

    public function test_a_bulk_move_onto_a_schedule_without_a_break_is_refused_and_moves_nobody(): void
    {
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break']);
        $other = Employee::factory()->create();
        $this->halfDay(LeaveHalf::Pm, '2026-06-22', $other);

        try {
            app(EmployeeScheduleAssigner::class)->bulkReassign($this->schedule, $noBreak, Carbon::parse('2026-06-18'));
            $this->fail('The bulk move must be refused.');
        } catch (HalfDayLeaveNeedsBreakException $e) {
            $this->assertStringContainsString('approved half day on Mon 22 Jun (PM)', $e->getMessage());
            $this->assertStringContainsString('in Policies → Schedules first.', $e->getMessage());
        }

        $this->assertSame(0, EmployeeWorkSchedule::where('work_schedule_id', $noBreak->id)->count());
    }

    public function test_deleting_the_assignment_a_half_day_relies_on_is_refused(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $noBreak = WorkSchedule::factory()->create(['name' => 'Old hours', 'is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2026-01-01']);          // on "Old hours" from joining
        $assignment = EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'work_schedule_id' => $this->schedule->id, 'effective_from' => '2026-06-01']);
        $this->halfDay(LeaveHalf::Am, '2026-06-22', $employee);

        Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('deleteAssignment', $assignment->id)
            ->assertHasErrors(['delete'])
            ->assertSee('Schedule &quot;Old hours&quot; has no break time set', false);

        $this->assertTrue(EmployeeWorkSchedule::whereKey($assignment->id)->exists());
    }

    public function test_the_assign_modal_shows_the_refusal_under_the_schedule_field(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $noBreak = WorkSchedule::factory()->create(['name' => 'No break']);
        $this->halfDay(LeaveHalf::Am, '2026-06-22');

        Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(ScheduleAssignments::class, ['employee' => $this->employee])
            ->call('create')
            ->set('work_schedule_id', $noBreak->id)
            ->set('effective_from', '2026-06-20')
            ->call('assign')
            ->assertHasErrors(['work_schedule_id'])
            ->assertSet('showModal', true);
    }
}
