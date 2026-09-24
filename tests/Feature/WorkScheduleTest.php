<?php

namespace Tests\Feature;

use App\Exceptions\InvalidWorkScheduleException;
use App\Exceptions\WorkScheduleInUseException;
use App\Exceptions\WorkScheduleIsDefaultException;
use App\Exceptions\WorkScheduleLockedException;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkSchedule's own model-level invariants (App\Livewire\Schedules\Index
 * covers the admin UI built on top of these). All of it is enforced here,
 * not just documented, so a future caller — the UI, an API, a seeder — can't
 * bypass it by going around the form.
 */
class WorkScheduleTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_an_unreferenced_schedules_hours_can_be_changed(): void
    {
        $schedule = $this->schedule();

        $schedule->update(['start_time' => '09:00:00']);

        $this->assertSame('09:00:00', $schedule->fresh()->start_time);
    }

    public function test_a_schedules_hours_are_locked_once_an_employee_is_assigned_to_it(): void
    {
        $default = $this->schedule(['is_default' => true]);
        Employee::factory()->create(); // assigned to $default automatically

        $this->expectException(WorkScheduleLockedException::class);

        $default->update(['start_time' => '09:00:00']);
    }

    public function test_a_schedules_hours_are_locked_once_a_daily_attendance_row_references_it(): void
    {
        $this->schedule(['is_default' => true]); // DailyAttendanceFactory's nested Employee needs one
        $schedule = $this->schedule();
        DailyAttendance::factory()->create(['work_schedule_id' => $schedule->id]);

        $this->expectException(WorkScheduleLockedException::class);

        $schedule->update(['end_time' => '18:00:00']);
    }

    public function test_a_locked_schedules_name_can_still_be_changed(): void
    {
        $default = $this->schedule(['is_default' => true]);
        Employee::factory()->create();

        $default->update(['name' => 'Renamed']);

        $this->assertSame('Renamed', $default->fresh()->name);
    }

    public function test_a_referenced_schedule_cannot_be_deleted(): void
    {
        $default = $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create();

        // A second schedule becomes default so the delete attempt below fails
        // on "in use", not "is the default" — isolating the case under test.
        // $default is refreshed since the swap updated its is_default column
        // directly (a query, not this in-memory instance).
        $this->schedule(['is_default' => true]);
        $default->refresh();

        $this->expectException(WorkScheduleInUseException::class);

        $default->delete();
    }

    public function test_an_unreferenced_non_default_schedule_can_be_deleted(): void
    {
        $this->schedule(['is_default' => true]);
        $unused = $this->schedule();

        $unused->delete();

        $this->assertNull($unused->fresh());
    }

    public function test_the_default_schedule_cannot_be_deleted(): void
    {
        $default = $this->schedule(['is_default' => true]);

        $this->expectException(WorkScheduleIsDefaultException::class);

        $default->delete();
    }

    public function test_the_default_schedule_cannot_be_unset_directly(): void
    {
        $default = $this->schedule(['is_default' => true]);

        $this->expectException(WorkScheduleIsDefaultException::class);

        $default->update(['is_default' => false]);
    }

    public function test_setting_a_new_default_unsets_the_old_one(): void
    {
        $old = $this->schedule(['is_default' => true]);
        $new = $this->schedule(['is_default' => true]);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($new->fresh()->is_default);
        $this->assertSame(1, WorkSchedule::where('is_default', true)->count());
    }

    public function test_end_time_at_or_before_start_time_is_rejected(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);

        $this->schedule(['start_time' => '17:00:00', 'end_time' => '17:00:00']);
    }

    public function test_end_time_before_start_time_is_rejected(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);

        $this->schedule(['start_time' => '17:00:00', 'end_time' => '08:00:00']);
    }

    public function test_a_schedule_needs_at_least_one_workday(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);

        $this->schedule(['workdays' => []]);
    }

    public function test_break_minutes_must_be_shorter_than_the_shift(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);

        // An 08:00-12:00 shift is 240 minutes; a break of exactly that long
        // leaves no time to actually work.
        $this->schedule(['start_time' => '08:00:00', 'end_time' => '12:00:00', 'break_minutes' => 240]);
    }

    public function test_break_minutes_shorter_than_the_shift_is_accepted(): void
    {
        $schedule = $this->schedule(['start_time' => '08:00:00', 'end_time' => '12:00:00', 'break_minutes' => 239]);

        $this->assertSame(239, $schedule->break_minutes);
    }
}
