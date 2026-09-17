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

    private function employeeOn(WorkSchedule $schedule): Employee
    {
        return Employee::factory()->create(['work_schedule_id' => $schedule->id]);
    }

    private function punch(Employee $employee, string $dateTime, string $type): AttendanceLog
    {
        return AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => $dateTime,
            'punch_type' => $type,
        ]);
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

        $this->assertSame(AttendanceStatus::Late, $row->status);
        // Full gap from start_time (25), not from the end of the 10min grace.
        $this->assertSame(25, $row->late_minutes);
    }

    public function test_early_departure_reports_correct_minutes(): void
    {
        $employee = $this->employeeOn($this->schedule());
        $this->punch($employee, self::MONDAY.' 07:55:00', 'in');
        $this->punch($employee, self::MONDAY.' 16:00:00', 'out');

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::MONDAY));

        $this->assertSame(60, $row->early_leave_minutes);
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
        $this->assertNotNull($row->last_out);
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
        $this->assertNotNull($row->first_in);
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
        $this->assertNotNull($saturday->last_out);
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
        $this->assertNotNull($tuesday->last_out);
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
        $this->assertNotNull($row->last_out);
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
    // only prove the work_schedule_id path is used, not the default's. ---

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

        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, $row->late_minutes);
    }
}
