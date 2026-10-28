<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3c — LeavePolicy, one assertion set per ability and role, and the
 * list scope (Leave::scopeVisibleTo(), through EmployeeScope).
 */
class LeavePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $manager;

    private Employee $report;

    private Employee $outsider;

    private User $orphanManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);

        $this->admin = User::factory()->create()->assignRole('admin');
        $this->manager = $this->person('manager');
        $this->report = $this->person('employee', $this->manager);
        $this->outsider = $this->person('employee');
        // A manager-role user with no employee record.
        $this->orphanManager = User::factory()->create()->assignRole('manager');
    }

    private function person(string $role, ?Employee $manager = null): Employee
    {
        $user = User::factory()->create()->assignRole($role);

        return Employee::factory()->create(['user_id' => $user->id, 'manager_id' => $manager?->id]);
    }

    private function leave(Employee $employee, string $state = 'pending', string $start = '2026-06-22'): Leave
    {
        return Leave::factory()->for($employee)->between($start, $start)->{$state}()->create();
    }

    public function test_view_any(): void
    {
        $this->assertTrue($this->admin->can('viewAny', Leave::class));
        $this->assertTrue($this->manager->user->can('viewAny', Leave::class));
        $this->assertTrue($this->report->user->can('viewAny', Leave::class));
        $this->assertFalse(User::factory()->create()->can('viewAny', Leave::class));
    }

    public function test_view_mirrors_employee_view(): void
    {
        $leave = $this->leave($this->report);

        $this->assertTrue($this->admin->can('view', $leave));
        $this->assertTrue($this->manager->user->can('view', $leave));
        $this->assertTrue($this->report->user->can('view', $leave));
        $this->assertFalse($this->outsider->user->can('view', $leave));
        $this->assertFalse($this->orphanManager->can('view', $leave));
    }

    public function test_create_is_self_or_an_admin_for_anyone(): void
    {
        $this->assertTrue($this->report->user->can('create', [Leave::class, $this->report]));
        $this->assertFalse($this->report->user->can('create', [Leave::class, $this->outsider]));
        $this->assertFalse($this->manager->user->can('create', [Leave::class, $this->report]));
        $this->assertTrue($this->admin->can('create', [Leave::class, $this->report]));
    }

    public function test_approve_follows_the_step(): void
    {
        $stepOne = $this->leave($this->report);

        $this->assertTrue($this->manager->user->can('approve', $stepOne));
        $this->assertTrue($this->admin->can('approve', $stepOne));
        $this->assertFalse($this->report->user->can('approve', $stepOne));
        $this->assertFalse($this->outsider->user->can('approve', $stepOne));
        $this->assertFalse($this->orphanManager->can('approve', $stepOne));

        $stepTwo = Leave::factory()->for($this->report)->pending(2)->create();
        $this->assertFalse($this->manager->user->can('approve', $stepTwo));
        $this->assertTrue($this->admin->can('approve', $stepTwo));

        $this->assertFalse($this->admin->can('approve', $this->leave($this->report, 'approved')));
    }

    public function test_cancel_by_the_requester_before_the_start_and_by_an_admin_any_time(): void
    {
        $future = $this->leave($this->report, 'approved', '2026-06-16');
        $today = $this->leave($this->report, 'approved', '2026-06-15');

        $this->assertTrue($this->report->user->can('cancel', $future));
        $this->assertFalse($this->report->user->can('cancel', $today));
        $this->assertTrue($this->admin->can('cancel', $today));
        $this->assertFalse($this->manager->user->can('cancel', $future));
        $this->assertFalse($this->admin->can('cancel', $this->leave($this->report, 'rejected')));
        $this->assertFalse($this->admin->can('cancel', $this->leave($this->report, 'cancelled')));
    }

    public function test_lists_are_scoped_through_employee_scope(): void
    {
        $mine = $this->leave($this->manager);
        $reports = $this->leave($this->report);
        $outsiders = $this->leave($this->outsider);

        $ids = fn (User $user) => Leave::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$mine->id, $reports->id, $outsiders->id], $ids($this->admin));
        $this->assertSame([$mine->id, $reports->id], $ids($this->manager->user));
        $this->assertSame([$reports->id], $ids($this->report->user));
        $this->assertSame([], $ids($this->orphanManager));
    }
}
