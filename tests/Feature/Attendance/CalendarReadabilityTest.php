<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Index;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarReadabilityTest extends TestCase
{
    use RefreshDatabase;

    // A Monday, so it's a scheduled workday under the default Mon-Fri schedule.
    private const MONTH = '2026-03';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    public function test_the_legend_lists_all_six_statuses_with_their_icons(): void
    {
        $employee = Employee::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH);

        foreach (['Present', 'Incomplete', 'Absent', 'Off', 'Not calculated', 'Late / Early leave'] as $label) {
            $component->assertSee($label);
        }

        // Each legend entry's icon path, not just its label text — proves
        // the legend actually pairs icon-to-label, not just prints text
        // near unrelated icons elsewhere on the page. No icon path for
        // "Late / Early leave": timing isn't a status, so that entry is an
        // amber sample marked time, not an icon.
        foreach ([
            'M4.5 12.75l6 6 9-13.5',       // present (check)
            'M12 9v3.75m-9.303',            // incomplete (triangle)
            'M6 18 18 6M6 6l12 12',         // absent (x-mark)
            'M6.75 3v2.25M17.25 3v2.25',    // off (calendar-days)
            'M5 12h14',                      // not calculated (dash)
        ] as $path) {
            $component->assertSeeHtml($path);
        }

        // Exactly one "Late / Early leave" entry — this used to be two
        // (an icon-based legend row sharing present's check icon, plus a
        // separately worded "Late arrival / early leave" marked-time
        // sample), now merged into a single entry.
        $this->assertSame(1, substr_count($component->html(), 'Late / Early leave'));
    }

    public function test_the_summary_label_says_calculated_workdays_not_workdays(): void
    {
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('Calculated workdays')
            ->assertDontSee('>Workdays<');
    }

    public function test_a_partially_built_month_shows_the_calculated_up_to_notice(): void
    {
        $employee = Employee::factory()->create();

        // Only the first 5 days of March are built; the month has 31.
        for ($day = 1; $day <= 5; $day++) {
            DailyAttendance::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => sprintf('2026-03-%02d', $day),
                'status' => AttendanceStatus::Present,
            ]);
        }

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('only been calculated up to')
            ->assertSee('Mar 5, 2026');
    }

    public function test_a_month_with_nothing_built_shows_a_generic_notice_not_a_date(): void
    {
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            // Not the apostrophe-containing form: that's literal static
            // Blade text (not passed through {{ }}), so it isn't
            // HTML-entity-escaped the way assertSee's own escaping of the
            // search string would expect.
            ->assertSee('been calculated for this month yet');
    }

    public function test_a_fully_built_month_shows_no_notice(): void
    {
        $employee = Employee::factory()->create();
        $builder = app(DailySummaryBuilder::class);

        $cursor = Carbon::parse('2026-03-01');
        while ($cursor->format('Y-m') === self::MONTH) {
            $builder->build($employee, $cursor->copy());
            $cursor->addDay();
        }

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertDontSee('only been calculated up to')
            ->assertDontSee("hasn't been calculated");
    }

    public function test_a_day_with_an_early_departure_appends_it_to_the_cells_accessible_label(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => Carbon::parse('2026-03-02 16:15:00'),
            'early_leave_minutes' => 45,
        ]);
        // A day with no early leave, for contrast.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-03',
            'status' => AttendanceStatus::Present,
            'early_leave_minutes' => 0,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH);

        $component->assertSee('March 2, 2026, Present, left 45 minutes early');
        $component->assertSee('March 3, 2026, Present');
        $component->assertDontSee('March 3, 2026, Present, left');
    }

    public function test_a_late_arrival_marks_the_in_time_in_amber_without_underline_and_with_a_label(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:12:00'),
            'last_out' => Carbon::parse('2026-03-02 17:00:00'),
            'late_minutes' => 12,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertMatchesRegularExpression('/<span class="font-medium text-amber-700 dark:text-amber-300" aria-label="Arrived 12 minutes late">/', $html);
        $this->assertStringNotContainsString('decoration-red', $html);
        $this->assertStringNotContainsString('aria-label="Left', $html);
        // The cell itself stays status-coloured (green Present), not amber.
        $this->assertStringContainsString('bg-green-50 dark:bg-green-900/20', $html);
        $this->assertStringNotContainsString('bg-amber-50 dark:bg-amber-900/20', $html);
    }

    public function test_an_early_departure_marks_the_out_time_in_amber_and_with_a_label(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => Carbon::parse('2026-03-02 16:56:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 4,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertMatchesRegularExpression('/<span class="font-medium text-amber-700 dark:text-amber-300" aria-label="Left 4 minutes early">/', $html);
        $this->assertStringNotContainsString('aria-label="Arrived', $html);
    }

    public function test_the_table_view_marks_timing_on_the_late_and_early_values_not_the_times(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:12:00'),
            'last_out' => Carbon::parse('2026-03-02 16:56:00'),
            'late_minutes' => 12,
            'early_leave_minutes' => 4,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->set('view', 'table')
            ->html();

        // Same rule as the Daily Attendance table: In/Out stay neutral and
        // the amber Late/Early values carry the timing fact.
        $this->assertMatchesRegularExpression('/class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums font-medium text-amber-700 dark:text-amber-300">12m</', $html);
        $this->assertMatchesRegularExpression('/class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums font-medium text-amber-700 dark:text-amber-300">4m</', $html);
        $this->assertStringNotContainsString('aria-label="Arrived', $html);
        $this->assertStringNotContainsString('aria-label="Left', $html);
        // The row's one affordance is the trailing 40px raw-punches toggle,
        // named for its date (an admin is acting here).
        $this->assertStringContainsString('aria-label="Show raw punches for Mon 2 Mar"', $html);
        $this->assertStringContainsString('<abbr title="Early leave" class="no-underline">Early</abbr>', $html);
        // A third "Late 12m"/"Early 4m" chip beside the Status badge
        // repeated the same fact and bloated the row height.
        $this->assertStringNotContainsString('>Late 12m<', $html);
        $this->assertStringNotContainsString('>Early 4m<', $html);
    }

    public function test_the_day_modal_also_marks_late_arrival_and_early_leave_times(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:12:00'),
            'last_out' => Carbon::parse('2026-03-02 16:56:00'),
            'late_minutes' => 12,
            'early_leave_minutes' => 4,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->call('openDay', '2026-03-02')
            ->html();

        $this->assertStringContainsString('aria-label="Arrived 12 minutes late"', $html);
        $this->assertStringContainsString('aria-label="Left 4 minutes early"', $html);
    }

    public function test_a_day_with_both_a_late_arrival_and_an_early_departure_marks_both_times(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:12:00'),
            'last_out' => Carbon::parse('2026-03-02 16:56:00'),
            'late_minutes' => 12,
            'early_leave_minutes' => 4,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertStringContainsString('aria-label="Arrived 12 minutes late"', $html);
        $this->assertStringContainsString('aria-label="Left 4 minutes early"', $html);
    }

    public function test_a_day_with_neither_shows_no_marked_time(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => Carbon::parse('2026-03-02 17:00:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        // decoration-red-600 alone isn't a safe absence check any more: the
        // legend's sample marked time always renders it. Check for the
        // aria-labels a real marked time would carry instead.
        $this->assertStringNotContainsString('aria-label="Arrived', $html);
        $this->assertStringNotContainsString('aria-label="Left', $html);
    }

    public function test_incomplete_uses_a_distinct_violet_color_not_ambers_late_color(): void
    {
        $employee = Employee::factory()->create();
        // Incomplete requires exactly one of first_in/last_out present.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Incomplete,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => null,
        ]);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-03',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-03 08:12:00'),
            'last_out' => Carbon::parse('2026-03-03 17:00:00'),
            'late_minutes' => 12,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertStringContainsString('text-violet-700', $html);
        $this->assertStringContainsString('dark:text-violet-300', $html);
        $this->assertStringContainsString('text-amber-700', $html);
    }

    public function test_the_tables_incomplete_badge_matches_the_calendars_violet_not_amber(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Incomplete,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => null,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->set('view', 'table')
            ->html();

        $this->assertStringContainsString('bg-violet-50', $html);
        $this->assertStringNotContainsString('bg-amber-50 text-amber-700', $html);
    }

    public function test_a_present_day_with_an_early_leave_keeps_the_green_present_cell(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:00:00'),
            'last_out' => Carbon::parse('2026-03-02 16:56:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 4,
        ]);
        // A plain Present day, for contrast — must stay green.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-03',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-03 08:00:00'),
            'last_out' => Carbon::parse('2026-03-03 17:00:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        // Colour is status-only: both days are green Present cells; the
        // early day is distinguished only by its amber marked Out time.
        $this->assertStringNotContainsString('bg-amber-50 dark:bg-amber-900/20', $html);
        $this->assertSame(2, substr_count($html, 'bg-green-50 dark:bg-green-900/20'));
        $this->assertStringContainsString('aria-label="Left 4 minutes early"', $html);
    }

    public function test_the_legend_shows_a_marked_time_sample_instead_of_a_dot(): void
    {
        $employee = Employee::factory()->create();

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertStringContainsString('Late / Early leave', $html);
        // The sample is an amber marked time with no underline and no
        // colour swatch — timing never colours a cell.
        $this->assertStringContainsString('font-medium text-amber-700 dark:text-amber-300', $html);
        $this->assertStringNotContainsString('decoration-red', $html);
        $this->assertStringNotContainsString('bg-slate-700 dark:bg-slate-200', $html);
    }

    public function test_off_cells_show_no_time_placeholder(): void
    {
        $employee = Employee::factory()->create();
        // 2026-03-01 is a Sunday — not a scheduled workday.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-01',
            'status' => AttendanceStatus::Off,
            'first_in' => null,
            'last_out' => null,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH);

        // No "— → —" placeholder anywhere the Off cell would render one.
        $component->assertDontSeeHtml('— <span aria-hidden="true" class="opacity-60">&rarr;</span> —');
    }

    public function test_absent_cells_still_show_dash_placeholders_for_missing_times(): void
    {
        $employee = Employee::factory()->create();
        // 2026-03-02 is a Monday — a scheduled workday with nothing punched.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Absent,
            'first_in' => null,
            'last_out' => null,
        ]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSeeHtml('&rarr;');
    }

    public function test_time_format_is_12_hour_with_am_pm_and_is_identical_across_calendar_table_and_modal(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:52:00'),
            'last_out' => Carbon::parse('2026-03-02 17:15:00'),
        ]);

        // Calendar (default view).
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('8:52')
            ->assertSee('5:15')
            ->assertDontSeeHtml('08:52')
            ->assertDontSeeHtml('17:15');

        // Table view.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->set('view', 'table')
            ->assertSee('8:52')
            ->assertSee('5:15')
            ->assertDontSeeHtml('08:52')
            ->assertDontSeeHtml('17:15');

        // Day modal.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->call('openDay', '2026-03-02')
            ->assertSee('8:52')
            ->assertSee('5:15')
            ->assertDontSeeHtml('08:52')
            ->assertDontSeeHtml('17:15');
    }

    public function test_the_attendance_list_page_uses_the_same_12_hour_format(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'first_in' => today()->setTime(8, 52),
            'last_out' => today()->setTime(17, 15),
        ]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->assertSee('8:52')
            ->assertSee('5:15')
            ->assertDontSeeHtml('08:52')
            ->assertDontSeeHtml('17:15');
    }

    public function test_the_time_format_is_config_driven_not_hardcoded(): void
    {
        config(['attendance.time_format' => 'H:i']);

        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'first_in' => Carbon::parse('2026-03-02 08:52:00'),
            'last_out' => Carbon::parse('2026-03-02 17:15:00'),
        ]);

        // Not assertDontSee('AM')/('PM'): that scans the WHOLE page, including
        // the acting admin's Faker-generated name, which can coincidentally
        // contain that substring (flaky — passed in isolation, failed once
        // inside the full suite run purely from Faker RNG ordering). The
        // meridiem is always wrapped in its own span with nothing else
        // inside (see <x-time>), so checking for that exact tag boundary is
        // still a real "no meridiem was rendered" check, just not a fragile
        // whole-page substring one.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('08:52')
            ->assertSee('17:15')
            ->assertDontSeeHtml('>AM<')
            ->assertDontSeeHtml('>PM<');
    }

    public function test_the_modal_drops_seconds_from_raw_punch_times(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-03-02 08:52:37',
            'punch_type' => 'in',
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->call('openDay', '2026-03-02');

        $component->assertSee('8:52');
        $component->assertDontSeeHtml('8:52:37');
        $component->assertDontSeeHtml('08:52:37');
    }

    public function test_voiding_from_the_modal_goes_through_the_shared_confirm_dialog_not_a_direct_click(): void
    {
        $employee = Employee::factory()->create();
        $punch = AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-03-02 08:52:00',
            'punch_type' => 'in',
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->call('openDay', '2026-03-02')
            ->html();

        // The Void button dispatches to the confirm dialog rather than
        // calling voidPunch() directly — a stray click can't immediately
        // void a device record.
        $this->assertStringNotContainsString('wire:click="voidPunch('.$punch->id.')"', $html);
        $this->assertStringContainsString('confirm-dialog-attendance-show', $html);
        $this->assertStringContainsString("method: 'voidPunch'", $html);
        $this->assertStringContainsString('args: ['.$punch->id.']', $html);
    }

    public function test_the_modal_shows_the_days_schedule(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
        ]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->call('openDay', '2026-03-02')
            ->assertSee('Schedule')
            ->assertSee('8:00')
            ->assertSee('5:00');
    }

    public function test_query_count_is_still_equal_between_calendar_and_table_views(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        // Warm Spatie's role cache with an uncounted render first — measuring
        // twice in the same PHP process otherwise makes whichever view is
        // measured second look artificially cheaper (its role check already
        // hits a warm cache the first measurement paid to fill).
        Livewire::actingAs($admin)->test(Show::class, ['employee' => $employee]);

        DB::enableQueryLog();
        Livewire::withQueryParams(['view' => 'calendar'])->actingAs($admin)->test(Show::class, ['employee' => $employee]);
        $calendarCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        Livewire::withQueryParams(['view' => 'table'])->actingAs($admin)->test(Show::class, ['employee' => $employee]);
        $tableCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($tableCount, $calendarCount);
    }
}
