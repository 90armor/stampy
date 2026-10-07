<?php

namespace Tests\Feature\Leave;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveHalf;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3d — approved full-day leave as a builder input (CLAUDE.md, Phase 3,
 * rules 15–17). The clock is Wed 17 Jun 2026, 10:00; the schedule is
 * Mon–Fri 08:00–17:00 with a 60-minute break from 12:00 and 10 minutes' grace.
 */
class LeaveAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-17';

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true, 'grace_minutes' => 10]);
        $this->employee = Employee::factory()->create();
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
    }

    private function leave(string $from, string $to, ?LeaveType $type = null, ?LeaveHalf $half = null): Leave
    {
        return Leave::factory()->for($this->employee)->for($type ?? $this->annual)
            ->approved()->create(['start_date' => $from, 'end_date' => $to, 'half' => $half]);
    }

    private function punch(string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function build(string $date): DailyAttendance
    {
        return app(DailySummaryBuilder::class)->build($this->employee->fresh(), Carbon::parse($date));
    }

    public function test_a_punchless_full_leave_day_is_leave_from_the_start_never_in_progress(): void
    {
        $leave = $this->leave(self::TODAY, self::TODAY);

        $row = $this->build(self::TODAY);

        $this->assertSame(AttendanceStatus::Leave, $row->status);
        $this->assertSame($leave->id, $row->leave_id);
        $this->assertSame([0, 0, 0], [$row->worked_minutes, $row->late_minutes, $row->early_leave_minutes]);
        $this->assertFalse($row->isNotInYet());
    }

    public function test_one_punch_on_a_full_leave_day_is_still_leave_and_the_punch_is_kept(): void
    {
        $this->leave('2026-06-16', '2026-06-16');
        $this->punch('2026-06-16 08:30:00', 'in');

        $row = $this->build('2026-06-16');

        $this->assertSame(AttendanceStatus::Leave, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(1, AttendanceLog::where('employee_id', $this->employee->id)->count());
    }

    public function test_both_punches_on_a_full_leave_day_are_present_with_no_timing(): void
    {
        $leave = $this->leave('2026-06-16', '2026-06-16');
        $this->punch('2026-06-16 08:30:00', 'in');   // would be 30 minutes late
        $this->punch('2026-06-16 16:00:00', 'out');  // would be an hour early

        $row = $this->build('2026-06-16');

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame($leave->id, $row->leave_id);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
        // 7h30 less the full-day rule's 60-minute break: worked minutes are kept.
        $this->assertSame(390, $row->worked_minutes);
    }

    public function test_an_off_day_inside_maternity_stays_off_and_a_holiday_inside_annual_stays_holiday(): void
    {
        $maternity = LeaveType::factory()->withoutBalance()->calendarDays()->withoutHalfDays()->create(['name' => 'Maternity']);
        $long = $this->leave('2026-06-08', '2026-06-14', $maternity);
        $saturday = $this->build('2026-06-13');
        $this->assertSame(AttendanceStatus::Off, $saturday->status);
        $this->assertSame($long->id, $saturday->leave_id);

        Holiday::factory()->create(['date' => '2026-06-02']);
        $annual = $this->leave('2026-06-01', '2026-06-03');
        $holiday = $this->build('2026-06-02');
        $this->assertSame(AttendanceStatus::Holiday, $holiday->status);
        $this->assertSame($annual->id, $holiday->leave_id);
    }

    public function test_a_day_no_approved_leave_covers_is_unchanged(): void
    {
        Leave::factory()->for($this->employee)->for($this->annual)->between(self::TODAY, self::TODAY)->pending()->create();
        Leave::factory()->for($this->employee)->for($this->annual)->between(self::TODAY, self::TODAY)->cancelled()->create();

        $row = $this->build(self::TODAY);

        $this->assertSame(AttendanceStatus::InProgress, $row->status);
        $this->assertNull($row->leave_id);
    }

    public function test_approving_a_leave_turns_todays_in_progress_row_into_leave_and_cancelling_turns_it_back(): void
    {
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        $admin = User::factory()->create()->assignRole('admin');
        $this->assertSame(AttendanceStatus::InProgress, $this->build(self::TODAY)->status);

        $unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);
        $service = app(LeaveRequestService::class);
        $leave = $service->submit($this->employee, $unpaid, Carbon::parse(self::TODAY), Carbon::parse(self::TODAY), null, null, $admin)['leave'];

        $row = DailyAttendance::where('employee_id', $this->employee->id)->whereDate('work_date', self::TODAY)->first();
        $this->assertSame(AttendanceStatus::Leave, $row->status);
        $this->assertSame($leave->id, $row->leave_id);

        $service->cancel($leave, $admin);

        $row->refresh();
        $this->assertSame(AttendanceStatus::InProgress, $row->status);
        $this->assertNull($row->leave_id);
    }

    public function test_a_range_build_loads_leave_once(): void
    {
        $this->leave('2026-06-10', '2026-06-12');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DailySummaryBuilder::class)->rebuildBetween($this->employee->fresh(), Carbon::parse('2026-06-08'), Carbon::parse('2026-06-16'));
        DB::disableQueryLog();

        $leaveQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from `leaves`'));

        $this->assertCount(1, $leaveQueries);
        $this->assertSame(3, DailyAttendance::where('status', AttendanceStatus::Leave->value)->count());
    }

    public function test_build_daily_loads_leave_once_per_employee(): void
    {
        $this->leave('2026-06-10', '2026-06-12');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('attendance:build-daily', ['--from' => '2026-06-08', '--to' => '2026-06-16'])->assertSuccessful();
        DB::disableQueryLog();

        $this->assertCount(1, collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from `leaves`')));
        $this->assertSame(['2026-06-10', '2026-06-11', '2026-06-12'], DailyAttendance::where('status', AttendanceStatus::Leave->value)->orderBy('work_date')->get()->map(fn ($row) => $row->work_date->format('Y-m-d'))->all());
    }
}
