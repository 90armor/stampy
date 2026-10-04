<?php

namespace Tests\Feature\Attendance;

use App\Console\AttendanceSchedule;
use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AttendanceSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00-17:00, Mon-Fri.
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    /**
     * The two rebuild tasks as they'd be registered at a given moment (the real
     * schedule computes its dates when it is registered, i.e. on every tick).
     *
     * @return array{today: Event, yesterday: Event, heal: Event}
     */
    private function tasksAt(string $now): array
    {
        $this->travelTo(Carbon::parse($now));

        $schedule = new Schedule;
        AttendanceSchedule::register($schedule);

        $events = array_values(array_filter($schedule->events(), fn (Event $e) => str_contains($e->command, 'attendance:build-daily')));
        $this->assertCount(3, $events);

        return ['today' => $events[0], 'yesterday' => $events[1], 'heal' => $events[2]];
    }

    /**
     * @return array<string, string> e.g. ['--date' => '2026-02-10']
     */
    private function optionsOf(Event $event): array
    {
        preg_match_all("/--([a-z]+)='?([^'\\s]+)'?/", $event->command, $matches, PREG_SET_ORDER);

        $options = [];
        foreach ($matches as [, $name, $value]) {
            $options["--{$name}"] = $value;
        }

        return $options;
    }

    /** Runs a scheduled task's own command line, in-process, so the clock stays frozen. */
    private function runScheduled(Event $event): void
    {
        $this->artisan('attendance:build-daily', $this->optionsOf($event))->assertSuccessful();
    }

    /**
     * @return list<string>
     */
    private function builtDates(Employee $employee): array
    {
        return DailyAttendance::where('employee_id', $employee->id)->orderBy('work_date')->get()
            ->map(fn ($row) => $row->work_date->format('Y-m-d'))->all();
    }

    public function test_the_real_application_schedule_has_all_three_rebuild_tasks(): void
    {
        // withSchedule() only registers once the console application starts, as it does under schedule:work.
        $this->artisan('schedule:list')->assertSuccessful();

        $tasks = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains($e->command, 'attendance:build-daily'))
            // The test harness can start the console more than once per test (RefreshDatabase's migrate does too),
            // which registers the same task again; the scheduler container starts it exactly once.
            ->unique(fn (Event $e) => $e->expression.'|'.$e->command.'|'.$e->output)
            ->map(fn (Event $e) => $e->expression)
            ->sort()
            ->values()
            ->all();

        // Today and (while it has open rows) yesterday every 15 minutes; the healing window each night.
        $this->assertSame(['*/15 * * * *', '*/15 * * * *', '10 2 * * *'], $tasks);
    }

    public function test_one_task_rebuilds_only_today_every_fifteen_minutes(): void
    {
        $today = $this->tasksAt('2026-02-10 09:00:00')['today'];

        $this->assertSame('*/15 * * * *', $today->expression);
        $this->assertSame(['--date' => '2026-02-10'], $this->optionsOf($today));
        $this->assertTrue($today->withoutOverlapping);
    }

    public function test_the_other_task_rebuilds_the_last_seven_days_not_just_yesterday_each_early_morning(): void
    {
        $heal = $this->tasksAt('2026-02-10 09:00:00')['heal'];

        $this->assertSame('10 2 * * *', $heal->expression);
        // Seven days inclusive of today.
        $this->assertSame(['--from' => '2026-02-04', '--to' => '2026-02-10'], $this->optionsOf($heal));
        $this->assertTrue($heal->withoutOverlapping);
    }

    public function test_the_dates_follow_the_clock_each_time_the_schedule_is_registered(): void
    {
        $this->assertSame(['--date' => '2026-02-10'], $this->optionsOf($this->tasksAt('2026-02-10 09:00:00')['today']));
        $this->assertSame(['--date' => '2026-02-11'], $this->optionsOf($this->tasksAt('2026-02-11 09:00:00')['today']));
    }

    public function test_a_day_built_as_in_progress_becomes_absent_when_the_task_runs_after_end_time(): void
    {
        $employee = Employee::factory()->create();

        // Monday 10:00: the shift hasn't ended, so no punches yet means "not yet".
        $this->runScheduled($this->tasksAt('2026-02-09 10:00:00')['today']);
        $this->assertSame(AttendanceStatus::InProgress, DailyAttendance::where('employee_id', $employee->id)->firstOrFail()->status);

        // 17:15: end_time has passed; the next 15-minute run finalises it.
        $this->runScheduled($this->tasksAt('2026-02-09 17:15:00')['today']);
        $this->assertSame(AttendanceStatus::Absent, DailyAttendance::where('employee_id', $employee->id)->firstOrFail()->status);
    }

    public function test_the_yesterday_task_closes_a_late_in_only_row_once_its_pairing_window_passes(): void
    {
        $employee = Employee::factory()->create();
        // Monday 09:30 in-punch, no out: the pairing window closes Tuesday 03:30.
        \App\Models\AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => '2026-02-09 09:30:00', 'punch_type' => 'in']);

        // Monday evening, past end time: still in progress (overtime is still "at work").
        $this->runScheduled($this->tasksAt('2026-02-09 18:00:00')['today']);
        $row = fn () => DailyAttendance::where('employee_id', $employee->id)->whereDate('work_date', '2026-02-09')->firstOrFail();
        $this->assertSame(AttendanceStatus::InProgress, $row()->status);

        // Tuesday 03:15: the yesterday task is due (Monday has an open row) but the window hasn't closed yet.
        $yesterday = $this->tasksAt('2026-02-10 03:15:00')['yesterday'];
        $this->assertSame(['--date' => '2026-02-09'], $this->optionsOf($yesterday));
        $this->assertTrue($yesterday->filtersPass($this->app));
        $this->runScheduled($yesterday);
        $this->assertSame(AttendanceStatus::InProgress, $row()->status);

        // Tuesday 03:45: the next run closes it, with no wait for 02:10 the following night.
        $yesterday = $this->tasksAt('2026-02-10 03:45:00')['yesterday'];
        $this->runScheduled($yesterday);
        $this->assertSame(AttendanceStatus::Incomplete, $row()->status);

        // Nothing open any more: the task is skipped from now on.
        $this->assertFalse($this->tasksAt('2026-02-10 04:00:00')['yesterday']->filtersPass($this->app));
    }

    public function test_the_seven_day_task_rebuilds_a_gap_in_the_middle_of_its_window(): void
    {
        $employee = Employee::factory()->create();

        // Everything from Feb 3 to Feb 10 gets built once...
        $this->travelTo(Carbon::parse('2026-02-10 09:00:00'));
        $this->artisan('attendance:build-daily', ['--from' => '2026-02-03', '--to' => '2026-02-10'])->assertSuccessful();

        // ...then downtime leaves a hole on Feb 7 (inside the window) and Feb 3 (older than it).
        DailyAttendance::where('employee_id', $employee->id)->whereIn('work_date', ['2026-02-07', '2026-02-03'])->delete();
        $this->assertNotContains('2026-02-07', $this->builtDates($employee));

        $this->runScheduled($this->tasksAt('2026-02-10 09:00:00')['heal']);

        $this->assertContains('2026-02-07', $this->builtDates($employee), 'a gap inside the 7-day window heals on its own');
        $this->assertNotContains('2026-02-03', $this->builtDates($employee), 'older than the window needs a manual run (see CLAUDE.md)');
    }

    public function test_each_task_captures_its_output_in_a_fixed_file_so_none_pile_up_day_after_day(): void
    {
        $monday = $this->tasksAt('2026-02-09 09:00:00');
        $tuesday = $this->tasksAt('2026-02-10 09:00:00');

        $this->assertSame(storage_path('logs/attendance-rebuild-today.log'), $monday['today']->output);
        $this->assertSame(storage_path('logs/attendance-rebuild-heal.log'), $monday['heal']->output);
        $this->assertSame(storage_path('logs/attendance-rebuild-yesterday.log'), $monday['yesterday']->output);
        $this->assertSame($monday['yesterday']->output, $tuesday['yesterday']->output);
        $this->assertSame($monday['today']->output, $tuesday['today']->output);
        $this->assertSame($monday['heal']->output, $tuesday['heal']->output);
    }

    public function test_a_successful_run_logs_nothing(): void
    {
        $today = $this->tasksAt('2026-02-10 09:00:00')['today'];
        $today->sendOutputTo($output = tempnam(sys_get_temp_dir(), 'schedule'));
        file_put_contents($output, 'Built daily attendance for 2026-02-10');

        Log::shouldReceive('error')->never();

        $today->finish($this->app, 0);

        @unlink($output);
    }

    public function test_a_failure_is_logged_with_the_command_output(): void
    {
        $today = $this->tasksAt('2026-02-10 09:00:00')['today'];
        $today->sendOutputTo($output = tempnam(sys_get_temp_dir(), 'schedule'));
        file_put_contents($output, 'No default work schedule exists: nothing to measure against');

        Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'Scheduled attendance rebuild (today) failed')
            && str_contains($message, 'No default work schedule exists'));

        // What the scheduler itself calls once the command has exited non-zero.
        $today->finish($this->app, 1);

        @unlink($output);
    }
}
