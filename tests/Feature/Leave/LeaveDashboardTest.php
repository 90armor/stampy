<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveHalf;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\DashboardAttendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3d — what leave does to the derived facts and figures: worked on
 * leave, the "not in yet" due time, Needs attention, the live strip's
 * partition, the month summary, and the attendance rate's denominator. The
 * day is Wed 17 Jun 2026; the schedule is 08:00–17:00 with a 60-minute break
 * from 12:00 (PM start 13:00) and 10 minutes' grace.
 */
class LeaveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-17';

    private LeaveType $annual;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check); tests move it within the day.
        $this->travelTo(Carbon::parse(self::DAY.' 09:00:00'));

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true, 'grace_minutes' => 10]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $this->department = Department::factory()->create(['name' => 'Engineering']);
    }

    private function person(string $name): Employee
    {
        return Employee::factory()->create(['full_name' => $name, 'department_id' => $this->department->id]);
    }

    private function leave(Employee $employee, ?LeaveHalf $half = null, string $from = self::DAY, string $to = self::DAY): Leave
    {
        return Leave::factory()->for($employee)->for($this->annual)->approved()
            ->create(['start_date' => $from, 'end_date' => $to, 'half' => $half]);
    }

    private function punch(Employee $employee, string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function build(Employee $employee, string $date = self::DAY): DailyAttendance
    {
        return app(DailySummaryBuilder::class)->build($employee->fresh(), Carbon::parse($date));
    }

    // ── workedOnLeave() ────────────────────────────────────────────────

    public function test_worked_on_leave_at_each_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-06-18 09:00:00'));

        $cases = [
            // [leave half(s), in, out, expected]
            'full day, no punch' => [[null], null, null, false],
            'full day, one punch' => [[null], '08:00:00', null, true],
            'AM, in before break_start' => [[LeaveHalf::Am], '11:59:00', '17:00:00', true],
            'AM, in at break_start' => [[LeaveHalf::Am], '12:00:00', '17:00:00', false],
            'PM, out at the PM start' => [[LeaveHalf::Pm], '08:00:00', '13:00:00', false],
            'PM, out after the PM start' => [[LeaveHalf::Pm], '08:00:00', '13:01:00', true],
            'AM + PM, afternoon only' => [[LeaveHalf::Am, LeaveHalf::Pm], '13:00:00', '17:00:00', true],
            'no leave' => [[], '08:00:00', '17:00:00', false],
        ];

        foreach ($cases as $label => [$halves, $in, $out, $expected]) {
            $employee = $this->person($label);

            foreach ($halves as $half) {
                $this->leave($employee, $half);
            }

            if ($in !== null) {
                $this->punch($employee, self::DAY." {$in}", 'in');
            }

            if ($out !== null) {
                $this->punch($employee, self::DAY." {$out}", 'out');
            }

            $this->assertSame($expected, $this->build($employee)->workedOnLeave(), $label);
        }
    }

    // ── Needs attention ────────────────────────────────────────────────

    public function test_needs_attention_lists_worked_on_leave_last_and_counts_it(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 13:15:00'));

        $absent = $this->person('Absent After Noon');
        $this->leave($absent, LeaveHalf::Pm);                                   // punchless PM leave: absent after 12:00
        $late = $this->person('Late Arrival');
        $this->punch($late, self::DAY.' 09:00:00', 'in');
        $notIn = $this->person('Not In');
        $amLeave = $this->person('Am Leave Not Back');
        $this->leave($amLeave, LeaveHalf::Am);                                  // due 13:00, past 13:10
        $workedOnLeave = $this->person('Worked On Leave');
        $this->leave($workedOnLeave);
        $this->punch($workedOnLeave, self::DAY.' 08:00:00', 'in');

        foreach ([$absent, $late, $notIn, $amLeave, $workedOnLeave] as $employee) {
            $this->build($employee);
        }

        $list = DashboardAttendance::needsAttention(null);

        $this->assertSame(
            ['Absent After Noon', 'Late Arrival', 'Am Leave Not Back', 'Not In', 'Worked On Leave'],
            array_column($list, 'name'),
        );
        $this->assertSame('Not in yet · due 1:00 PM', $list[2]['detail']);
        $this->assertSame('Not in yet · due 8:00 AM', $list[3]['detail']);
        $this->assertSame(['worked_on_leave', null, 'Punched on approved leave'], [$list[4]['kind'], $list[4]['badge'], $list[4]['detail']]);
        $this->assertSame(5, DashboardAttendance::needsAttentionTotal(null));
    }

    // ── The live strip ─────────────────────────────────────────────────

    public function test_the_strip_counts_am_leave_as_on_leave_until_the_pm_start_plus_grace(): void
    {
        $amLeave = $this->person('Am Leave');
        $this->leave($amLeave, LeaveHalf::Am);
        $fullDay = $this->person('Full Day');
        $this->leave($fullDay);
        $working = $this->person('Working');
        $this->punch($working, self::DAY.' 08:00:00', 'in');

        foreach (['09:00:00' => [2, 0], '13:05:00' => [2, 0], '13:15:00' => [1, 1]] as $time => [$onLeave, $due]) {
            $this->travelTo(Carbon::parse(self::DAY." {$time}"));

            foreach ([$amLeave, $fullDay, $working] as $employee) {
                $this->build($employee);
            }

            $live = DashboardAttendance::liveToday(null);

            $this->assertSame(1, $live['atWork'], $time);
            $this->assertSame(2, $live['notIn'], $time);
            $this->assertSame($onLeave, $live['notInLeave'], "{$time}: on leave");
            $this->assertSame($due, $live['notInDue'], "{$time}: due");
            $this->assertSame($live['notIn'], $live['notInDue'] + $live['notInAbsent'] + $live['notInOff'] + $live['notInHoliday'] + $live['notInLeave'], "{$time}: the sub-line sums to the cell");
            $this->assertSame($live['total'], $live['atWork'] + $live['left'] + $live['notIn'], "{$time}: the strip is a partition");
        }

        $this->assertTrue(DailyAttendance::where('employee_id', $amLeave->id)->first()->isNotInYet());
    }

    public function test_past_end_time_uses_the_expected_end_so_pm_leave_ends_at_noon(): void
    {
        $pmLeave = $this->person('Pm Leave');
        $this->leave($pmLeave, LeaveHalf::Pm);
        $this->punch($pmLeave, self::DAY.' 08:00:00', 'in');                   // forgot to punch out at noon
        $fullDay = $this->person('Full Day');
        $this->punch($fullDay, self::DAY.' 08:00:00', 'in');

        foreach (['11:59:00' => 0, '12:30:00' => 1, '17:30:00' => 2] as $time => $pastEnd) {
            $this->travelTo(Carbon::parse(self::DAY." {$time}"));
            $this->build($pmLeave);
            $this->build($fullDay);

            $live = DashboardAttendance::liveToday(null);
            $this->assertSame(2, $live['atWork'], $time);
            $this->assertSame($pastEnd, $live['atWorkPastEnd'], $time);
        }
    }

    // ── The month summary ──────────────────────────────────────────────

    public function test_the_summary_counts_leave_as_workdays_and_half_days_as_half(): void
    {
        $employee = $this->person('Summary');
        $this->leave($employee, null, '2026-06-01', '2026-06-02');             // Mon–Tue, punchless: leave × 2
        $this->leave($employee, LeaveHalf::Am, '2026-06-03', '2026-06-03');    // worked the afternoon: present
        $this->punch($employee, '2026-06-03 13:00:00', 'in');
        $this->punch($employee, '2026-06-03 17:00:00', 'out');
        $this->leave($employee, LeaveHalf::Pm, '2026-06-04', '2026-06-04');    // punchless: absent
        $this->leave($employee, null, '2026-06-06', '2026-06-07');             // a weekend: off, costs nothing
        app(DailySummaryBuilder::class)->rebuildBetween($employee->fresh(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07'));

        Role::firstOrCreate(['name' => 'admin']);
        Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-06')
            ->assertViewHas('summary', function (array $summary) {
                // 1–2 leave, 3 present, 4 absent, 5 absent.
                $this->assertSame(5, $summary['workdays']);
                $this->assertSame(2, $summary['leave']);
                $this->assertSame(30, $summary['leave_tenths']);

                return true;
            })
            // Status counts sum to workdays: Present 1 + Absent 2 + Incomplete 0 + On leave 2 = 5.
            ->assertSeeHtml('On leave <strong class="font-semibold text-slate-900 dark:text-slate-100">2</strong>')
            // Leave taken is its own measure, on its own line.
            ->assertSeeHtml('Leave taken: <strong class="font-semibold text-slate-900 dark:text-slate-100">3 days</strong>')
            ->assertSee('counted separately from the workday figures above');
    }

    // ── The rate's denominator ─────────────────────────────────────────

    public function test_full_day_leave_is_left_out_of_the_rate_and_half_day_stays_in(): void
    {
        $this->travelTo(Carbon::parse('2026-06-18 09:00:00'));
        $date = self::DAY;

        foreach (['A', 'B'] as $name) {
            $present = $this->person("Present {$name}");
            $this->punch($present, "{$date} 08:00:00", 'in');
            $this->punch($present, "{$date} 17:00:00", 'out');
            $this->build($present);
        }
        $this->build($this->person('Absent'));
        $onLeave = $this->person('On Leave');
        $this->leave($onLeave);
        $this->build($onLeave);
        $halfDay = $this->person('Half Day');
        $this->leave($halfDay, LeaveHalf::Pm);
        $this->build($halfDay);                                                  // punchless PM leave: absent

        $bar = collect(DashboardAttendance::weeklyTrend(null))->firstWhere('date', $date);

        // 2 attended of the 4 expected (5 active, 1 on full-day leave); the half day stays in.
        $this->assertSame(50.0, $bar['value']);
    }

    public function test_a_day_everyone_spends_on_leave_shows_a_leave_marker_and_the_department_card_says_on_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-06-18 09:00:00'));

        foreach (['One', 'Two'] as $name) {
            $employee = $this->person($name);
            $this->leave($employee, null, self::DAY, '2026-06-18');
            $this->build($employee);
            $this->build($employee, '2026-06-18');
        }

        $bar = collect(DashboardAttendance::weeklyTrend(null))->firstWhere('date', self::DAY);
        $this->assertSame([null, 'Leave'], [$bar['value'], $bar['marker']]);

        $card = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null)[0];
        $this->assertSame([2, 0], [$card['employees'], $card['expected']]);

        Role::firstOrCreate(['name' => 'admin']);
        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('dashboard'))
            ->assertOk()
            ->assertSeeHtml('<p class="text-sm text-slate-500 dark:text-slate-400">On leave</p>');
    }

    public function test_the_department_cards_denominator_leaves_out_full_day_leave(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 18:00:00'));
        $present = $this->person('Present');
        $this->punch($present, self::DAY.' 08:00:00', 'in');
        $this->punch($present, self::DAY.' 17:00:00', 'out');
        $onLeave = $this->person('On Leave');
        $this->leave($onLeave);
        $absent = $this->person('Absent');

        foreach ([$present, $onLeave, $absent] as $employee) {
            $this->build($employee);
        }

        $card = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null)[0];

        $this->assertSame(['employees' => 3, 'expected' => 2, 'attended' => 1, 'pending' => false], array_intersect_key($card, array_flip(['employees', 'expected', 'attended', 'pending'])));
    }

    public function test_someone_on_full_day_leave_leaves_the_denominator_unless_counted_in_n(): void
    {
        // 09:00, today open (N = checked in): one at work, two on leave who
        // came in anyway, one away on leave.
        $atWork = $this->person('At Work');
        $this->punch($atWork, self::DAY.' 08:00:00', 'in');
        $cameIn = $this->person('Came In');
        $this->leave($cameIn);
        $this->punch($cameIn, self::DAY.' 08:05:00', 'in');
        $workedFull = $this->person('Worked Full');
        $this->leave($workedFull);
        $this->punch($workedFull, self::DAY.' 08:02:00', 'in');
        $away = $this->person('Away');
        $this->leave($away);
        $people = [$atWork, $cameIn, $workedFull, $away];

        foreach ($people as $employee) {
            $this->build($employee);
        }

        // Checked in 3 / 3, not 3 / 1: everyone with a punch is in N, so in M.
        $card = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null)[0];
        $this->assertEquals(['checkedIn' => 3, 'expected' => 3, 'pending' => true], array_intersect_key($card, array_flip(['checkedIn', 'expected', 'pending'])));
        $bar = collect(DashboardAttendance::weeklyTrend(null))->firstWhere('date', self::DAY);
        $this->assertSame([100.0, 'Today'], [$bar['value'], $bar['marker']]);

        // 18:00, closed (N = attended): a full day worked on leave is present,
        // so in N and in M — the case that would read 2 / 1 otherwise. A
        // one-punch leave day stays leave: out of both.
        $this->punch($atWork, self::DAY.' 17:00:00', 'out');
        $this->punch($workedFull, self::DAY.' 17:00:00', 'out');
        $this->travelTo(Carbon::parse(self::DAY.' 18:00:00'));
        foreach ($people as $employee) {
            $this->build($employee);
        }

        $card = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null)[0];
        $this->assertEquals(['attended' => 2, 'expected' => 2, 'pending' => false], array_intersect_key($card, array_flip(['attended', 'expected', 'pending'])));
        $bar = collect(DashboardAttendance::weeklyTrend(null))->firstWhere('date', self::DAY);
        $this->assertSame([100.0, null], [$bar['value'], $bar['marker']]);
    }
}
