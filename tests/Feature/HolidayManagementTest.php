<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Livewire\Holidays\Index;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    // A Monday — a scheduled workday under the schedule seeded below.
    private const WORKDAY = '2026-02-02';

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

    public function test_admin_can_create_a_holiday(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('date', self::WORKDAY)
            ->set('name', 'Test Holiday')
            ->call('save');

        $this->assertDatabaseHas('holidays', ['date' => self::WORKDAY, 'name' => 'Test Holiday']);
    }

    public function test_admin_can_edit_a_holiday(): void
    {
        $admin = $this->admin();
        $holiday = Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Old Name']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $holiday->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $holiday->fresh()->name);
    }

    public function test_duplicate_date_is_a_validation_error_naming_the_existing_holiday(): void
    {
        $admin = $this->admin();
        Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('date', self::WORKDAY)
            ->set('name', 'Something Else')
            ->call('save')
            ->assertHasErrors(['date'])
            ->assertSee('Test Holiday');

        // Not a second row for the same date.
        $this->assertSame(1, Holiday::where('date', self::WORKDAY)->count());
    }

    public function test_editing_a_holiday_can_keep_its_own_date(): void
    {
        $admin = $this->admin();
        $holiday = Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $holiday->id)
            ->set('date', self::WORKDAY)
            ->set('name', 'Test Holiday (renamed)')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_manager_cannot_access_holidays(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('organization.index'))
            ->assertForbidden();
    }

    public function test_creating_a_holiday_rebuilds_that_date_for_all_active_employees(): void
    {
        $admin = $this->admin();
        $active = Employee::factory()->create(['status' => 'active']);
        $inactive = Employee::factory()->create(['status' => 'inactive']);

        // Both start the day absent (no punches, an ordinary workday).
        app(DailySummaryBuilder::class)->build($active, Carbon::parse(self::WORKDAY));
        app(DailySummaryBuilder::class)->build($inactive, Carbon::parse(self::WORKDAY));
        $this->assertSame(AttendanceStatus::Absent, DailyAttendance::where('employee_id', $active->id)->first()->status);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('date', self::WORKDAY)
            ->set('name', 'Test Holiday')
            ->call('save');

        $this->assertSame(
            AttendanceStatus::Holiday,
            DailyAttendance::where('employee_id', $active->id)->where('work_date', self::WORKDAY)->first()->status
        );
        // Inactive employees are explicitly out of scope for the rebuild —
        // their stale row is left exactly as it was.
        $this->assertSame(
            AttendanceStatus::Absent,
            DailyAttendance::where('employee_id', $inactive->id)->where('work_date', self::WORKDAY)->first()->status
        );
    }

    public function test_deleting_a_holiday_reverts_the_rebuilt_days(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create(['status' => 'active']);
        $holiday = Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);
        app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));
        $this->assertSame(
            AttendanceStatus::Holiday,
            DailyAttendance::where('employee_id', $employee->id)->first()->status
        );

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('delete', $holiday->id);

        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
        $this->assertSame(
            AttendanceStatus::Absent,
            DailyAttendance::where('employee_id', $employee->id)->where('work_date', self::WORKDAY)->first()->status
        );
    }

    public function test_editing_a_holidays_date_rebuilds_both_the_old_and_new_dates(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create(['status' => 'active']);
        $oldDate = self::WORKDAY;
        $newDate = Carbon::parse(self::WORKDAY)->addDay()->format('Y-m-d'); // Tuesday — also a workday
        $holiday = Holiday::factory()->create(['date' => $oldDate, 'name' => 'Test Holiday']);

        app(DailySummaryBuilder::class)->build($employee, Carbon::parse($oldDate));
        app(DailySummaryBuilder::class)->build($employee, Carbon::parse($newDate));
        $this->assertSame(AttendanceStatus::Holiday, DailyAttendance::where('work_date', $oldDate)->first()->status);
        $this->assertSame(AttendanceStatus::Absent, DailyAttendance::where('work_date', $newDate)->first()->status);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $holiday->id)
            ->set('date', $newDate)
            ->call('save');

        $this->assertSame(
            AttendanceStatus::Absent,
            DailyAttendance::where('employee_id', $employee->id)->where('work_date', $oldDate)->first()->status
        );
        $this->assertSame(
            AttendanceStatus::Holiday,
            DailyAttendance::where('employee_id', $employee->id)->where('work_date', $newDate)->first()->status
        );
    }

    public function test_a_worked_holiday_deleted_reverts_to_present_with_its_real_timing(): void
    {
        // A more revealing revert check than plain absent: a holiday that
        // was WORKED must go back to reporting its actual late/early
        // minutes once the holiday is gone, not just flip a status label.
        $admin = $this->admin();
        $employee = Employee::factory()->create(['status' => 'active']);
        $holiday = Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 08:25:00',
            'punch_type' => 'in',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 16:00:00',
            'punch_type' => 'out',
        ]);
        app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));
        $built = DailyAttendance::where('employee_id', $employee->id)->first();
        $this->assertSame(0, $built->late_minutes);
        $this->assertSame(0, $built->early_leave_minutes);

        Livewire::actingAs($admin)->test(Index::class)->call('delete', $holiday->id);

        $reverted = DailyAttendance::where('employee_id', $employee->id)->where('work_date', self::WORKDAY)->first();
        $this->assertSame(AttendanceStatus::Present, $reverted->status);
        $this->assertSame(25, $reverted->late_minutes);
        $this->assertSame(60, $reverted->early_leave_minutes);
    }

    /**
     * The component's mount() refuses anyone but an admin, so to drive an
     * action directly the page is opened as an admin and the acting user is
     * then swapped — the action must re-check on its own, not lean on mount().
     */
    private function pageOpenedByAnAdminThenDowngradedTo(string $role): Testable
    {
        $component = Livewire::actingAs(User::factory()->create()->assignRole('admin'))->test(Index::class);

        $this->actingAs(User::factory()->create()->assignRole($role));

        return $component;
    }

    public function test_a_non_admin_cannot_mount_the_component_directly(): void
    {
        foreach (['manager', 'employee'] as $role) {
            Livewire::actingAs(User::factory()->create()->assignRole($role))
                ->test(Index::class)
                ->assertForbidden();
        }
    }

    public function test_every_action_re_authorizes_when_driven_directly_by_a_non_admin(): void
    {
        $existing = Holiday::factory()->create(['name' => 'Existing Holiday', 'date' => '2026-03-03']);

        foreach (['manager', 'employee'] as $role) {
            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('create')->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('edit', $existing->id)->assertForbidden();

            // save() without going through create()/edit() first.
            $this->pageOpenedByAnAdminThenDowngradedTo($role)
                ->set('date', '2026-04-04')
                ->set('name', 'Direct Save')
                ->call('save')
                ->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('delete', $existing->id)->assertForbidden();
        }

        $this->assertDatabaseHas('holidays', ['id' => $existing->id]);
        $this->assertDatabaseMissing('holidays', ['name' => 'Direct Save']);
    }
}
