<?php

namespace Tests\Feature;

use App\Livewire\Employees\ScheduleAssignments;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeScheduleAssignmentsComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_admin_can_assign_a_backdated_schedule(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-02-10 12:00:00'));
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $morning = WorkSchedule::factory()->create(['name' => 'Morning shift']);

        Livewire::actingAs($this->admin())
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('create')
            ->set('work_schedule_id', (string) $morning->id)
            ->set('effective_from', '2026-02-06')
            ->call('assign')
            ->assertHasNoErrors();

        // The initial mount+render cycle already resolved (and cached) this
        // $employee's schedule once, before the reassignment — refresh to
        // drop that cache rather than read it stale.
        $this->assertSame($morning->id, $employee->refresh()->scheduleOn(today())->id);
        $this->assertSame(5, DailyAttendance::where('employee_id', $employee->id)->count());
    }

    public function test_assigning_before_the_join_date_is_rejected(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2026-02-01']);
        $schedule = WorkSchedule::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('create')
            ->set('work_schedule_id', (string) $schedule->id)
            ->set('effective_from', '2026-01-15')
            ->call('assign')
            ->assertHasErrors(['effective_from']);
    }

    public function test_admin_can_delete_an_assignment_row_when_more_than_one_exists(): void
    {
        // A recent join date, not EmployeeFactory's fixed 2020-01-01 default
        // — deleting the earliest row below rebuilds from its effective_from
        // (the join date) through today, so a years-old default here would
        // make this test itself rebuild years of history for no reason.
        $this->travelTo(\Carbon\Carbon::parse('2026-02-10 12:00:00'));
        $employee = Employee::factory()->create(['join_date' => '2026-02-01']);
        $schedule = WorkSchedule::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('create')
            ->set('work_schedule_id', (string) $schedule->id)
            ->set('effective_from', today()->format('Y-m-d'))
            ->call('assign');

        $original = EmployeeWorkSchedule::where('employee_id', $employee->id)->orderBy('effective_from')->first();

        Livewire::actingAs($this->admin())
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('deleteAssignment', $original->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('employee_work_schedules', ['id' => $original->id]);
    }

    public function test_admin_cannot_delete_the_only_assignment_row(): void
    {
        $employee = Employee::factory()->create();
        $only = EmployeeWorkSchedule::where('employee_id', $employee->id)->sole();

        Livewire::actingAs($this->admin())
            ->test(ScheduleAssignments::class, ['employee' => $employee])
            ->call('deleteAssignment', $only->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('employee_work_schedules', ['id' => $only->id]);
    }

    public function test_manager_viewing_their_reports_profile_cannot_assign_or_delete(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);
        $report = Employee::factory()->create(['manager_id' => $managerEmployee->id]);
        $schedule = WorkSchedule::factory()->create();

        Livewire::actingAs($manager)
            ->test(ScheduleAssignments::class, ['employee' => $report])
            ->call('create')
            ->assertForbidden();
    }

    public function test_a_manager_cannot_view_a_peers_schedule_assignments(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);
        $peer = Employee::factory()->create();

        Livewire::actingAs($manager)
            ->test(ScheduleAssignments::class, ['employee' => $peer])
            ->assertForbidden();
    }
}
