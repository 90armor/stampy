<?php

namespace Tests\Feature\Attendance;

use App\Livewire\Attendance\Show;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * F07: a day with no row because it hasn't happened yet — or, since the
 * follow-up, because it's before the employee joined — is a quiet cell with no
 * status, and the amber notice appears only for a past workday with no row.
 * Today is past once the scheduler has closed it (the schedule's end plus one
 * 15-minute run).
 */
class UpcomingDaysTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);

        $this->employee = Employee::factory()->create();
        Holiday::create(['date' => '2026-04-20', 'name' => 'Spring holiday']);

        // Wed 15 Apr 2026 at noon, with 1–14 Apr built; today has no row yet.
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));
        for ($date = Carbon::parse('2026-04-01'); $date->lte(Carbon::parse('2026-04-14')); $date->addDay()) {
            app(DailySummaryBuilder::class)->build($this->employee, $date->copy());
        }
    }

    private function page(string $view = 'calendar')
    {
        return Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-04')
            ->set('view', $view);
    }

    public function test_future_days_have_no_status_and_no_notice(): void
    {
        $this->page()
            ->assertDontSee('been calculated for')
            ->assertSeeHtml('aria-label="Thursday, 16 April 2026"')
            ->assertDontSee('Thursday, 16 April 2026, Not calculated')
            // Today, before it closes, is upcoming too.
            ->assertSeeHtml('aria-label="Wednesday, 15 April 2026"');
    }

    public function test_an_upcoming_holiday_keeps_its_name_and_flag(): void
    {
        $this->page()
            ->assertSeeHtml('aria-label="Monday, 20 April 2026, Holiday: Spring holiday"')
            ->assertSee('Spring holiday');
    }

    public function test_today_counts_as_past_once_the_scheduler_has_closed_it(): void
    {
        $this->travelTo(Carbon::parse('2026-04-15 17:14:00'));
        $this->page()->assertDontSee('been calculated for');

        $this->travelTo(Carbon::parse('2026-04-15 17:15:00'));
        $this->page()
            ->assertSeeHtml('<strong>Wed 15 Apr</strong>')
            ->assertSee('Wednesday, 15 April 2026, Not calculated');
    }

    public function test_the_table_view_shows_no_status_for_upcoming_days(): void
    {
        $page = $this->page('table');

        $page->assertDontSee('Not calculated');
    }

    public function test_the_table_view_still_marks_a_missing_past_workday(): void
    {
        DailyAttendance::query()->whereDate('work_date', '2026-04-14')->delete();

        $this->page('table')
            ->assertSee('Not calculated')
            ->assertSeeHtml('<strong>Tue 14 Apr</strong>');
    }

    public function test_days_before_the_join_date_are_quiet_too(): void
    {
        // Joined Wed 8 Apr; 8–14 Apr built. 1–7 Apr were before they joined:
        // nothing to calculate, so no "Not calculated" and no notice.
        $joiner = Employee::factory()->create(['join_date' => '2026-04-08']);
        for ($date = Carbon::parse('2026-04-08'); $date->lte(Carbon::parse('2026-04-14')); $date->addDay()) {
            app(DailySummaryBuilder::class)->build($joiner, $date->copy());
        }

        foreach (['calendar', 'table'] as $view) {
            Livewire::actingAs(User::factory()->create()->assignRole('admin'))
                ->test(Show::class, ['employee' => $joiner])
                ->set('month', '2026-04')
                ->set('view', $view)
                ->assertDontSee('been calculated for')
                ->assertDontSee('Not calculated');
        }
    }

    public function test_an_upcoming_days_modal_has_no_status_pill(): void
    {
        $this->page()
            ->call('openDay', '2026-04-16')
            ->assertSee('Thursday, 16 April 2026')
            ->assertDontSee('Not calculated');
    }
}
