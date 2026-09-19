<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualPunchTest extends TestCase
{
    use RefreshDatabase;

    // A Monday, so it's a scheduled workday under the default Mon-Fri schedule.
    private const DAY = '2026-02-02';

    private const NEXT_DAY = '2026-02-03';

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

    private function build(Employee $employee, string $date): DailyAttendance
    {
        return app(DailySummaryBuilder::class)->build($employee, Carbon::parse($date));
    }

    /**
     * @return array{0: Testable, 1: Employee}
     */
    private function punchForm(string $joinDate, string $date, string $time): array
    {
        $employee = Employee::factory()->create(['join_date' => $joinDate]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', substr($date, 0, 7))
            ->call('startAddingPunch', $date)
            ->set('newPunchDate', $date)
            ->set('newPunchTime', $time)
            ->set('newPunchType', 'in');

        return [$component, $employee];
    }

    public function test_a_punch_dated_before_the_employees_start_date_is_rejected_and_nothing_is_written(): void
    {
        [$component, $employee] = $this->punchForm('2026-02-02', '2026-02-01', '08:00');

        $component->call('addPunch')->assertHasErrors(['newPunchDate']);

        $this->assertStringContainsString("before this employee's start date (Feb 2, 2026)", $component->errors()->first('newPunchDate'));
        $this->assertSame(0, AttendanceLog::where('employee_id', $employee->id)->count());
        $this->assertSame(0, DailyAttendance::where('employee_id', $employee->id)->count());
    }

    public function test_a_punch_on_the_start_date_itself_is_accepted(): void
    {
        [$component, $employee] = $this->punchForm('2026-02-02', '2026-02-02', '07:55');

        $component->call('addPunch')->assertHasNoErrors();

        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_a_punch_dated_in_the_future_is_rejected_and_nothing_is_written(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));

        [$component, $employee] = $this->punchForm('2020-01-01', '2026-02-11', '08:00');

        $component->call('addPunch')->assertHasErrors(['newPunchDate']);

        $this->assertStringContainsString('in the future', $component->errors()->first('newPunchDate'));
        $this->assertSame(0, AttendanceLog::where('employee_id', $employee->id)->count());
        $this->assertSame(0, DailyAttendance::where('employee_id', $employee->id)->count());
    }

    public function test_a_punch_today_is_rejected_only_if_it_is_later_than_the_current_time(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 10:00:00'));

        [$component, $employee] = $this->punchForm('2020-01-01', '2026-02-10', '10:30');

        $component->call('addPunch')->assertHasErrors(['newPunchTime']);

        $this->assertStringContainsString('later than the current time (10:00 AM)', $component->errors()->first('newPunchTime'));
        $this->assertSame(0, AttendanceLog::where('employee_id', $employee->id)->count());

        // Exactly now is not in the future.
        $component->set('newPunchTime', '10:00')->call('addPunch')->assertHasNoErrors();

        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_adding_a_manual_in_punch_turns_absent_into_incomplete(): void
    {
        $employee = Employee::factory()->create();
        $before = $this->build($employee, self::DAY);
        $this->assertSame(AttendanceStatus::Absent, $before->status);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        $after = DailyAttendance::where('id', $before->id)->first();
        $this->assertSame(AttendanceStatus::Incomplete, $after->status);
        $this->assertSame(self::DAY.' 07:55:00', $after->first_in->format('Y-m-d H:i:s'));
        $this->assertNull($after->last_out);
    }

    public function test_adding_the_matching_out_punch_turns_it_into_present_with_correct_minutes(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 07:55:00',
            'punch_type' => 'in',
        ]);
        $before = $this->build($employee, self::DAY);
        $this->assertSame(AttendanceStatus::Incomplete, $before->status);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '17:00')
            ->set('newPunchType', 'out')
            ->call('addPunch')
            ->assertHasNoErrors();

        $after = DailyAttendance::where('id', $before->id)->first();
        $this->assertSame(AttendanceStatus::Present, $after->status);
        // 07:55 -> 17:00 = 545 minutes, minus 60 break = 485.
        $this->assertSame(485, $after->worked_minutes);
    }

    public function test_voiding_a_punch_reverts_the_days_status(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 07:55:00',
            'punch_type' => 'in',
        ]);
        $outPunch = AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 17:00:00',
            'punch_type' => 'out',
        ]);
        $before = $this->build($employee, self::DAY);
        $this->assertSame(AttendanceStatus::Present, $before->status);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('voidPunch', $outPunch->id);

        $after = DailyAttendance::where('id', $before->id)->first();
        $this->assertSame(AttendanceStatus::Incomplete, $after->status);
        $this->assertNull($after->last_out);

        $this->assertNotNull($outPunch->fresh()->voided_at);
    }

    /**
     * The (employee_id, punched_at, source) unique index has no voided_at
     * in it (see the migration's comment for why that would be unsafe), so
     * a voided row permanently occupies its exact key — re-adding at the
     * same time must revive that row, not attempt (and fail) an insert.
     */
    public function test_adding_a_punch_at_the_same_time_as_a_voided_one_revives_it_instead_of_failing(): void
    {
        $employee = Employee::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        $punch = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();
        $component->call('voidPunch', $punch->id);
        $this->assertNotNull($punch->fresh()->voided_at);

        // Re-add at the exact same date/time/type.
        $component
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        // Still one row (revived), not two, and no longer voided.
        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
        $revived = $punch->fresh();
        $this->assertSame($punch->id, $revived->id);
        $this->assertNull($revived->voided_at);
        $this->assertNull($revived->voided_by);

        // The day's summary reflects the re-added punch, not a stale "still
        // voided" state.
        $row = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('work_date', self::DAY)
            ->first();
        $this->assertSame(self::DAY.' 07:55:00', $row->first_in->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
    }

    /**
     * The revive-on-exact-match lookup in addPunch() is scoped to
     * source=manual, matching the unique index's full (employee_id,
     * punched_at, source) key — a voided DEVICE punch at the same timestamp
     * has a different source, so it's neither found nor collided with. A
     * manual add there must produce a second, independent row rather than
     * resurrecting the device row under a manual identity (which would
     * falsely claim the device reported something it never did).
     */
    public function test_a_voided_device_punch_is_not_revived_by_a_manual_add_at_the_same_time(): void
    {
        $employee = Employee::factory()->create();
        $devicePunch = AttendanceLog::factory()->voided()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 07:55:00',
            'punch_type' => 'in',
            'source' => 'device',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        // The voided device row is untouched...
        $devicePunch->refresh();
        $this->assertNotNull($devicePunch->voided_at);
        $this->assertSame('device', $devicePunch->source->value);

        // ...and a separate, new manual row now exists alongside it.
        $manualPunch = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('source', 'manual')
            ->first();
        $this->assertNotNull($manualPunch);
        $this->assertNotSame($devicePunch->id, $manualPunch->id);
        $this->assertNull($manualPunch->voided_at);
        $this->assertSame(2, AttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_a_voided_punch_is_excluded_from_overnight_pairing_on_the_following_day(): void
    {
        $employee = Employee::factory()->create();
        $inPunch = AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 20:00:00',
            'punch_type' => 'in',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::NEXT_DAY.' 01:00:00',
            'punch_type' => 'out',
        ]);

        $dayBefore = $this->build($employee, self::DAY);
        $nextDay = $this->build($employee, self::NEXT_DAY);
        // Present with a late-arrival timing exception, not a separate
        // status (see AttendanceStatus's doc comment): first_in (20:00) is
        // measured against this same calendar day's 08:00 scheduled start,
        // which an evening check-in is always well past — worked_minutes/
        // pairing is unaffected either way.
        $this->assertSame(AttendanceStatus::Present, $dayBefore->status);
        $this->assertTrue($dayBefore->isLate());
        // Tuesday's only punch was already claimed as Monday's overnight
        // tail — Tuesday isn't a scheduled workday's absence either way here
        // since it's still built from the pairing, just with nothing of its
        // own; the key fact is it has no last_out of its own yet.
        $this->assertNull($nextDay->last_out);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('voidPunch', $inPunch->id);

        // Voiding Monday's in-punch must rebuild Tuesday too (not just
        // Monday) — otherwise Tuesday's stale row keeps reading as if
        // Monday's shift still claims its out-punch.
        $tuesdayAfter = DailyAttendance::where('id', $nextDay->id)->first();
        $this->assertSame(self::NEXT_DAY.' 01:00:00', $tuesdayAfter->last_out->format('Y-m-d H:i:s'));
        $this->assertSame(AttendanceStatus::Incomplete, $tuesdayAfter->status);
    }

    public function test_adding_a_punch_just_after_midnight_rebuilds_the_previous_day_correctly(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::DAY.' 20:00:00',
            'punch_type' => 'in',
        ]);
        $before = $this->build($employee, self::DAY);
        $this->assertSame(AttendanceStatus::Incomplete, $before->status);

        // The admin opens the *next* day's row (where the punch actually
        // falls) to add the missing overnight out-punch that finishes the
        // previous day's shift.
        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::NEXT_DAY)
            ->set('newPunchDate', self::NEXT_DAY)
            ->set('newPunchTime', '00:30')
            ->set('newPunchType', 'out')
            ->call('addPunch')
            ->assertHasNoErrors();

        $after = DailyAttendance::where('id', $before->id)->first();
        // Present with a late-arrival timing exception — see the note in
        // the overnight-pairing test above; first_in at 20:00 is always
        // past this day's 08:00 start.
        $this->assertSame(AttendanceStatus::Present, $after->status);
        $this->assertTrue($after->isLate());
        // 20:00 -> 00:30 next day = 270 minutes, minus 60 break = 210.
        $this->assertSame(210, $after->worked_minutes);
    }

    public function test_a_manual_punch_records_created_by_and_a_void_records_voided_by(): void
    {
        $employee = Employee::factory()->create();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch');

        $punch = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($admin->id, $punch->created_by);

        Livewire::actingAs($admin)
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-02')
            ->call('voidPunch', $punch->id);

        $this->assertSame($admin->id, $punch->fresh()->voided_by);
    }

    public function test_a_manager_cannot_add_or_void_punches(): void
    {
        $topManager = Employee::factory()->create();
        $subordinate = Employee::factory()->create(['manager_id' => $topManager->id]);
        $managerUser = User::factory()->create()->assignRole('manager');
        $topManager->update(['user_id' => $managerUser->id]);

        $punch = AttendanceLog::factory()->create([
            'employee_id' => $subordinate->id,
            'punched_at' => self::DAY.' 07:55:00',
            'punch_type' => 'in',
        ]);

        Livewire::actingAs($managerUser)
            ->test(Show::class, ['employee' => $subordinate])
            ->set('month', '2026-02')
            ->call('addPunch')
            ->assertForbidden();

        // A denied authorize() call leaves the component in a 403 response,
        // not a normal snapshot — a fresh instance is needed for the second
        // assertion (see CLAUDE.md's Livewire authorization testing note).
        Livewire::actingAs($managerUser)
            ->test(Show::class, ['employee' => $subordinate])
            ->set('month', '2026-02')
            ->call('voidPunch', $punch->id)
            ->assertForbidden();

        $this->assertNull($punch->fresh()->voided_at);
    }
}
