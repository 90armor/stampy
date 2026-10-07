<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Exceptions\OvertimeSettingsLockedException;
use App\Livewire\Holidays\Index as HolidaysIndex;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Leave\LeaveBalance;
use App\Services\Overtime\TimeOffInLieuReconciler;
use App\Services\Overtime\ToilReconciliation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4b — time off in lieu follows the credited minutes (CLAUDE.md, Phase
 * 4, rule 14): TimeOffInLieuReconciler, when the builder runs it, what a
 * failure does, and the settings it locks. Amounts are tenths of a day.
 */
class TimeOffInLieuTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-02-02';

    private const SATURDAY = '2026-02-07';

    private LeaveType $toil;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        // 08:00–17:00 Mon–Fri, break 12:00–13:00.
        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $this->toil->id]);
    }

    /**
     * Approved overtime on $date, $from–$to, worked 08:00 (or $from, if
     * earlier) until $out, then the work date rebuilt — which reconciles.
     */
    private function overtime(Employee $employee, string $date, string $from, string $to, string $out, OvertimeCompensation $compensation = OvertimeCompensation::TimeOff): OvertimeRequest
    {
        $request = OvertimeRequest::factory()->for($employee)->window($date, $from, $to)->approved()->create(['compensation' => $compensation]);
        $in = min('08:00', $from);
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => "{$date} {$in}:00", 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => "{$date} {$out}", 'punch_type' => 'out']);

        app(DailySummaryBuilder::class)->rebuildOvertimeDate($request);

        return $request;
    }

    /** Posted system TOIL days, in tenths. */
    private function posted(Employee $employee): int
    {
        return (int) round(10 * (float) LeaveAdjustment::where('employee_id', $employee->id)->whereNull('created_by')->sum('days'));
    }

    // ── The target ──────────────────────────────────────────────────────

    public function test_every_full_four_hours_is_half_a_day(): void
    {
        $short = Employee::factory()->create();
        $this->overtime($short, self::MONDAY, '17:00', '21:00', '20:59:00'); // 3h59m
        $this->assertSame(0, $this->posted($short));
        $this->assertSame(0, LeaveAdjustment::count());

        $four = Employee::factory()->create();
        $request = $this->overtime($four, self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->assertSame(5, $this->posted($four));

        $adjustment = LeaveAdjustment::sole();
        $this->assertSame(
            [$this->toil->id, 2026, '0.5', $request->id, null, 'Time off in lieu: overtime on Mon 2 Feb'],
            [$adjustment->leave_type_id, $adjustment->year, $adjustment->days, $adjustment->overtime_request_id, $adjustment->created_by, $adjustment->note],
        );

        // 7h on a Saturday (08:00–16:00 less the break), then 1h: one day in all.
        $sum = Employee::factory()->create();
        $this->overtime($sum, self::SATURDAY, '08:00', '16:00', '16:00:00');
        $this->assertSame(5, $this->posted($sum));
        $this->overtime($sum, '2026-02-09', '17:00', '18:00', '18:00:00');
        $this->assertSame(10, $this->posted($sum));
        $this->assertSame(2, LeaveAdjustment::where('employee_id', $sum->id)->count());

        // The balance is built from these adjustments.
        $this->assertSame(10, app(LeaveBalance::class)->for($sum, $this->toil, 2026)->available());
    }

    public function test_the_ratio_applies_to_the_minutes_first_and_the_remainder_carries(): void
    {
        OvertimeSettings::current()->update(['toil_ratio_percent' => 150]);
        $employee = Employee::factory()->create();

        // 4h at 150% is 6h: one block, 2h left over.
        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->assertSame(5, $this->posted($employee));

        // 1h20m is 2h more: 8h, two blocks.
        $this->overtime($employee, '2026-02-03', '17:00', '18:20', '18:20:00');
        $this->assertSame(10, $this->posted($employee));
    }

    public function test_the_remainder_carries_across_a_year_boundary(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);

        $this->overtime($employee, '2025-12-30', '17:00', '20:00', '20:00:00'); // 3h
        $this->assertSame(0, LeaveAdjustment::count());

        $january = $this->overtime($employee, '2026-01-02', '17:00', '18:00', '18:00:00'); // +1h

        $adjustment = LeaveAdjustment::sole();
        $this->assertSame([2026, '0.5', $january->id], [$adjustment->year, $adjustment->days, $adjustment->overtime_request_id]);
    }

    public function test_paid_overtime_earns_no_time_off(): void
    {
        $employee = Employee::factory()->create();
        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00', OvertimeCompensation::Pay);

        $this->assertSame(0, LeaveAdjustment::count());
        $this->assertSame('settled', app(TimeOffInLieuReconciler::class)->reconcile($employee)->outcome);
    }

    public function test_a_punch_correction_that_lowers_the_minutes_posts_a_negative_difference(): void
    {
        $employee = Employee::factory()->create();
        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->assertSame(5, $this->posted($employee));

        // The out-punch was someone else's: voided, and the day rebuilt as a punch change does.
        AttendanceLog::where('punch_type', 'out')->update(['voided_at' => now()]);
        app(DailySummaryBuilder::class)->rebuildAround($employee->fresh(), Carbon::parse(self::MONDAY));

        $this->assertSame(0, $this->posted($employee));
        $this->assertSame(['0.5', '-0.5'], LeaveAdjustment::orderBy('id')->pluck('days')->all());
    }

    public function test_a_backdated_deactivation_takes_back_overtime_after_the_last_day(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');

        $employee->update(['status' => 'inactive', 'left_on' => '2026-01-30']);
        app(DailySummaryBuilder::class)->rebuildFrom($employee->fresh(), Carbon::parse('2026-01-31'));

        $this->assertSame(0, $this->posted($employee));
    }

    public function test_reconciling_twice_posts_nothing_the_second_time(): void
    {
        $employee = Employee::factory()->create();
        $request = $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');

        $reconciler = app(TimeOffInLieuReconciler::class);
        $this->assertSame('settled', $reconciler->reconcile($employee, $request)->outcome);
        $this->assertSame('settled', $reconciler->reconcile($employee)->outcome);
        app(DailySummaryBuilder::class)->rebuildOvertimeDate($request);

        $this->assertSame(1, LeaveAdjustment::count());
    }

    // ── Where it runs ───────────────────────────────────────────────────

    public function test_a_range_build_reconciles_once_at_the_end_and_only_when_time_off_minutes_changed(): void
    {
        $employee = Employee::factory()->create();
        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->overtime($employee, '2026-02-03', '17:00', '21:00', '21:00:00');

        $calls = new \ArrayObject;
        $this->app->instance(TimeOffInLieuReconciler::class, new class($calls) extends TimeOffInLieuReconciler
        {
            public function __construct(private \ArrayObject $calls) {}

            public function reconcile(Employee $employee, ?OvertimeRequest $trigger = null): ToilReconciliation
            {
                $this->calls[] = $trigger?->id;

                return parent::reconcile($employee, $trigger);
            }
        });
        $builder = app(DailySummaryBuilder::class);

        // Nothing changed: no reconciliation at all.
        $builder->rebuildBetween($employee->fresh(), Carbon::parse('2026-02-01'), Carbon::parse('2026-02-08'));
        $this->assertCount(0, $calls);

        // Both days' minutes change: one reconciliation, after the range.
        AttendanceLog::where('punch_type', 'out')->update(['voided_at' => now()]);
        $builder->rebuildBetween($employee->fresh(), Carbon::parse('2026-02-01'), Carbon::parse('2026-02-08'));
        $this->assertCount(1, $calls);
        $this->assertSame(0, $this->posted($employee));
    }

    public function test_a_holiday_added_through_the_holidays_page_reconciles(): void
    {
        $employee = Employee::factory()->create();
        // 08:00–19:00 approved: on a workday only 17:00–19:00 is overtime (2h, nothing posted)…
        $this->overtime($employee, self::MONDAY, '08:00', '19:00', '19:00:00');
        $this->assertSame(0, $this->posted($employee));

        // …on a holiday all of it less the break is: 10h, two blocks.
        Role::firstOrCreate(['name' => 'admin']);
        Livewire::actingAs(User::factory()->create()->assignRole('admin'))->test(HolidaysIndex::class)
            ->call('create')->set('date', self::MONDAY)->set('name', 'Test holiday')->call('save')->assertHasNoErrors();

        $this->assertSame(10, $this->posted($employee));
    }

    public function test_a_failed_reconciliation_never_fails_the_build_and_the_command_heals_it(): void
    {
        Log::spy();
        $employee = Employee::factory()->create(['employee_code' => 'EMP-0042']);
        $this->app->instance(TimeOffInLieuReconciler::class, new class extends TimeOffInLieuReconciler
        {
            public function reconcile(Employee $employee, ?OvertimeRequest $trigger = null): never
            {
                throw new RuntimeException('database went away');
            }
        });

        $this->overtime($employee, self::MONDAY, '17:00', '21:00', '21:00:00');

        // The build stands; nothing was posted; the log says how to heal it.
        $this->assertSame(240, $employee->dailyAttendances()->sole()->overtime_workday_minutes);
        $this->assertSame(0, LeaveAdjustment::count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, 'EMP-0042')
            && str_contains($message, 'database went away')
            && str_contains($message, 'php artisan attendance:build-daily --date=2026-04-15 --employee=EMP-0042'));

        // The next scheduled run — today only — reconciles everyone it builds who has time-off overtime.
        $this->app->forgetInstance(TimeOffInLieuReconciler::class);
        $this->artisan('attendance:build-daily', ['--date' => '2026-04-15'])->assertSuccessful();

        $this->assertSame(5, $this->posted($employee));
    }

    public function test_with_no_toil_type_nothing_is_credited_and_one_line_is_logged_per_run(): void
    {
        OvertimeSettings::current()->update(['toil_leave_type_id' => null]);
        $first = Employee::factory()->create(['employee_code' => 'EMP-0001']);
        $second = Employee::factory()->create(['employee_code' => 'EMP-0002']);
        $this->overtime($first, self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->overtime($second, self::MONDAY, '17:00', '21:00', '21:00:00');

        Log::spy(); // only the command's run below (the rebuilds above logged their own lines)
        $this->artisan('attendance:build-daily', ['--date' => '2026-04-15'])
            ->expectsOutputToContain('2 employee(s) have time-off overtime but no TOIL leave type is set')
            ->assertSuccessful();

        $this->assertSame(0, LeaveAdjustment::count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '2 employee(s)')
            && str_contains($message, 'overtime_settings.toil_leave_type_id') && str_contains($message, 'EMP-0001, EMP-0002'));
    }

    // ── The settings locks ──────────────────────────────────────────────

    public function test_the_toil_settings_lock_once_time_off_is_credited_and_the_rates_stay_editable(): void
    {
        // Nothing credited yet: all editable.
        OvertimeSettings::current()->update(['toil_ratio_percent' => 125, 'toil_block_minutes' => 210]);
        OvertimeSettings::current()->update(['toil_ratio_percent' => 100, 'toil_block_minutes' => 240]);

        $this->overtime(Employee::factory()->create(), self::MONDAY, '17:00', '21:00', '21:00:00');
        $this->assertTrue(OvertimeSettings::toilCredited());

        $other = LeaveType::factory()->earned()->create(['name' => 'Comp time']);

        foreach ([['toil_ratio_percent' => 150], ['toil_block_minutes' => 180], ['toil_leave_type_id' => $other->id]] as $change) {
            try {
                OvertimeSettings::current()->update($change);
                $this->fail('Changed after crediting: '.json_encode($change));
            } catch (OvertimeSettingsLockedException $e) {
                $this->assertStringContainsString('re-valued', $e->getMessage());
            }
        }

        OvertimeSettings::current()->update(['workday_rate_percent' => 175, 'night_rate_percent' => 200, 'night_starts' => '21:00:00', 'claim_window_days' => 14]);

        $settings = OvertimeSettings::current();
        $this->assertSame([175, '21:00:00', 14, 100], [$settings->workday_rate_percent, $settings->night_starts, $settings->claim_window_days, $settings->toil_ratio_percent]);
    }
}
