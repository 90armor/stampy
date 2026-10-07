<?php

namespace Tests\Feature\Leave;

use App\Livewire\Attendance\Index;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — leave and holidays in the attendance views: the half-day and
 * worked-on-leave annotations (calendar, tables), the holiday's name on a
 * day the badge doesn't name it, the day modal's leave section with what
 * each leave charged that day, and the daily list's "Worked on leave"
 * filter. The clock is Fri 19 Jun 2026, 18:00; the schedule is Mon–Fri
 * 08:00–17:00 with a 60-minute break from 12:00.
 *
 * The month, built:
 * - Wed 10 Jun: an AM Medical and a PM Annual — a full day of leave
 * - Fri 12 Jun: holiday "Quiet Day", unworked; inside a Thu–Fri Annual
 * - Sat 13 Jun: a Maternity day (calendar days)
 * - Mon 15 Jun: AM leave, worked 13:00–17:00
 * - Tue 16 Jun: full-day leave, worked 08:00–17:00 anyway
 * - Wed 17 Jun: holiday "Busy Day", worked 08:30–17:00
 */
class AttendanceLeaveViewsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-19 18:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $medical = LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30]);
        $maternity = LeaveType::factory()->withoutBalance()->calendarDays()->withoutHalfDays()->create(['name' => 'Maternity']);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->employee = Employee::factory()->create(['full_name' => 'Employee', 'user_id' => User::factory()->create()->assignRole('employee')->id]);

        Holiday::factory()->create(['date' => '2026-06-12', 'name' => 'Quiet Day']);
        Holiday::factory()->create(['date' => '2026-06-17', 'name' => 'Busy Day']);

        $leave = fn (LeaveType $type, string $from, string $to, ?string $half = null) => ($half
            ? Leave::factory()->for($this->employee)->for($type)->between($from, $to)->halfDay($half)
            : Leave::factory()->for($this->employee)->for($type)->between($from, $to))->approved()->create();
        $leave($medical, '2026-06-10', '2026-06-10', 'am');
        $leave($annual, '2026-06-10', '2026-06-10', 'pm');
        $leave($annual, '2026-06-11', '2026-06-12');
        $leave($maternity, '2026-06-13', '2026-06-13');
        $leave($annual, '2026-06-15', '2026-06-15', 'am');
        $leave($annual, '2026-06-16', '2026-06-16');

        foreach ([['2026-06-15 13:00', '2026-06-15 17:00'], ['2026-06-16 08:00', '2026-06-16 17:00'], ['2026-06-17 08:30', '2026-06-17 17:00']] as [$in, $out]) {
            AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $in.':00', 'punch_type' => 'in']);
            AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $out.':00', 'punch_type' => 'out']);
        }

        app(DailySummaryBuilder::class)->rebuildBetween($this->employee->fresh(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-19'));
    }

    private function show(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->admin)->test(Show::class, ['employee' => $this->employee])->set('month', '2026-06');
    }

    public function test_the_table_annotates_half_days_work_on_leave_and_holidays(): void
    {
        // Without Livewire's block markers, so the patterns read the cells.
        $html = preg_replace('/<!--.*?-->/s', '', $this->show()->set('view', 'table')->html());

        // Mon 15: present on an AM-leave day. Tue 16: present on full-day leave.
        $this->assertMatchesRegularExpression('/Mon 15 Jun.*?Present.*?AM leave/s', $html);
        $this->assertMatchesRegularExpression('/Tue 16 Jun.*?Present.*?Worked on leave/s', $html);
        // Wed 17, worked on a holiday: this view has a Note column, so the name is
        // there — why late/early are zero — and not under the badge.
        $this->assertMatchesRegularExpression('/Wed 17 Jun.*?Present\s*<\/span>\s*<\/td>.*?Busy Day/s', $html);
        $this->assertSame(1, substr_count($html, 'Busy Day'));
        // Fri 12, an unworked holiday: the badge already says Holiday, so only the Note names it.
        $this->assertMatchesRegularExpression('/Fri 12 Jun.*?Holiday\s*<\/span>\s*<\/td>.*?Quiet Day/s', $html);
    }

    public function test_calendar_cells_carry_the_annotations_in_their_label(): void
    {
        $this->show()
            ->assertSeeHtml('aria-label="Monday, 15 June 2026, Present, AM leave"')
            ->assertSeeHtml('aria-label="Tuesday, 16 June 2026, Present, worked on leave"')
            ->assertSee('Worked on leave');
    }

    public function test_the_day_modal_lists_every_covering_leave_and_what_it_charged(): void
    {
        // Two types on one date.
        $this->show()->call('openDay', '2026-06-10')
            ->assertSee('Medical · AM')
            ->assertSee('Annual · PM')
            ->assertSee('Wed 10 Jun · Charged 0.5 day')
            ->assertSee('View on profile');

        // A holiday inside Annual isn't charged; Maternity's calendar days are, weekend or not.
        $this->show()->call('openDay', '2026-06-12')->assertSee('11–12 Jun · Not charged — holiday');
        $this->show()->call('openDay', '2026-06-13')->assertSee('Sat 13 Jun · Charged 1 day');

        // Worked on leave says so.
        $this->show()->call('openDay', '2026-06-16')->assertSee('Worked on leave: there are punches in leave time.');
    }

    public function test_the_employee_on_my_attendance_is_linked_to_time_off_not_the_profile(): void
    {
        Livewire::actingAs($this->employee->user)->withQueryParams(['month' => '2026-06'])->test(Show::class)
            ->call('openDay', '2026-06-10')
            ->assertSee('View in Time off')
            ->assertDontSee('View on profile');
    }

    public function test_the_daily_list_filters_to_days_worked_on_leave_and_annotates_them(): void
    {
        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('fromDate', '2026-06-01')
            ->set('toDate', '2026-06-19')
            ->call('toggleWorkedOnLeave')
            ->assertSet('workedOnLeave', true)
            ->assertSee('Tue 16 Jun')
            ->assertDontSee('Mon 15 Jun')
            ->assertDontSee('Wed 17 Jun')
            ->assertSee('Worked on leave');

        // No Note column here: the name sits under the badge, muted — not a status colour.
        $html = Livewire::actingAs($this->admin)->test(Index::class)
            ->set('fromDate', '2026-06-17')
            ->set('toDate', '2026-06-17')
            ->html();
        $this->assertMatchesRegularExpression('/<span class="[^"]*text-slate-500[^"]*">Busy Day<\/span>/', $html);
        $this->assertStringNotContainsString('fuchsia-700 dark:text-fuchsia-300">Busy Day', $html);
    }
}
