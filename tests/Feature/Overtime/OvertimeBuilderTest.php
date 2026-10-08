<?php

namespace Tests\Feature\Overtime;

use App\Enums\AttendanceStatus;
use App\Enums\OvertimeStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4b — DailySummaryBuilder writes credited overtime: the approved
 * request for the work date, its minutes per category from the real punches,
 * schedule and holidays, and never a different status.
 */
class OvertimeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-02-02';

    private const SATURDAY = '2026-02-07';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        // 08:00–17:00 Mon–Fri, break 12:00–13:00.
        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 10, 'break_minutes' => 60,
            'break_start' => '12:00:00', 'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->employee = Employee::factory()->create();
    }

    private function punch(string $at, string $type): AttendanceLog
    {
        return AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function request(string $date, string $from, string $to, string $state = 'approved'): OvertimeRequest
    {
        return OvertimeRequest::factory()->for($this->employee)->window($date, $from, $to)->{$state}()->create();
    }

    private function build(string $date): DailyAttendance
    {
        return app(DailySummaryBuilder::class)->build($this->employee->fresh(), Carbon::parse($date))->fresh();
    }

    /** @return array{0: ?int, 1: int, 2: int, 3: int, 4: int} request id, workday, night, rest day, holiday */
    private function overtime(DailyAttendance $row): array
    {
        return [$row->overtime_request_id, $row->overtime_workday_minutes, $row->overtime_night_minutes, $row->overtime_rest_day_minutes, $row->overtime_holiday_minutes];
    }

    public function test_a_present_day_with_approved_overtime_is_credited_and_stays_present(): void
    {
        $request = $this->request(self::MONDAY, '17:00', '19:00');
        $this->punch(self::MONDAY.' 07:58:00', 'in');
        $this->punch(self::MONDAY.' 19:20:45', 'out');

        $row = $this->build(self::MONDAY);

        $this->assertSame([$request->id, 120, 0, 0, 0], $this->overtime($row));
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame('present', $row->displayVariant());
        // Timing is untouched: no late, no early leave.
        $this->assertSame([0, 0], [$row->late_minutes, $row->early_leave_minutes]);
    }

    public function test_an_overnight_out_punch_pairs_and_credits_its_work_date_only(): void
    {
        $request = $this->request(self::MONDAY, '20:00', '01:00');
        $this->punch(self::MONDAY.' 08:00:00', 'in');
        $this->punch('2026-02-03 00:42:00', 'out');

        // 20:00–22:00 workday, 22:00–00:42 night.
        $this->assertSame([$request->id, 120, 162, 0, 0], $this->overtime($this->build(self::MONDAY)));
        $this->assertSame([null, 0, 0, 0, 0], $this->overtime($this->build('2026-02-03')));
    }

    public function test_non_workdays_the_rest_day_and_a_holiday_come_from_the_schedule_settings_and_holidays(): void
    {
        // Saturday: not a workday of the schedule, not the weekly rest day — workday minutes.
        $saturday = $this->request(self::SATURDAY, '08:00', '17:00');
        $this->punch(self::SATURDAY.' 07:55:00', 'in');
        $this->punch(self::SATURDAY.' 17:05:00', 'out');

        $this->assertSame([$saturday->id, 480, 0, 0, 0], $this->overtime($this->build(self::SATURDAY)));

        // Sunday: the weekly rest day.
        $sunday = $this->request('2026-02-08', '08:00', '17:00');
        $this->punch('2026-02-08 08:00:00', 'in');
        $this->punch('2026-02-08 17:00:00', 'out');

        $this->assertSame([$sunday->id, 0, 0, 480, 0], $this->overtime($this->build('2026-02-08')));

        $monday = $this->request(self::MONDAY, '08:00', '17:00');
        $this->punch(self::MONDAY.' 08:00:00', 'in');
        $this->punch(self::MONDAY.' 17:00:00', 'out');
        Holiday::create(['date' => self::MONDAY, 'name' => 'Test holiday']);

        $row = $this->build(self::MONDAY);
        $this->assertSame([$monday->id, 0, 0, 0, 480], $this->overtime($row));
        $this->assertSame(AttendanceStatus::Present, $row->status);
    }

    public function test_an_approved_request_with_no_out_punch_is_named_with_nothing_credited(): void
    {
        $request = $this->request(self::MONDAY, '17:00', '19:00');
        $this->punch(self::MONDAY.' 08:00:00', 'in');

        $row = $this->build(self::MONDAY);

        $this->assertSame([$request->id, 0, 0, 0, 0], $this->overtime($row));
        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
    }

    public function test_pending_rejected_and_cancelled_requests_credit_nothing(): void
    {
        $this->punch(self::MONDAY.' 08:00:00', 'in');
        $this->punch(self::MONDAY.' 19:00:00', 'out');

        foreach (['pending', 'rejected', 'cancelled'] as $state) {
            $request = $this->request(self::MONDAY, '17:00', '19:00', $state);

            $this->assertSame([null, 0, 0, 0, 0], $this->overtime($this->build(self::MONDAY)), $state);

            $request->delete();
        }
    }

    public function test_rebuilding_the_work_date_after_approval_and_after_cancellation(): void
    {
        $builder = app(DailySummaryBuilder::class);
        $this->punch(self::MONDAY.' 08:00:00', 'in');
        $this->punch(self::MONDAY.' 19:00:00', 'out');
        $request = $this->request(self::MONDAY, '17:00', '19:00', 'pending');

        $builder->rebuildOvertimeDate($request);
        $this->assertSame([null, 0, 0, 0, 0], $this->overtime(DailyAttendance::sole()));

        $request->update(['status' => OvertimeStatus::Approved, 'current_step' => null]);
        $this->assertSame(1, $builder->rebuildOvertimeDate($request));
        $this->assertSame([$request->id, 120, 0, 0, 0], $this->overtime(DailyAttendance::sole()));

        $request->update(['status' => OvertimeStatus::Cancelled]);
        $builder->rebuildOvertimeDate($request);
        $this->assertSame([null, 0, 0, 0, 0], $this->overtime(DailyAttendance::sole()));
        $this->assertSame(1, DailyAttendance::count());

        // A future work date builds nothing.
        $future = $this->request('2026-04-20', '17:00', '19:00');
        $this->assertSame(0, $builder->rebuildOvertimeDate($future));
    }

    public function test_a_range_loads_overtime_once_whatever_the_number_of_requests(): void
    {
        $builder = app(DailySummaryBuilder::class);
        OvertimeSettings::current(); // cached for the request; not part of either count

        foreach (['2026-02-02', '2026-02-03', '2026-02-04', '2026-02-05', '2026-02-06'] as $date) {
            $this->punch("{$date} 08:00:00", 'in');
            $this->punch("{$date} 19:00:00", 'out');
        }

        $count = function () use ($builder): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $builder->rebuildBetween($this->employee->fresh(), Carbon::parse('2026-02-02'), Carbon::parse('2026-02-08'));
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Each count is a rebuild that changes nothing (the build before it
        // settled the rows), so only reads are counted, not the updates.
        $this->request('2026-02-02', '17:00', '19:00');
        $count();
        $withOne = $count();

        foreach (['2026-02-03', '2026-02-04', '2026-02-05', '2026-02-06'] as $date) {
            $this->request($date, '17:00', '19:00');
        }
        $count();

        $this->assertSame($withOne, $count());
        $this->assertSame(600, (int) DailyAttendance::sum('overtime_workday_minutes'));
    }

    public function test_a_full_range_rebuild_gives_the_same_rows(): void
    {
        $builder = app(DailySummaryBuilder::class);
        $this->request(self::MONDAY, '17:00', '23:00');
        $this->request(self::SATURDAY, '08:00', '17:00');
        $this->punch(self::MONDAY.' 08:00:00', 'in');
        $this->punch(self::MONDAY.' 22:30:15', 'out');
        $this->punch(self::SATURDAY.' 08:00:00', 'in');
        $this->punch(self::SATURDAY.' 16:00:00', 'out');

        $snapshot = fn () => DailyAttendance::orderBy('work_date')->get()
            ->map(fn (DailyAttendance $row) => collect($row->getAttributes())->except(['id', 'created_at', 'updated_at'])->all())
            ->all();

        $builder->rebuildBetween($this->employee, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-08'));
        $first = $snapshot();
        $builder->rebuildBetween($this->employee, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-08'));

        $this->assertSame($first, $snapshot());
        $monday = DailyAttendance::whereDate('work_date', self::MONDAY)->sole();
        $this->assertSame([300, 30], [$monday->overtime_workday_minutes, $monday->overtime_night_minutes]);
    }
}
