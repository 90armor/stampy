<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\AttendanceSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b follow-ups — what a day contributes to the month's summary, the
 * one definition My attendance, the employee dashboard and the attendance
 * report share. Calculated workdays and Present count scheduled workdays only
 * (WorkdayCalendar::isWorkday); a present day off is a "day off worked"; a
 * one-punch day off counts nowhere; Not calculated is past workdays with no
 * finished row. The clock is Mon 15 Jun 2026 12:00; the schedule is
 * Mon–Fri 08:00–17:00 with an hour's break from noon.
 */
class AttendanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
            'break_minutes' => 60, 'break_start' => '12:00:00', 'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->employee = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id]);
    }

    private function punch(string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function build(string $from, string $to): void
    {
        app(DailySummaryBuilder::class)->rebuildBetween($this->employee, Carbon::parse($from), Carbon::parse($to));
    }

    private function statusOn(string $date): AttendanceStatus
    {
        return DailyAttendance::where('employee_id', $this->employee->id)->whereDate('work_date', $date)->sole()->status;
    }

    /** @return array<string, int> */
    private function june(): array
    {
        return AttendanceSummary::forMonth($this->employee->fresh(), Carbon::parse('2026-06-01'));
    }

    public function test_a_worked_sunday_is_a_day_off_worked_not_a_workday(): void
    {
        $this->punch('2026-06-07 09:00:00', 'in');
        $this->punch('2026-06-07 13:00:00', 'out');
        $this->build('2026-06-07', '2026-06-07');

        // The day itself is unchanged: present, green.
        $this->assertSame(AttendanceStatus::Present, $this->statusOn('2026-06-07'));

        $summary = $this->june();
        $this->assertSame(0, $summary['workdays']);
        $this->assertSame(0, $summary['present']);
        $this->assertSame(1, $summary['days_off_worked']);
        // Its time still counts as worked: 09:00–13:00 less the hour's break.
        $this->assertSame(180, $summary['total_worked_minutes']);
    }

    public function test_a_worked_holiday_is_a_day_off_worked_not_a_workday(): void
    {
        Holiday::factory()->create(['date' => '2026-06-10', 'name' => 'Company holiday']);
        $this->punch('2026-06-10 08:00:00', 'in');
        $this->punch('2026-06-10 17:00:00', 'out');
        // Tue 9 Jun, an ordinary workday, for contrast.
        $this->punch('2026-06-09 08:00:00', 'in');
        $this->punch('2026-06-09 17:00:00', 'out');
        $this->build('2026-06-09', '2026-06-10');

        $this->assertSame(AttendanceStatus::Present, $this->statusOn('2026-06-10'));

        $summary = $this->june();
        $this->assertSame(1, $summary['workdays']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['days_off_worked']);
    }

    public function test_a_one_punch_saturday_counts_nowhere(): void
    {
        // Owner decision: not a workday, so not absent or incomplete in the
        // figures; without both punches, not a day worked either.
        $this->punch('2026-06-06 09:00:00', 'in');
        $this->build('2026-06-06', '2026-06-06');

        // The day itself still shows Incomplete.
        $this->assertSame(AttendanceStatus::Incomplete, $this->statusOn('2026-06-06'));

        $summary = $this->june();
        $this->assertSame(0, $summary['workdays']);
        $this->assertSame(0, $summary['incomplete']);
        $this->assertSame(0, $summary['incomplete_late']);
        $this->assertSame(0, $summary['days_off_worked']);
    }

    public function test_a_full_day_worked_on_approved_leave_is_present_and_still_leave_taken(): void
    {
        // EMP-0020's 23 Oct in the seed: both punches on an approved Annual
        // day. Present (both punches win, Phase 3d), and the day stays
        // charged — the balance isn't refunded automatically, an admin
        // decides — so it is one day of leave taken, not On leave.
        Leave::factory()->for($this->employee)->between('2026-06-12', '2026-06-12')->approved()->create();
        $this->punch('2026-06-12 08:00:00', 'in');
        $this->punch('2026-06-12 17:00:00', 'out');
        $this->build('2026-06-12', '2026-06-12');

        $summary = $this->june();
        $this->assertSame(1, $summary['workdays']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(0, $summary['leave']);
        $this->assertSame(10, $summary['leave_tenths']);
        // …and it is visible as such: one of those days was worked.
        $this->assertSame(1, $summary['worked_on_leave']);
    }

    public function test_a_day_off_worked_inside_a_leave_costs_no_leave(): void
    {
        // Fri 5 – Mon 8 Jun on leave, coming in on the Saturday: the leave
        // charges Friday and Monday only, and Saturday is a day off worked.
        Leave::factory()->for($this->employee)->between('2026-06-05', '2026-06-08')->approved()->create();
        $this->punch('2026-06-06 08:00:00', 'in');
        $this->punch('2026-06-06 12:00:00', 'out');
        $this->build('2026-06-05', '2026-06-08');

        $summary = $this->june();
        $this->assertSame(2, $summary['workdays']);
        $this->assertSame(2, $summary['leave']);
        $this->assertSame(20, $summary['leave_tenths']);
        $this->assertSame(1, $summary['days_off_worked']);
    }

    public function test_not_calculated_is_past_workdays_with_no_finished_row(): void
    {
        // Mon 1 – Wed 10 Jun built (absent); Thu 11 stuck In progress (an
        // in-punch the builder never closed); Fri 12 never built; today
        // (Mon 15) not built either.
        $this->build('2026-06-01', '2026-06-10');
        DailyAttendance::factory()->for($this->employee)->create([
            'work_date' => '2026-06-11', 'status' => AttendanceStatus::InProgress, 'first_in' => '2026-06-11 08:00:00',
        ]);

        // 11 Jun (stale) and 12 Jun — never today, never the weekend.
        $this->assertSame(2, AttendanceSummary::notCalculated($this->employee->fresh(), Carbon::parse('2026-06-01')));
    }

    public function test_an_earlier_day_still_inside_its_pairing_window_is_pending_not_missing(): void
    {
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));
        $this->build('2026-06-01', '2026-06-14');
        // In at 20:00 on Mon 15, no out yet: open until 14:00 on the 16th.
        $this->punch('2026-06-15 20:00:00', 'in');
        $this->build('2026-06-15', '2026-06-15');
        $this->assertSame(AttendanceStatus::InProgress, $this->statusOn('2026-06-15'));

        $this->assertSame(0, AttendanceSummary::notCalculated($this->employee->fresh(), Carbon::parse('2026-06-01')));

        // Past the window and still not rebuilt: missing.
        $this->travelTo(Carbon::parse('2026-06-16 14:30:00'));
        $this->assertSame(1, AttendanceSummary::notCalculated($this->employee->fresh(), Carbon::parse('2026-06-01')));
    }

    public function test_my_attendance_and_the_dashboard_show_days_off_worked(): void
    {
        $this->punch('2026-06-07 09:00:00', 'in');
        $this->punch('2026-06-07 13:00:00', 'out');
        $this->build('2026-06-01', '2026-06-07');

        $summary = $this->june();
        Livewire::actingAs($this->employee->user)->test(Show::class)
            ->assertViewHas('summary', $summary)
            ->assertSee('Days off worked:');

        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertSee('Days off worked: 1');
    }
}
