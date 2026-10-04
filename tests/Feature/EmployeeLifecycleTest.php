<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Exceptions\InvalidEmploymentPeriodException;
use App\Livewire\Employees\Show;
use App\Livewire\Employees\StatusModal;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\DashboardAttendance;
use App\Support\EmployeeLeftOnBackfill;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3a — employees.left_on: the status/employment-period invariants,
 * "active on a date", deactivate/reactivate through StatusModal and the
 * rebuild that follows, and the dashboard trend measured per day.
 */
class EmployeeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // Wednesday 15 April 2026, midday; the factory schedule is 08:00–17:00 Mon–Fri.
    private const NOW = '2026-04-15 12:00:00';

    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse(self::NOW));
        $this->schedule = WorkSchedule::factory()->create(['is_default' => true]);

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /** @return list<string> */
    private function builtDates(Employee $employee): array
    {
        return DailyAttendance::where('employee_id', $employee->id)
            ->orderBy('work_date')
            ->get()
            ->map(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'))
            ->all();
    }

    // ── Invariants ──────────────────────────────────────────────────────

    public function test_an_inactive_employee_needs_a_left_on(): void
    {
        $this->expectException(InvalidEmploymentPeriodException::class);

        Employee::factory()->create(['status' => 'inactive', 'left_on' => null]);
    }

    public function test_an_active_employee_cannot_have_a_left_on(): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(InvalidEmploymentPeriodException::class);

        $employee->update(['left_on' => '2026-04-01']);
    }

    public function test_left_on_cannot_be_before_join_date(): void
    {
        $this->expectException(InvalidEmploymentPeriodException::class);

        Employee::factory()->inactive('2026-02-28')->create(['join_date' => '2026-03-01']);
    }

    public function test_left_on_cannot_be_in_the_future(): void
    {
        $this->expectException(InvalidEmploymentPeriodException::class);

        Employee::factory()->inactive('2026-04-16')->create();
    }

    public function test_left_on_may_equal_join_date_or_today(): void
    {
        $sameDay = Employee::factory()->inactive('2026-03-01')->create(['join_date' => '2026-03-01']);
        $today = Employee::factory()->inactive('2026-04-15')->create();

        $this->assertSame('2026-03-01', $sameDay->fresh()->left_on->format('Y-m-d'));
        $this->assertSame('2026-04-15', $today->fresh()->left_on->format('Y-m-d'));
    }

    public function test_moving_join_date_past_left_on_is_rejected_on_the_model(): void
    {
        $employee = Employee::factory()->inactive('2026-03-31')->create(['join_date' => '2026-01-01']);

        $this->expectException(InvalidEmploymentPeriodException::class);

        $employee->update(['join_date' => '2026-04-01']);
    }

    // ── Active on a date ────────────────────────────────────────────────

    /**
     * The scope and the instance method are one rule: they agree on every
     * boundary, for a current and a former employee.
     */
    public function test_active_on_scope_and_instance_method_agree_at_every_boundary(): void
    {
        $current = Employee::factory()->create(['join_date' => '2026-03-10']);
        $former = Employee::factory()->inactive('2026-04-05')->create(['join_date' => '2026-03-10']);

        $expected = [
            '2026-03-09' => [false, false],
            '2026-03-10' => [true, true],
            '2026-04-05' => [true, true],
            '2026-04-06' => [true, false],
            '2026-04-15' => [true, false],
        ];

        foreach ($expected as $date => [$currentActive, $formerActive]) {
            $day = Carbon::parse($date);
            $inScope = Employee::query()->activeOn($day)->pluck('id')->all();

            $this->assertSame($currentActive, $current->isActiveOn($day), "current on {$date}");
            $this->assertSame($formerActive, $former->isActiveOn($day), "former on {$date}");
            $this->assertSame($currentActive, in_array($current->id, $inScope, true), "scope, current on {$date}");
            $this->assertSame($formerActive, in_array($former->id, $inScope, true), "scope, former on {$date}");
        }
    }

    public function test_active_between_is_active_on_some_date_in_the_range(): void
    {
        $former = Employee::factory()->inactive('2026-04-05')->create(['join_date' => '2026-03-10']);

        $between = fn (string $from, string $to) => Employee::query()->activeBetween(Carbon::parse($from), Carbon::parse($to))->pluck('id')->contains($former->id);

        $this->assertTrue($between('2026-04-05', '2026-04-10'));
        $this->assertTrue($between('2026-03-01', '2026-03-10'));
        $this->assertFalse($between('2026-04-06', '2026-04-10'));
        $this->assertFalse($between('2026-03-01', '2026-03-09'));
    }

    // ── Backfill ───────────────────────────────────────────────────────

    public function test_the_backfill_gives_inactive_employees_their_updated_at_date_and_leaves_active_ones_null(): void
    {
        $inactive = Employee::factory()->create(['join_date' => '2025-01-01']);
        $clamped = Employee::factory()->create(['join_date' => '2026-03-20']);
        $active = Employee::factory()->create();

        // Rows as they were before left_on existed — the query builder, past the model's invariants.
        DB::table('employees')->where('id', $inactive->id)->update(['status' => 'inactive', 'left_on' => null, 'updated_at' => '2026-03-05 18:30:00']);
        DB::table('employees')->where('id', $clamped->id)->update(['status' => 'inactive', 'left_on' => null, 'updated_at' => '2026-03-01 09:00:00']);

        $this->assertSame(2, EmployeeLeftOnBackfill::run());

        $this->assertSame('2026-03-05', $inactive->fresh()->left_on->format('Y-m-d'));
        // updated_at before join_date (shouldn't happen) is clamped to join_date.
        $this->assertSame('2026-03-20', $clamped->fresh()->left_on->format('Y-m-d'));
        $this->assertNull($active->fresh()->left_on);
    }

    // ── Deactivate / reactivate ────────────────────────────────────────

    public function test_deactivate_defaults_the_last_day_to_today_and_validates_it(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2026-03-01']);

        $component = Livewire::actingAs($this->admin())
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->assertSet('left_on', '2026-04-15')
            ->assertSee('Last day');

        $component->set('left_on', '2026-02-28')->call('confirm')->assertHasErrors(['left_on' => 'after_or_equal']);
        $component->set('left_on', '2026-04-16')->call('confirm')->assertHasErrors(['left_on' => 'before_or_equal']);
        $component->set('left_on', '')->call('confirm')->assertHasErrors(['left_on' => 'required']);
        $this->assertSame('active', $employee->fresh()->status);

        $component->set('left_on', '2026-03-01')->call('confirm')->assertHasNoErrors()->assertSet('showModal', false)->assertDispatched('employee-saved');

        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertSame('2026-03-01', $employee->fresh()->left_on->format('Y-m-d'));
    }

    public function test_a_backdated_deactivation_removes_rows_after_left_on_and_reactivation_restores_them(): void
    {
        $employee = Employee::factory()->create();
        $this->artisan('attendance:build-daily', ['--from' => '2026-04-08', '--to' => '2026-04-15'])->assertSuccessful();
        $this->assertCount(8, $this->builtDates($employee));

        Livewire::actingAs($this->admin())
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->set('left_on', '2026-04-10')
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame(['2026-04-08', '2026-04-09', '2026-04-10'], $this->builtDates($employee));

        Livewire::actingAs($this->admin())
            ->test(StatusModal::class)
            ->call('openReactivate', $employee->id)
            ->assertSee('Fri 10 Apr')
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertCount(8, $this->builtDates($employee));
        // The gap days are ordinary workdays again — punchless, so absent.
        $this->assertSame(AttendanceStatus::Absent, DailyAttendance::where('employee_id', $employee->id)->whereDate('work_date', '2026-04-13')->first()->status);
    }

    /**
     * Deactivated mid-shift with left_on = today: the in-only row is still
     * open, and the scheduled builds must still close it — the employee is
     * active on that date, so they're still selected.
     */
    public function test_a_mid_day_deactivation_with_left_on_today_still_gets_today_finalised(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::create(['employee_id' => $employee->id, 'punched_at' => '2026-04-15 08:00:00', 'punch_type' => 'in', 'source' => 'device']);
        app(DailySummaryBuilder::class)->build($employee, Carbon::parse('2026-04-15'));

        Livewire::actingAs($this->admin())
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame(AttendanceStatus::InProgress, DailyAttendance::where('employee_id', $employee->id)->first()->status);

        // Past the 18h pairing window: the default run (yesterday and today) closes it.
        $this->travelTo(Carbon::parse('2026-04-16 03:00:00'));
        $this->artisan('attendance:build-daily')->assertSuccessful();

        $this->assertSame(['2026-04-15'], $this->builtDates($employee));
        $this->assertSame(AttendanceStatus::Incomplete, DailyAttendance::where('employee_id', $employee->id)->first()->status);
    }

    public function test_a_failed_rebuild_keeps_the_status_change_and_shows_the_heal_command(): void
    {
        $employee = Employee::factory()->create(['employee_code' => 'EMP-0042']);
        $this->mock(DailySummaryBuilder::class, fn ($mock) => $mock->shouldReceive('rebuildFrom')->andThrow(new RuntimeException('connection lost')));
        Log::spy();

        Livewire::actingAs($this->admin())
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->set('left_on', '2026-04-10')
            ->call('confirm')
            ->assertSet('showModal', true)
            ->assertSee('php artisan attendance:build-daily --from=2026-04-11 --to=2026-04-15 --employee=EMP-0042');

        $this->assertSame('inactive', $employee->fresh()->status);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'Deactivation of EMP-0042: rebuild failed partway (connection lost)'))->once();
    }

    public function test_the_profile_offers_deactivate_or_reactivate_to_an_admin_only(): void
    {
        $active = Employee::factory()->create();
        $inactive = Employee::factory()->inactive('2026-04-01')->create();

        Livewire::actingAs($this->admin())->test(Show::class, ['employee' => $active])
            ->assertSeeHtml("\$dispatch('deactivate-employee', { id: {$active->id} })")
            ->assertDontSeeHtml("\$dispatch('reactivate-employee'");
        Livewire::actingAs($this->admin())->test(Show::class, ['employee' => $inactive])
            ->assertSeeHtml("\$dispatch('reactivate-employee', { id: {$inactive->id} })")
            ->assertDontSeeHtml("\$dispatch('deactivate-employee'");

        $managerEmployee = Employee::factory()->create();
        $manager = User::factory()->create()->assignRole('manager');
        $managerEmployee->update(['user_id' => $manager->id]);
        $active->update(['manager_id' => $managerEmployee->id]);

        Livewire::actingAs($manager)->test(Show::class, ['employee' => $active->fresh()])
            ->assertDontSeeHtml("\$dispatch('deactivate-employee'");
    }

    // ── Dashboard trend ─────────────────────────────────────────────────

    private function row(Employee $employee, string $date, AttendanceStatus $status): void
    {
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'work_schedule_id' => $this->schedule->id,
            'status' => $status,
            'first_in' => $status === AttendanceStatus::Present ? "{$date} 08:00:00" : null,
            'last_out' => $status === AttendanceStatus::Present ? "{$date} 17:00:00" : null,
        ]);
    }

    /**
     * Each trend day is measured against the employees active that day: a
     * deactivated employee counts up to their left_on (numerator and
     * denominator) and not after — even if a stale row is still there.
     */
    public function test_the_trend_counts_a_deactivated_employee_up_to_left_on_and_not_after(): void
    {
        $stays = Employee::factory()->create();
        $leaves = Employee::factory()->inactive('2026-04-13')->create();

        foreach (['2026-04-09', '2026-04-10'] as $date) {
            $this->row($stays, $date, AttendanceStatus::Present);
            $this->row($leaves, $date, AttendanceStatus::Present);
        }
        foreach (['2026-04-11', '2026-04-12'] as $date) {
            $this->row($stays, $date, AttendanceStatus::Off);
            $this->row($leaves, $date, AttendanceStatus::Off);
        }
        $this->row($stays, '2026-04-13', AttendanceStatus::Absent);
        $this->row($leaves, '2026-04-13', AttendanceStatus::Present);
        $this->row($stays, '2026-04-14', AttendanceStatus::Present);
        $this->row($leaves, '2026-04-14', AttendanceStatus::Present); // stale: after left_on
        $this->row($stays, '2026-04-15', AttendanceStatus::Present);

        $trend = collect(DashboardAttendance::weeklyTrend(null))->keyBy('date');

        $this->assertSame(100.0, $trend['2026-04-10']['value']);
        $this->assertSame(50.0, $trend['2026-04-13']['value']);
        $this->assertSame(100.0, $trend['2026-04-14']['value']);
        // Today is closed: the one employee active today has a final row.
        $this->assertFalse(DashboardAttendance::todayIsPending(null));
        $this->assertSame(100.0, $trend['2026-04-15']['value']);
    }

    public function test_today_is_pending_while_someone_who_left_today_has_no_row(): void
    {
        $stays = Employee::factory()->create();
        $leftToday = Employee::factory()->inactive('2026-04-15')->create();
        $this->row($stays, '2026-04-15', AttendanceStatus::Present);

        $this->assertTrue(DashboardAttendance::todayIsPending(null));

        $this->row($leftToday, '2026-04-15', AttendanceStatus::Present);

        $this->assertFalse(DashboardAttendance::todayIsPending(null));
    }
}
