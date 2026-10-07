<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveCounting;
use App\Enums\LeaveStatus;
use App\Livewire\LeaveTypes\Index;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — Policies → Leave types. The rules are LeaveType's own; these
 * tests check the page shows them: locked fields read-only with the reason,
 * Delete only while nothing refers to the type, Deactivate otherwise with
 * the pending count. The clock is Mon 15 Jun 2026.
 */
class LeaveTypesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id]);
    }

    public function test_policies_is_admin_only_and_the_sidebar_follows_its_ability(): void
    {
        $this->actingAs($this->admin)->get(route('policies.index'))->assertOk()->assertSee('Leave types');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('policies.index'));

        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager)->get(route('policies.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('dashboard'))->assertDontSee(route('policies.index'));
    }

    public function test_an_admin_adds_a_type_and_the_models_rules_show_on_the_form(): void
    {
        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('create')
            ->set('name', 'Study')
            ->set('days_per_year', '5')
            ->set('carry_over_cap', '2.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Added Study.')
            ->assertSee('5 days a year · carry over up to 2.5 days');

        $study = LeaveType::where('name', 'Study')->sole();
        $this->assertSame(['5.0', '2.5', true], [$study->days_per_year, $study->carry_over_cap, $study->is_active]);

        // Carry-over needs a balance: LeaveType's own rule, on the form.
        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('create')
            ->set('name', 'Odd')
            ->set('carry_over_cap', '3')
            ->call('save')
            ->assertHasErrors('form')
            ->assertSee('Carry-over and the seniority bonus need a yearly balance (days per year).');

        // Names are unique; amounts are whole or tenths of a day.
        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('create')
            ->set('name', 'Annual')
            ->set('days_per_year', '18.25')
            ->call('save')
            ->assertHasErrors(['name' => 'unique', 'days_per_year']);
    }

    public function test_fields_a_leave_depends_on_lock_once_one_is_taken_and_say_why(): void
    {
        $page = Livewire::actingAs($this->admin)->test(Index::class)->call('edit', $this->annual->id)
            ->assertDontSee('Locked: leave has been taken with this type')
            ->assertSee('A change applies from the next grant; grants already made keep their days.');

        $employee = $this->employee();
        app(LeaveRequestService::class)->submit($employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $employee->user);

        $html = Livewire::actingAs($this->admin)->test(Index::class)->call('edit', $this->annual->id)
            ->assertSee('Locked: leave has been taken with this type')
            ->html();
        $this->assertMatchesRegularExpression('/<select\s+disabled[^>]*id="leave_type_counts"|<select[^>]*disabled[^>]*id="leave_type_counts"|id="leave_type_counts"[^>]*disabled/s', $html);

        // Other fields still save; a locked one is refused by the model, on the form.
        Livewire::actingAs($this->admin)->test(Index::class)->call('edit', $this->annual->id)
            ->set('days_per_year', '20')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('20.0', $this->annual->fresh()->days_per_year);

        Livewire::actingAs($this->admin)->test(Index::class)->call('edit', $this->annual->id)
            ->set('counts', LeaveCounting::CalendarDays->value)
            ->call('save')
            ->assertHasErrors('form');
        $this->assertSame(LeaveCounting::Workdays, $this->annual->fresh()->counts);
        $this->assertNotNull($page);
    }

    public function test_raising_the_service_requirement_says_what_it_affects_and_revokes_nothing(): void
    {
        $employee = $this->employee();
        $service = app(LeaveRequestService::class);
        $leave = $service->submit($employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->admin)['leave'];
        $grant = $employee->leaveEntitlements()->where('leave_type_id', $this->annual->id)->sole();

        Livewire::actingAs($this->admin)->test(Index::class)->call('edit', $this->annual->id)
            ->assertSee('A change affects grants not yet made; grants already made and approved leave are untouched.')
            ->set('min_service_months', '120')
            ->call('save')
            ->assertHasNoErrors();

        // Ten years now required, but the grant already made and the approved leave stand.
        $this->assertSame(120, $this->annual->fresh()->min_service_months);
        $this->assertSame($grant->days, $grant->fresh()->days);
        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
    }

    public function test_delete_only_while_nothing_refers_to_the_type_otherwise_deactivate_with_the_pending_count(): void
    {
        $unused = LeaveType::factory()->withoutBalance()->create(['name' => 'Unused']);
        $employee = $this->employee();
        $service = app(LeaveRequestService::class);
        $service->submit($employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $employee->user);
        $pending = $service->submit($employee, $this->annual, Carbon::parse('2026-06-23'), Carbon::parse('2026-06-23'), null, null, $employee->user)['leave'];

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSeeHtml('aria-label="Delete Unused"')
            ->assertDontSeeHtml('aria-label="Delete Annual"')
            ->assertSeeHtml('aria-label="Deactivate Annual"')
            ->assertDontSeeHtml('aria-label="Deactivate Unused"')
            ->assertSee('2 pending requests stay decidable.')
            ->call('delete', $unused->id)
            ->assertSee('Deleted Unused.');
        $this->assertNull($unused->fresh());

        // Deleting a referenced type is refused with the model's message.
        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $this->annual->id)->assertHasErrors('delete');

        // Deactivated: no new requests, but the pending ones stay decidable.
        Livewire::actingAs($this->admin)->test(Index::class)->call('setActive', $this->annual->id, false)
            ->assertSee('Deactivated Annual.')
            ->assertSee('Inactive')
            ->assertSeeHtml('aria-label="Reactivate Annual"');
        $service->approve($pending->fresh(), $this->admin);
        $this->assertSame(LeaveStatus::Approved, $pending->fresh()->status);
    }

    public function test_only_admins_can_change_types(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        Livewire::actingAs($manager)->test(Index::class)->assertForbidden();
    }
}
