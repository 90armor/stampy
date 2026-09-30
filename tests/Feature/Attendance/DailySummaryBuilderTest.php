<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClassConstant;
use Tests\TestCase;

class DailySummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-02-02';

    private const FRIDAY = '2026-02-06';

    private const SATURDAY = '2026-02-07';

    private function schedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create(array_merge([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
        ], $overrides));
    }

    /**
     * An employee on a specific schedule, regardless of whether it's the
     * current default: creation always assigns whatever IS default at the
     * time (see Employee::booted()), so this overwrites that one automatic
     * assignment — still the employee's only row, still effective from
     * their join_date — to point at $schedule instead. See
     * EmployeeScheduleAssignmentTest for creation/scheduleOn() resolution
     * itself; this file only cares that build() uses whatever schedule ends
     * up assigned.
     */
    private function employeeOn(WorkSchedule $schedule): Employee
    {
        if (WorkSchedule::default() === null) {
            WorkSchedule::factory()->create(['is_default' => true]);
        }

        $employee = Employee::factory()->create();
        $employee->scheduleAssignments()->update(['work_schedule_id' => $schedule->id]);

        return $employee;
    }

    private function punch(Employee $employee, string $dateTime, string $type): AttendanceLog
    {
        return AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => $dateTime,
            'punch_type' => $type,
        ]);
    }

    /**
     * @return list<string> the Y-m-d dates that have a row for the employee
     */
    private function datesBuilt(Employee $employee): array
    {
        return DailyAttendance::where('employee_id', $employee->id)->orderBy('work_date')->get()
            ->map(fn ($row) => $row->work_date->format('Y-m-d'))->all();
    }

    public function test_rebuild_around_covers_the_day_before_and_after_a_single_date(): void
    {
        $employee = $this->employeeOn($this->schedule());

        $built = app(DailySummaryBuilder::class)->rebuildAround($employee, Carbon::parse('2026-02-04'));

        $this->assertSame(3, $built);
        $this->assertSame(['2026-02-03', '2026-02-04', '2026-02-05'], $this->datesBuilt($employee));
    }

    public function test_rebuild_around_widens_a_range_by_one_day_each_side(): void
    {
        $employee = $this->employeeOn($this->schedule());

        $built = app(DailySummaryBuilder::class)->rebuildAround($employee, Carbon::parse('2026-02-04'), Carbon::parse('2026-02-06'));

        $this->assertSame(5, $built);
        $this->assertSame(['2026-02-03', '2026-02-04', '2026-02-05', '2026-02-06', '2026-02-07'], $this->datesBuilt($employee));
    }

    public function test_rebuild_around_never_builds_before_the_join_date(): void
    {
        $this->travelTo(Carbon::parse('2026-02-20 12:00:00'));
        $employee = $this->employeeOn($this->schedule());
        $employee->update(['join_date' => '2026-02-04']);

        app(DailySummaryBuilder::class)->rebuildAround($employee, Carbon::parse('2026-02-04'));

        $this->assertSame(['2026-02-04', '2026-02-05'], $this->datesBuilt($employee));
    }

    public function test_rebuild_around_never_builds_after_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-04 12:00:00'));
        $employee = $this->employeeOn($this->schedule());

        app(DailySummaryBuilder::class)->rebuildAround($employee, Carbon::parse('2026-02-04'));

        $this->assertSame(['2026-02-03', '2026-02-04'], $this->datesBuilt($employee));
    }

    public function test_rebuild_from_covers_the_effective_date_through_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $employee = $this->employeeOn($this->schedule());

        $built = app(DailySummaryBuilder::class)->rebuildFrom($employee, Carbon::parse('2026-02-06'));

        $this->assertSame(5, $built);
        $this->assertSame(['2026-02-06', '2026-02-07', '2026-02-08', '2026-02-09', '2026-02-10'], $this->datesBuilt($employee));
    }

    public function test_rebuild_from_never_builds_before_the_join_date(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $employee = $this->employeeOn($this->schedule());
        $employee->update(['join_date' => '2026-02-08']);

        app(DailySummaryBuilder::class)->rebuildFrom($employee, Carbon::parse('2026-02-01'));

        $this->assertSame(['2026-02-08', '2026-02-09', '2026-02-10'], $this->datesBuilt($employee));
    }

    public function test_rebuild_from_a_future_date_builds_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $employee = $this->employeeOn($this->schedule());

        $built = app(DailySummaryBuilder::class)->rebuildFrom($employee, Carbon::parse('2026-02-15'));

        $this->assertSame(0, $built);
        $this->assertSame([], $this->datesBuilt($employee));
    }

    public function test_normal_day_is_present_with_correct_worked_minutes(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 07:55:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:05:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        // 07:55 -> 17:05 = 550 minutes, minus 60 break = 490.
        $this->assertSame(490, $row->worked_minutes);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
    }

    public function test_arrival_within_grace_is_not_late(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:08:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->late_minutes);
    }

    public function test_arrival_past_grace_is_late_from_start_time(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:25:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        // Present, not a separate status — a late arrival is a timing
        // exception, not a different attendance status (see
        // AttendanceStatus's doc comment).
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertTrue($row->isLate());
        // Full gap from start_time (25), not from the end of the 10min grace.
        $this->assertSame(25, $row->late_minutes);
    }

    public function test_arrival_exactly_at_the_end_of_grace_is_not_late(): void
    {
        $employee = $this->employeeOn($this->schedule(['grace_minutes' => 10]));
        $this->punch($employee, self::MONDAY.' 08:10:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertFalse($row->isLate());
    }

    public function test_arrival_one_minute_past_grace_is_late_by_the_full_gap_from_start_time(): void
    {
        $employee = $this->employeeOn($this->schedule(['grace_minutes' => 10]));
        $this->punch($employee, self::MONDAY.' 08:11:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertTrue($row->isLate());
        // 11 (08:00 -> 08:11), never 1 (the minutes past the end of grace).
        $this->assertSame(11, $row->late_minutes);
    }

    /**
     * Minute arithmetic truncates, never rounds: where rounding is
     * ambiguous attendance favours the employee, and docking someone over
     * seconds is indefensible (especially once this feeds payroll). See
     * CLAUDE.md's note beside the work_schedules table.
     */
    public function test_seconds_never_count_against_the_employee_late_and_early_minutes_truncate(): void
    {
        $schedule = $this->schedule(['grace_minutes' => 10]);

        // [in, out, expected late minutes, expected early-leave minutes]
        $cases = [
            ['08:10:59', '17:00:00', 0, 0],   // 59s into the last grace minute: not late
            ['08:11:00', '17:00:00', 11, 0],  // the first second that is late
            ['08:11:59', '17:00:00', 11, 0],  // 11m59s late is 11, not 12
            ['08:00:00', '16:59:01', 0, 0],   // 59s short of end_time: not early
            ['08:00:00', '16:58:01', 0, 1],   // 1m59s short is 1, not 2
        ];

        foreach ($cases as [$in, $out, $expectedLate, $expectedEarly]) {
            $employee = $this->employeeOn($schedule);
            $this->punch($employee, self::MONDAY.' '.$in, 'in');
            $this->punch($employee, self::MONDAY.' '.$out, 'out');

            $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

            $this->assertSame($expectedLate, $row->late_minutes, "in {$in} / out {$out}: late");
            $this->assertSame($expectedEarly, $row->early_leave_minutes, "in {$in} / out {$out}: early");
        }
    }

    public function test_early_departure_reports_correct_minutes(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 07:55:00', 'in');
        $this->punch($employee, self::MONDAY.' 16:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        // Present, not a separate status — an early departure is a timing
        // exception, not a different attendance status (see
        // AttendanceStatus's doc comment).
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertTrue($row->leftEarly());
        $this->assertSame(60, $row->early_leave_minutes);
    }

    public function test_a_day_both_late_and_early_is_still_present_with_both_fields_set(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:25:00', 'in');
        $this->punch($employee, self::MONDAY.' 16:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(25, $row->late_minutes);
        $this->assertSame(60, $row->early_leave_minutes);
        $this->assertTrue($row->isLate());
        $this->assertTrue($row->leftEarly());
        $this->assertTrue($row->hasTimingException());
    }

    public function test_display_variant_is_status_only_present_whether_or_not_the_day_had_a_timing_exception(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $monday = Carbon::parse(self::MONDAY);

        $this->punch($employee, $monday->format('Y-m-d').' 07:55:00', 'in');
        $this->punch($employee, $monday->format('Y-m-d').' 17:05:00', 'out');
        $clean = app(DailySummaryBuilder::class)->build($employee, $monday);
        $this->assertSame('present', $clean->displayVariant());

        $tuesday = $monday->copy()->addDay();
        $this->punch($employee, $tuesday->format('Y-m-d').' 08:25:00', 'in');
        $this->punch($employee, $tuesday->format('Y-m-d').' 17:00:00', 'out');
        $lateOnly = app(DailySummaryBuilder::class)->build($employee, $tuesday);
        $this->assertTrue($lateOnly->isLate());
        $this->assertSame('present', $lateOnly->displayVariant());

        $wednesday = $tuesday->copy()->addDay();
        $this->punch($employee, $wednesday->format('Y-m-d').' 07:55:00', 'in');
        $this->punch($employee, $wednesday->format('Y-m-d').' 16:00:00', 'out');
        $earlyOnly = app(DailySummaryBuilder::class)->build($employee, $wednesday);
        $this->assertTrue($earlyOnly->leftEarly());
        $this->assertSame('present', $earlyOnly->displayVariant());

        $thursday = $wednesday->copy()->addDay();
        $this->punch($employee, $thursday->format('Y-m-d').' 08:25:00', 'in');
        $this->punch($employee, $thursday->format('Y-m-d').' 16:00:00', 'out');
        $both = app(DailySummaryBuilder::class)->build($employee, $thursday);
        $this->assertTrue($both->isLate() && $both->leftEarly());
        $this->assertSame('present', $both->displayVariant());
    }

    public function test_in_only_is_incomplete_with_zero_minutes(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 07:55:00', 'in');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
        $this->assertSame(0, $row->worked_minutes);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
    }

    public function test_out_only_is_incomplete_with_zero_minutes(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 17:05:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
        $this->assertSame(0, $row->worked_minutes);
    }

    public function test_no_punches_on_a_workday_is_absent(): void
    {
        $employee = $this->employeeOn($this->schedule());

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Absent, $row->status);
    }

    public function test_no_punches_on_a_non_workday_is_off(): void
    {
        $employee = $this->employeeOn($this->schedule());

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::SATURDAY));

        $this->assertSame(AttendanceStatus::Off, $row->status);
    }

    public function test_overnight_shift_belongs_to_the_first_date(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 20:00:00', 'in');
        $this->punch($employee, '2026-02-03 02:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(self::MONDAY, $row->work_date->format('Y-m-d'));
        // 20:00 -> 02:00 next day = 360 minutes, minus 60 break = 300.
        $this->assertSame(300, $row->worked_minutes);
    }

    public function test_overnight_out_punch_does_not_leak_into_the_next_day(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::FRIDAY.' 08:00:00', 'in');
        $this->punch($employee, self::SATURDAY.' 01:00:00', 'out'); // 17h later, within 18h.

        $builder = app(DailySummaryBuilder::class);
        $friday = $builder->build($employee, Carbon::parse(self::FRIDAY));
        $saturday = $builder->build($employee, Carbon::parse(self::SATURDAY));

        $this->assertSame(AttendanceStatus::Present, $friday->status);
        // 08:00 Fri -> 01:00 Sat = 1020 minutes, minus 60 break = 960.
        $this->assertSame(960, $friday->worked_minutes);

        // Saturday's only punch is the tail end of Friday's shift, already
        // claimed above — it must not also read as Saturday's own "forgot to
        // punch in" signal.
        $this->assertSame(AttendanceStatus::Off, $saturday->status);
        $this->assertNull($saturday->last_out);
    }

    public function test_a_voided_in_punch_is_excluded_from_first_in(): void
    {
        $employee = $this->employeeOn($this->schedule());
        AttendanceLog::factory()->voided()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::MONDAY.' 07:55:00',
            'punch_type' => 'in',
        ]);
        $this->punch($employee, self::MONDAY.' 17:05:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        // With the in-punch voided, first_in must come back empty — the
        // 17:05 out-punch is then this day's own unclaimed candidate (no
        // earlier in-punch exists at all, voided or not).
        $this->assertNull($row->first_in);
        $this->assertSame(self::MONDAY.' 17:05:00', $row->last_out->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
    }

    public function test_a_voided_out_punch_is_excluded_from_the_overnight_pairing(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 20:00:00', 'in');
        AttendanceLog::factory()->voided()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-02-03 02:00:00',
            'punch_type' => 'out',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        // The only candidate last_out is voided, so first_in stands alone —
        // an incomplete shift, not the paired 300-minute overnight shift
        // this same punch pair produces in the un-voided version of this
        // scenario above.
        $this->assertSame(self::MONDAY.' 20:00:00', $row->first_in->format('Y-m-d H:i:s'));
        $this->assertNull($row->last_out);
        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
    }

    public function test_a_voided_in_punch_no_longer_claims_the_next_days_out_candidate(): void
    {
        $employee = $this->employeeOn($this->schedule());
        AttendanceLog::factory()->voided()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::FRIDAY.' 08:00:00',
            'punch_type' => 'in',
        ]);
        $this->punch($employee, self::SATURDAY.' 01:00:00', 'out');

        $saturday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::SATURDAY));

        // In the un-voided version of this scenario (the test above this
        // one), Friday's in-punch claims Saturday's 01:00 out-punch as its
        // own overnight tail, and Saturday is built as Off. With Friday's
        // in-punch voided, that claim must not happen — the "claimed by an
        // earlier shift" check has to see the voided punch as absent too,
        // not just first_in and the direct pairing queries.
        $this->assertSame(AttendanceStatus::Incomplete, $saturday->status);
        $this->assertSame(self::SATURDAY.' 01:00:00', $saturday->last_out->format('Y-m-d H:i:s'));
    }

    public function test_unclaimed_out_punch_more_than_18h_from_any_in_is_still_incomplete(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
        // 19 hours after Monday's in — outside Monday's 18h pairing window,
        // and this employee has no other in-punch nearby, so Tuesday's build
        // must not silently treat it as claimed by anything.
        $this->punch($employee, '2026-02-03 03:00:00', 'out');

        $tuesday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse('2026-02-03'));

        $this->assertSame(AttendanceStatus::Incomplete, $tuesday->status);
        $this->assertSame(0, $tuesday->worked_minutes);
        $this->assertSame('2026-02-03 03:00:00', $tuesday->last_out->format('Y-m-d H:i:s'));
    }

    public function test_out_beyond_18_hours_is_not_paired(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
        // 19 hours later — outside the 18h pairing window.
        $this->punch($employee, '2026-02-03 03:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
        $this->assertSame(0, $row->worked_minutes);
        $this->assertNull($row->last_out);
    }

    public function test_a_gap_of_exactly_17h59m_pairs(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
        $this->punch($employee, '2026-02-03 01:59:00', 'out'); // 17h59m later.

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame('2026-02-03 01:59:00', $row->last_out->format('Y-m-d H:i:s'));
    }

    public function test_a_gap_of_exactly_18h00m00s_pairs(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
        $this->punch($employee, '2026-02-03 02:00:00', 'out'); // Exactly 18h later.

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame('2026-02-03 02:00:00', $row->last_out->format('Y-m-d H:i:s'));
        // 18h = 1080 minutes, minus the 60 minute break.
        $this->assertSame(1020, $row->worked_minutes);
    }

    public function test_a_gap_of_18h01m_does_not_pair(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
        $this->punch($employee, '2026-02-03 02:01:00', 'out'); // 18h01m later.

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
        $this->assertNull($row->last_out);
    }

    private function maxShiftHours(): int
    {
        return (new ReflectionClassConstant(DailySummaryBuilder::class, 'MAX_SHIFT_HOURS'))->getValue();
    }

    /**
     * The shift window is applied in two independent places: pairing an out
     * to its in (the earlier day), and deciding whether an out-only day's
     * punch is already claimed by an earlier shift (the later day). If the
     * two ever disagree at the boundary, one out-punch becomes both days'
     * last_out — counted twice. Both are pinned here against the same
     * constant, and in both build orders.
     */
    public function test_an_out_exactly_the_shift_window_after_an_in_is_claimed_once_by_the_earlier_day(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $in = Carbon::parse(self::MONDAY.' 08:00:00');
        $out = $in->copy()->addHours($this->maxShiftHours());
        $this->assertSame('2026-02-03', $out->format('Y-m-d'), 'premise: the out-punch lands on the next day');

        $this->punch($employee, $in->format('Y-m-d H:i:s'), 'in');
        $this->punch($employee, $out->format('Y-m-d H:i:s'), 'out');

        foreach ([['2026-02-02', '2026-02-03'], ['2026-02-03', '2026-02-02']] as $order) {
            $rows = [];
            foreach ($order as $date) {
                $rows[$date] = app(DailySummaryBuilder::class)->build($employee, Carbon::parse($date));
            }

            $label = 'build order '.implode(' then ', $order);
            $monday = $rows['2026-02-02'];
            $tuesday = $rows['2026-02-03'];

            $this->assertSame(AttendanceStatus::Present, $monday->status, $label);
            $this->assertSame($out->format('Y-m-d H:i:s'), $monday->last_out->format('Y-m-d H:i:s'), $label);
            $this->assertSame($this->maxShiftHours() * 60 - 60, $monday->worked_minutes, $label);

            // The same punch must not also surface as Tuesday's own.
            $this->assertNull($tuesday->last_out, $label);
            $this->assertNull($tuesday->first_in, $label);
            $this->assertSame(AttendanceStatus::Absent, $tuesday->status, $label);
        }
    }

    public function test_an_out_one_second_past_the_shift_window_belongs_only_to_the_later_day(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $in = Carbon::parse(self::MONDAY.' 08:00:00');
        $out = $in->copy()->addHours($this->maxShiftHours())->addSecond();

        $this->punch($employee, $in->format('Y-m-d H:i:s'), 'in');
        $this->punch($employee, $out->format('Y-m-d H:i:s'), 'out');

        foreach ([['2026-02-02', '2026-02-03'], ['2026-02-03', '2026-02-02']] as $order) {
            $rows = [];
            foreach ($order as $date) {
                $rows[$date] = app(DailySummaryBuilder::class)->build($employee, Carbon::parse($date));
            }

            $label = 'build order '.implode(' then ', $order);

            $this->assertSame(AttendanceStatus::Incomplete, $rows['2026-02-02']->status, $label);
            $this->assertNull($rows['2026-02-02']->last_out, $label);

            $this->assertSame(AttendanceStatus::Incomplete, $rows['2026-02-03']->status, $label);
            $this->assertSame($out->format('Y-m-d H:i:s'), $rows['2026-02-03']->last_out->format('Y-m-d H:i:s'), $label);
        }
    }

    public function test_an_in_punch_at_midnight_belongs_to_the_new_day_and_23_59_59_to_the_old_one(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 23:59:59', 'in');
        $this->punch($employee, '2026-02-03 00:00:00', 'in');

        $monday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));
        $tuesday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse('2026-02-03'));

        $this->assertSame(self::MONDAY.' 23:59:59', $monday->first_in->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-03 00:00:00', $tuesday->first_in->format('Y-m-d H:i:s'));
    }

    public function test_an_out_only_punch_at_midnight_belongs_to_the_new_day_and_23_59_59_to_the_old_one(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 23:59:59', 'out');
        $this->punch($employee, '2026-02-03 00:00:00', 'out');

        $monday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));
        $tuesday = app(DailySummaryBuilder::class)->build($employee, Carbon::parse('2026-02-03'));

        $this->assertSame(self::MONDAY.' 23:59:59', $monday->last_out->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-03 00:00:00', $tuesday->last_out->format('Y-m-d H:i:s'));
    }

    public function test_a_shift_no_longer_than_the_break_reports_zero_worked_minutes_never_negative(): void
    {
        $schedule = $this->schedule(['break_minutes' => 60]);

        // [out time, expected worked minutes] for an 08:00 in.
        foreach ([['08:30:00', 0], ['09:00:00', 0], ['09:01:00', 1]] as [$outTime, $expected]) {
            $employee = $this->employeeOn($schedule);
            $this->punch($employee, self::MONDAY.' 08:00:00', 'in');
            $this->punch($employee, self::MONDAY.' '.$outTime, 'out');

            $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

            $this->assertSame(AttendanceStatus::Present, $row->status, "out at {$outTime}");
            $this->assertSame($expected, $row->worked_minutes, "out at {$outTime}");
        }
    }

    public function test_double_tap_day_matches_a_clean_day(): void
    {
        $schedule = $this->schedule();
        $clean = $this->employeeOn($schedule);
        $this->punch($clean, self::MONDAY.' 07:55:00', 'in');
        $this->punch($clean, self::MONDAY.' 17:00:00', 'out');

        $noisy = $this->employeeOn($schedule);
        $this->punch($noisy, self::MONDAY.' 07:55:00', 'in');
        $this->punch($noisy, self::MONDAY.' 07:55:08', 'in'); // extra tap, same minute
        $this->punch($noisy, self::MONDAY.' 17:00:00', 'out');
        $this->punch($noisy, self::MONDAY.' 17:00:05', 'out'); // extra tap, same minute

        $cleanRow = app(DailySummaryBuilder::class)->build($clean, Carbon::parse(self::MONDAY));
        $noisyRow = app(DailySummaryBuilder::class)->build($noisy, Carbon::parse(self::MONDAY));

        $this->assertSame($cleanRow->status, $noisyRow->status);
        $this->assertSame($cleanRow->worked_minutes, $noisyRow->worked_minutes);
        $this->assertSame($cleanRow->late_minutes, $noisyRow->late_minutes);
        $this->assertSame($cleanRow->early_leave_minutes, $noisyRow->early_leave_minutes);
    }

    public function test_rebuild_is_idempotent_and_downgrades_when_punches_are_removed(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $in = $this->punch($employee, self::MONDAY.' 07:55:00', 'in');
        $out = $this->punch($employee, self::MONDAY.' 17:00:00', 'out');

        $builder = app(DailySummaryBuilder::class);

        $first = $builder->build($employee, Carbon::parse(self::MONDAY));
        $this->assertSame(AttendanceStatus::Present, $first->status);

        $second = $builder->build($employee, Carbon::parse(self::MONDAY));
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyAttendance::count());
        $this->assertSame($first->worked_minutes, $second->worked_minutes);

        $out->delete();
        $in->delete();

        $third = $builder->build($employee, Carbon::parse(self::MONDAY));
        $this->assertSame($first->id, $third->id);
        $this->assertSame(AttendanceStatus::Absent, $third->status);
        $this->assertSame(0, $third->worked_minutes);
    }

    // --- Schedule variation: factory-made schedules, not the seeder. These
    // only prove the employee's own assigned schedule is used, not the default's. ---

    public function test_short_morning_schedule_normal_day(): void
    {
        $schedule = $this->schedule(['start_time' => '08:00:00', 'end_time' => '12:00:00', 'break_minutes' => 0]);
        $employee = $this->employeeOn($schedule);
        $this->punch($employee, self::MONDAY.' 07:58:00', 'in');
        $this->punch($employee, self::MONDAY.' 12:02:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
        // 07:58 -> 12:02 = 244 minutes, no break. Confirms the default
        // schedule's 17:00 end is not used anywhere in this calculation.
        $this->assertSame(244, $row->worked_minutes);
    }

    public function test_short_morning_schedule_early_leave_uses_its_own_end_time(): void
    {
        $schedule = $this->schedule(['start_time' => '08:00:00', 'end_time' => '12:00:00', 'break_minutes' => 0]);
        $employee = $this->employeeOn($schedule);
        $this->punch($employee, self::MONDAY.' 07:58:00', 'in');
        $this->punch($employee, self::MONDAY.' 11:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        // 60 minutes early against this schedule's own 12:00 end — not 360,
        // which is what you'd get comparing against the default's 17:00.
        $this->assertSame(60, $row->early_leave_minutes);
    }

    public function test_afternoon_schedule_late_arrival(): void
    {
        $schedule = $this->schedule(['start_time' => '13:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0]);
        $employee = $this->employeeOn($schedule);
        $this->punch($employee, self::MONDAY.' 13:30:00', 'in');
        $this->punch($employee, self::MONDAY.' 17:05:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertTrue($row->isLate());
        $this->assertSame(30, $row->late_minutes);
    }
}
