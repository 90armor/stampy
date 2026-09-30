<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Show;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The calendar's holiday layer specifically — read straight from
 * `holidays`, not from `daily_attendances` (see Attendance\Show::
 * holidaysByDate()'s doc comment). Builder-level status/timing rules for a
 * holiday live in HolidayAttendanceTest instead.
 */
class HolidayCalendarDisplayTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_future_holiday_with_no_daily_attendance_row_still_renders_its_name(): void
    {
        $employee = Employee::factory()->create();
        // Deliberately no DailyAttendance row for this date at all.
        Holiday::factory()->create(['date' => '2026-03-20', 'name' => 'Second Test Holiday']);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('Second Test Holiday');
    }

    public function test_a_built_workday_holiday_shows_both_its_name_and_the_holiday_colour(): void
    {
        $employee = Employee::factory()->create();
        Holiday::factory()->create(['date' => '2026-03-02', 'name' => 'Test Holiday']);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Holiday,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertStringContainsString('Test Holiday', $html);
        $this->assertStringContainsString('bg-fuchsia-50 dark:bg-fuchsia-900/20', $html);
    }

    public function test_a_weekend_holiday_shows_the_name_with_the_off_colour_not_holiday_colour(): void
    {
        // 2026-03-01 is a Sunday.
        $employee = Employee::factory()->create();
        Holiday::factory()->create(['date' => '2026-03-01', 'name' => 'Company Anniversary']);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-01',
            'status' => AttendanceStatus::Off,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH);

        $component->assertSee('Company Anniversary');
        $component->assertSee('March 1, 2026, Off, Holiday: Company Anniversary');
    }

    public function test_a_worked_holiday_shows_the_name_with_the_present_colour(): void
    {
        $employee = Employee::factory()->create();
        Holiday::factory()->create(['date' => '2026-03-02', 'name' => 'Test Holiday']);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->html();

        $this->assertStringContainsString('Test Holiday', $html);
        // A worked holiday is Present: the neutral cell surface with the green
        // day number and check (fill is reserved for exceptions).
        $this->assertStringContainsString('bg-white dark:bg-slate-900 ring-slate-200 dark:ring-slate-800', $html);
        $this->assertStringContainsString('text-green-700 dark:text-green-400', $html);
    }

    public function test_holidays_are_not_shown_on_out_of_month_padding_cells(): void
    {
        $employee = Employee::factory()->create();
        // March 2026 starts on a Sunday (zero leading padding) and has 31
        // days, so 2026-04-01 is one of its trailing padding cells.
        Holiday::factory()->create(['date' => '2026-04-01', 'name' => 'Should Not Appear']);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertDontSee('Should Not Appear');
    }

    public function test_the_legend_lists_in_progress_and_holiday(): void
    {
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::MONTH)
            ->assertSee('In progress')
            ->assertSee('Holiday');
    }
}
