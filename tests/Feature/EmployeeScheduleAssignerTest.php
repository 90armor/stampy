<?php

namespace Tests\Feature;

use App\Exceptions\BulkReassignmentTooFarBackException;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EmployeeScheduleAssignerTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create(array_merge([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays' => [1, 2, 3, 4, 5],
        ], $overrides));
    }

    public function test_a_backdated_assignment_rebuilds_from_its_effective_date_through_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $default = $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $morning = $this->schedule(['name' => 'Morning']);

        $result = app(EmployeeScheduleAssigner::class)->assign($employee, $morning, Carbon::parse('2026-02-06'));

        $this->assertSame(5, $result['days']); // 02-06 through 02-10
        $this->assertNull($result['rebuildError']);
        $this->assertSame(
            ['2026-02-06', '2026-02-07', '2026-02-08', '2026-02-09', '2026-02-10'],
            DailyAttendance::where('employee_id', $employee->id)->orderBy('work_date')->pluck('work_date')->map(fn ($d) => $d->format('Y-m-d'))->all()
        );
        $this->assertSame($morning->id, $employee->scheduleOn(Carbon::parse('2026-02-06'))->id);
        $this->assertSame($default->id, $employee->scheduleOn(Carbon::parse('2026-02-05'))->id);
    }

    public function test_a_future_dated_assignment_rebuilds_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create();
        $future = $this->schedule(['name' => 'Future shift']);

        $result = app(EmployeeScheduleAssigner::class)->assign($employee, $future, Carbon::parse('2026-03-01'));

        $this->assertSame(0, $result['days']);
        $this->assertNull($result['rebuildError']);
        $this->assertSame(0, DailyAttendance::where('employee_id', $employee->id)->count());
        // Not in effect yet — today still resolves to whatever was assigned before.
        $this->assertNotSame($future->id, $employee->scheduleOn(today())->id);
    }

    public function test_a_rebuild_failure_for_a_single_assignment_is_reported_not_swallowed(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2020-01-01', 'employee_code' => 'EMP-9001']);
        $morning = $this->schedule(['name' => 'Morning']);

        Log::shouldReceive('error')->once()->with(\Mockery::pattern('/EMP-9001.*rebuild failed partway/'));

        $this->mock(DailySummaryBuilder::class, function ($mock) {
            $mock->shouldReceive('rebuildFrom')->once()->andThrow(new \RuntimeException('simulated failure'));
        });

        $result = app(EmployeeScheduleAssigner::class)->assign($employee, $morning, Carbon::parse('2026-02-06'));

        // The assignment write itself still happened — it's never rolled
        // back for a rebuild failure — only the rebuild step failed.
        $this->assertSame($morning->id, $employee->fresh()->scheduleOn(Carbon::parse('2026-02-06'))->id);
        $this->assertSame(0, $result['days']);
        $this->assertNotNull($result['rebuildError']);
        $this->assertStringContainsString('--from=2026-02-06 --to=2026-02-10 --employee=EMP-9001', $result['rebuildError']);
        $this->assertStringContainsString('php artisan attendance:build-daily', $result['rebuildError']);
    }

    public function test_reassigning_at_an_existing_effective_date_replaces_that_row_not_adds_one(): void
    {
        $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create();
        $a = $this->schedule(['name' => 'A']);
        $b = $this->schedule(['name' => 'B']);

        app(EmployeeScheduleAssigner::class)->assign($employee, $a, today());
        app(EmployeeScheduleAssigner::class)->assign($employee, $b, today());

        $this->assertSame(1, EmployeeWorkSchedule::where('employee_id', $employee->id)->where('effective_from', today()->format('Y-m-d'))->count());
        $this->assertSame($b->id, $employee->scheduleOn(today())->id);
    }

    public function test_bulk_reassignment_moves_exactly_the_right_employees(): void
    {
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);

        $onA1 = Employee::factory()->create(['full_name' => 'On A One']);
        $onA2 = Employee::factory()->create(['full_name' => 'On A Two']);
        $onB = Employee::factory()->create(['full_name' => 'On B']);
        app(EmployeeScheduleAssigner::class)->assign($onB, $b, today());
        $inactiveOnA = Employee::factory()->create(['status' => 'inactive', 'full_name' => 'Inactive On A']);

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, today());

        // Only the two genuinely on A — not $onB (already reassigned to B
        // before the bulk run) and not the inactive one (also on A, but
        // excluded from the active-only scope).
        $this->assertSame(2, $result['employees']);
        $this->assertNull($result['rebuildError']);
        $this->assertSame($b->id, $onA1->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onA2->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onB->fresh()->scheduleOn(today())->id);
        // Inactive — left alone even though it was on A.
        $this->assertSame($a->id, $inactiveOnA->fresh()->scheduleOn(today())->id);
    }

    public function test_bulk_reassignment_more_than_60_days_back_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);

        $this->expectException(BulkReassignmentTooFarBackException::class);

        app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, Carbon::parse('2026-02-10')->subDays(61));
    }

    public function test_bulk_reassignment_exactly_60_days_back_is_allowed(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);
        Employee::factory()->create(['join_date' => '2020-01-01']);

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, Carbon::parse('2026-02-10')->subDays(60));

        $this->assertSame(1, $result['employees']);
        $this->assertNull($result['rebuildError']);
    }

    public function test_a_rebuild_failure_mid_bulk_leaves_every_assignment_written_and_none_half_applied(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);

        $onA1 = Employee::factory()->create(['full_name' => 'On A One', 'employee_code' => 'EMP-A1']);
        $onA2 = Employee::factory()->create(['full_name' => 'On A Two', 'employee_code' => 'EMP-A2']);
        $onA3 = Employee::factory()->create(['full_name' => 'On A Three', 'employee_code' => 'EMP-A3']);

        Log::shouldReceive('error')->once()->with(\Mockery::pattern('/Bulk schedule reassignment.*rebuild failed partway, after all 3 assignment\(s\) were already written/'));

        // Whichever employee this hits, in whatever order the loop reaches
        // them, the point being tested doesn't depend on which one — only
        // that ALL THREE assignment rows below were already written before
        // any of this ran (see the transaction in bulkReassign()).
        $this->mock(DailySummaryBuilder::class, function ($mock) use ($onA2) {
            $mock->shouldReceive('rebuildFrom')
                ->with(\Mockery::on(fn ($employee) => $employee->id === $onA2->id), \Mockery::any())
                ->andThrow(new \RuntimeException('simulated failure'));
            $mock->shouldReceive('rebuildFrom')->andReturn(1);
        });

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, Carbon::parse('2026-02-01'));

        $this->assertSame($b->id, $onA1->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onA2->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onA3->fresh()->scheduleOn(today())->id);
        $this->assertSame(3, $result['employees']);
        $this->assertNotNull($result['rebuildError']);
    }

    public function test_the_bulk_failure_message_names_the_range_and_the_command(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);
        Employee::factory()->create();

        $this->mock(DailySummaryBuilder::class, function ($mock) {
            $mock->shouldReceive('rebuildFrom')->andThrow(new \RuntimeException('simulated failure'));
        });

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, Carbon::parse('2026-02-01'));

        $this->assertSame(
            'Not every affected day may have been rebuilt. Run: php artisan attendance:build-daily --from=2026-02-01 --to=2026-02-10',
            $result['rebuildError']
        );
    }

    public function test_re_running_the_stated_recovery_command_heals_every_affected_day(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);

        $onA1 = Employee::factory()->create(['join_date' => '2020-01-01']);
        $onA2 = Employee::factory()->create(['join_date' => '2020-01-01']);

        // Both rebuildFrom() calls are intercepted — one throws, one
        // "succeeds" but the mock never actually writes anything — so
        // nothing lands in daily_attendances for either employee yet.
        $this->mock(DailySummaryBuilder::class, function ($mock) use ($onA1) {
            $mock->shouldReceive('rebuildFrom')
                ->with(\Mockery::on(fn ($employee) => $employee->id === $onA1->id), \Mockery::any())
                ->andReturn(10);
            $mock->shouldReceive('rebuildFrom')->andThrow(new \RuntimeException('simulated failure'));
        });

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, Carbon::parse('2026-02-01'));

        $this->assertNotNull($result['rebuildError']);
        $this->assertSame(
            0,
            DailyAttendance::whereIn('employee_id', [$onA1->id, $onA2->id])
                ->whereBetween('work_date', ['2026-02-01', '2026-02-10'])
                ->count()
        );

        // Un-mock so the real builder resolves, then run exactly the
        // command the error message named.
        $this->app->forgetInstance(DailySummaryBuilder::class);

        $this->artisan('attendance:build-daily', ['--from' => '2026-02-01', '--to' => '2026-02-10'])->assertSuccessful();

        foreach ([$onA1, $onA2] as $employee) {
            $this->assertSame(
                10,
                DailyAttendance::where('employee_id', $employee->id)
                    ->whereBetween('work_date', ['2026-02-01', '2026-02-10'])
                    ->count(),
                "Employee {$employee->id} was not fully healed by the recovery command."
            );
        }
    }
}
