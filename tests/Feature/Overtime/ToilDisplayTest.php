<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Livewire\Employees\LeaveCard;
use App\Livewire\Leave\TimeOff;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — where Time off in lieu shows: only for someone who has earned
 * some (an adjustment of the type, ever), and then with the time saved toward
 * the next half day (TimeOffInLieuReconciler::remainderMinutes()).
 */
class ToilDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $toil->id]);

        $this->employee = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id]);
    }

    /** 4h 30m of approved time-off overtime on Mon 6 Apr, 17:00–21:30. */
    private function earnFourAndAHalfHours(): void
    {
        $request = OvertimeRequest::factory()->for($this->employee)->window('2026-04-06', '17:00', '21:30')->timeOff()->approved()->create();
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-04-06 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-04-06 21:30:00', 'punch_type' => 'out']);
        app(DailySummaryBuilder::class)->rebuildOvertimeDate($request);
    }

    public function test_the_remainder_is_the_time_below_a_full_block(): void
    {
        $reconciler = app(TimeOffInLieuReconciler::class);
        $this->assertSame(0, $reconciler->remainderMinutes($this->employee));

        // No TOIL type, no remainder (set back before anything is credited — it locks then).
        $type = OvertimeSettings::current()->toil_leave_type_id;
        OvertimeSettings::current()->update(['toil_leave_type_id' => null]);
        $this->assertNull($reconciler->remainderMinutes($this->employee));
        OvertimeSettings::current()->update(['toil_leave_type_id' => $type]);

        $this->earnFourAndAHalfHours();

        $this->assertSame(30, $reconciler->remainderMinutes($this->employee));
    }

    public function test_time_off_in_lieu_shows_only_once_earned_with_its_remainder(): void
    {
        $user = $this->employee->user;

        Livewire::actingAs($user)->test(TimeOff::class)->assertSee('Annual')->assertDontSee('Time off in lieu');
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('Time off in lieu');

        $this->earnFourAndAHalfHours();

        Livewire::actingAs($user)->test(TimeOff::class)
            ->assertSee('Time off in lieu')
            ->assertSee('30m toward the next half day');
        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('Time off in lieu')
            ->assertSee('30m toward the next half day');
    }

    public function test_the_profile_card_hides_an_unearned_toil_balance_but_still_offers_it_for_adjustments(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->assertDontSee('toward the next half day')
            ->assertViewHas('balanceRows', fn (array $rows) => collect($rows)->doesntContain(fn ($row) => $row['type']->name === 'Time off in lieu'))
            ->assertViewHas('balanceTypes', fn ($types) => $types->contains('name', 'Time off in lieu'));

        $this->earnFourAndAHalfHours();

        Livewire::actingAs($admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->assertSee('30m toward the next half day');
    }

    /** What overtime earned is the entitlement, not an adjustment: that column is an admin's corrections. */
    public function test_posted_time_off_in_lieu_is_entitled_and_adjustments_are_only_an_admins(): void
    {
        $this->earnFourAndAHalfHours();
        $toil = LeaveType::where('name', 'Time off in lieu')->sole();
        LeaveAdjustment::factory()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $toil->id, 'year' => 2026, 'days' => '1.0', 'created_by' => User::factory()->create()->id]);

        $balance = app(LeaveBalance::class)->for($this->employee, $toil, 2026);

        $this->assertSame([5, 10, 15], [$balance->entitled, $balance->adjustments, $balance->available()]);
        Livewire::actingAs($this->employee->user)->test(TimeOff::class)->assertSee('earned from overtime');
    }

    public function test_paid_overtime_earns_no_toil_row(): void
    {
        $request = OvertimeRequest::factory()->for($this->employee)->window('2026-04-06', '17:00', '21:00')->pay()->approved()->create(['compensation' => OvertimeCompensation::Pay]);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-04-06 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-04-06 21:00:00', 'punch_type' => 'out']);
        app(DailySummaryBuilder::class)->rebuildOvertimeDate($request);

        Livewire::actingAs($this->employee->user)->test(TimeOff::class)->assertDontSee('Time off in lieu');
    }
}
