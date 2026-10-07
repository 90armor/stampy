<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — overtime on the dashboards: the employee's decisions since they
 * last opened Overtime, pending and approved planned overtime under Upcoming,
 * the month's credited overtime, and the approver card counting both types.
 * The clock is Mon 15 Jun 2026 12:00.
 */
class OvertimeDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);

        $managerUser = User::factory()->create(['name' => 'Aye Aye Mon'])->assignRole('manager');
        $this->manager = Employee::factory()->create(['full_name' => 'Aye Aye Mon', 'user_id' => $managerUser->id]);
        $this->employee = Employee::factory()->create(['full_name' => 'Kyaw Kyaw', 'user_id' => User::factory()->create()->assignRole('employee')->id, 'manager_id' => $this->manager->id]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function overtime(string $date, ?User $actor = null)
    {
        return app(OvertimeRequestService::class)->submit(
            $this->employee, Carbon::parse($date), Carbon::parse("{$date} 17:00"), Carbon::parse("{$date} 19:00"), OvertimeCompensation::Pay, null, $actor ?? $this->employee->user,
        )['request'];
    }

    public function test_upcoming_lists_pending_and_approved_planned_overtime(): void
    {
        $this->overtime('2026-06-17');
        $this->overtime('2026-06-19', $this->admin);

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertSeeTextInOrder(['Upcoming', 'Overtime · Wed 17 Jun, 5:00 PM – 7:00 PM', 'Waiting for manager', 'Overtime · Fri 19 Jun, 5:00 PM – 7:00 PM', 'Starts in 4 days'])
            ->assertSee('1 waiting')
            ->assertSee(route('overtime.index'));
    }

    public function test_overtime_decided_since_the_last_visit_to_overtime_is_listed(): void
    {
        $request = $this->overtime('2026-06-17');
        $this->employee->user->forceFill(['overtime_seen_at' => now()])->save();
        $this->travel(5)->minutes();
        app(OvertimeRequestService::class)->reject($request, $this->manager->user, 'Not this week');

        $this->actingAs($this->employee->user->fresh())->get(route('dashboard'))
            ->assertSee('Decided since your last visit')
            ->assertSeeText('Overtime · Wed 17 Jun, 5:00 PM – 7:00 PM')
            ->assertSeeText('“Not this week”')
            ->assertSee('See overtime');
    }

    public function test_this_month_shows_the_credited_overtime(): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-08 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-08 18:30:00', 'punch_type' => 'out']);
        $this->overtime('2026-06-08', $this->admin);

        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertSee('Overtime: 1h 30m credited');
    }

    public function test_the_approver_card_counts_requests_of_both_types(): void
    {
        $this->overtime('2026-06-17');
        app(LeaveRequestService::class)->submit($this->employee, LeaveType::first(), Carbon::parse('2026-06-24'), Carbon::parse('2026-06-24'), null, null, $this->employee->user);

        $this->actingAs($this->manager->user)->get(route('dashboard'))
            ->assertSee('Pending approvals')
            ->assertSeeText('2 requests waiting on you');
    }
}
