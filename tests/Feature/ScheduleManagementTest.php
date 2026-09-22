<?php

namespace Tests\Feature;

use App\Livewire\Schedules\Index;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

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

    public function test_admin_can_view_the_schedules_list(): void
    {
        $this->schedule(['name' => 'Morning shift', 'is_default' => true]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->assertSee('Morning shift')
            ->assertSee('Default');
    }

    public function test_the_list_shows_how_many_employees_are_currently_assigned_to_each_schedule(): void
    {
        $default = $this->schedule(['name' => 'Default Shift', 'is_default' => true]);
        Employee::factory()->count(2)->create();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->assertSee('2 employees currently assigned');
    }

    public function test_admin_can_create_a_schedule(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Evening shift')
            ->set('start_time', '14:00')
            ->set('end_time', '22:00')
            ->set('grace_minutes', 5)
            ->set('break_minutes', 30)
            ->set('workdays', [1, 2, 3, 4, 5])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('work_schedules', [
            'name' => 'Evening shift',
            'start_time' => '14:00:00',
            'end_time' => '22:00:00',
        ]);
    }

    public function test_creating_a_schedule_requires_the_basics(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('create')
            ->set('name', '')
            ->set('workdays', [])
            ->call('save')
            ->assertHasErrors(['name', 'workdays']);
    }

    public function test_end_time_at_or_before_start_time_is_rejected_through_the_form(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Bad shift')
            ->set('start_time', '17:00')
            ->set('end_time', '08:00')
            ->set('workdays', [1])
            ->call('save')
            ->assertHasErrors(['form']);

        $this->assertDatabaseMissing('work_schedules', ['name' => 'Bad shift']);
    }

    public function test_admin_can_edit_an_unreferenced_schedules_hours(): void
    {
        $schedule = $this->schedule(['is_default' => true]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->set('start_time', '09:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('09:00:00', $schedule->fresh()->start_time);
    }

    public function test_a_locked_schedules_name_can_still_be_changed(): void
    {
        $schedule = $this->schedule(['is_default' => true, 'name' => 'Before']);
        Employee::factory()->create(); // assigns it, locking the hours

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->set('name', 'After')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After', $schedule->fresh()->name);
    }

    public function test_a_locked_schedules_hours_cannot_be_changed(): void
    {
        $schedule = $this->schedule(['is_default' => true, 'name' => 'Before']);
        Employee::factory()->create(); // assigns it, locking the hours

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->set('name', 'After') // submitted alongside the crafted hour change below
            ->set('start_time', '10:00')
            ->call('save')
            ->assertHasErrors(['form']);

        // The whole update() call fails together — the name change isn't
        // applied either, since it was part of the same rejected write.
        $this->assertSame('Before', $schedule->fresh()->name);
        $this->assertSame('08:00:00', $schedule->fresh()->start_time);
    }

    public function test_the_edit_modal_shows_the_locked_explanation_for_a_referenced_schedule(): void
    {
        $schedule = $this->schedule(['is_default' => true]);
        Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->assertSee("can't be changed", false);
    }

    public function test_admin_can_delete_an_unreferenced_non_default_schedule(): void
    {
        $this->schedule(['is_default' => true]);
        $unused = $this->schedule(['name' => 'Unused Shift']);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('delete', $unused->id);

        $this->assertDatabaseMissing('work_schedules', ['id' => $unused->id]);
    }

    public function test_admin_cannot_delete_a_referenced_schedule(): void
    {
        $schedule = $this->schedule(['is_default' => true]);
        Employee::factory()->create();
        $this->schedule(['is_default' => true]); // becomes default, freeing $schedule from that guard

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('delete', $schedule->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('work_schedules', ['id' => $schedule->id]);
    }

    public function test_admin_cannot_delete_the_default_schedule(): void
    {
        $schedule = $this->schedule(['is_default' => true]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('delete', $schedule->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('work_schedules', ['id' => $schedule->id]);
    }

    public function test_admin_can_make_a_different_schedule_the_default(): void
    {
        $old = $this->schedule(['is_default' => true]);
        $new = $this->schedule(['name' => 'New Default']);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('setDefault', $new->id);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($new->fresh()->is_default);
    }

    public function test_manager_cannot_reach_the_schedules_tab(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertForbidden();
    }

    private function pageOpenedByAnAdminThenDowngradedTo(string $role): Testable
    {
        $component = Livewire::actingAs($this->admin())->test(Index::class);

        $this->actingAs(User::factory()->create()->assignRole($role));

        return $component;
    }

    public function test_every_action_re_authorizes_when_driven_directly_by_a_non_admin(): void
    {
        $existing = $this->schedule(['is_default' => true]);

        foreach (['manager', 'employee'] as $role) {
            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('create')->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('edit', $existing->id)->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)
                ->set('name', 'Direct Save')
                ->call('save')
                ->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('delete', $existing->id)->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('setDefault', $existing->id)->assertForbidden();
        }

        $this->assertDatabaseMissing('work_schedules', ['name' => 'Direct Save']);
    }

    public function test_employee_role_cannot_reach_the_organization_route_at_all(): void
    {
        $employee = User::factory()->create()->assignRole('employee');

        $this->actingAs($employee)->get('/organization')->assertForbidden();
    }

    public function test_bulk_reassign_moves_everyone_on_one_schedule_to_another(): void
    {
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);
        $onA = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('openBulkReassign')
            ->set('bulk_from_id', (string) $a->id)
            ->set('bulk_to_id', (string) $b->id)
            ->set('bulk_effective_from', today()->format('Y-m-d'))
            ->call('bulkReassign')
            ->assertHasNoErrors()
            ->assertSet('bulkResult.employees', 1);

        $this->assertSame($b->id, $onA->fresh()->scheduleOn(today())->id);
    }

    public function test_bulk_reassign_rejects_the_same_schedule_on_both_sides(): void
    {
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('openBulkReassign')
            ->set('bulk_from_id', (string) $a->id)
            ->set('bulk_to_id', (string) $a->id)
            ->set('bulk_effective_from', today()->format('Y-m-d'))
            ->call('bulkReassign')
            ->assertHasErrors(['bulk_from_id']);
    }

    public function test_manager_cannot_bulk_reassign(): void
    {
        $this->schedule(['is_default' => true]);

        $this->pageOpenedByAnAdminThenDowngradedTo('manager')->call('bulkReassign')->assertForbidden();
    }
}
