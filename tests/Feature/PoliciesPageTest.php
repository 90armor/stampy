<?php

namespace Tests\Feature;

use App\Livewire\Departments\Index as DepartmentsIndex;
use App\Livewire\Holidays\Index as HolidaysIndex;
use App\Livewire\LeaveTypes\Index as LeaveTypesIndex;
use App\Livewire\OvertimeSettings\Edit as OvertimeSettingsEdit;
use App\Livewire\Positions\Index as PositionsIndex;
use App\Livewire\Schedules\Index as SchedulesIndex;
use App\Models\Holiday;
use App\Models\User;
use App\Policies\PoliciesPolicy;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Schedules and Holidays moved from Organization to Policies (owner
 * decision, after Phase 4): Organization holds the structure, Policies the
 * rules attendance, leave and overtime are counted by. The old tab links
 * redirect; who may open either page didn't change.
 */
class PoliciesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_policies_holds_working_time_first_then_leave_and_overtime(): void
    {
        $this->actingAs($this->admin)->get(route('policies.index'))
            ->assertOk()
            ->assertSee('Working hours, holidays and the rules leave and overtime are counted by.')
            ->assertSeeInOrder(['aria-label="Policy sections"', '>Schedules<', '>Holidays<', '>Leave types<', '>Overtime<'], false)
            ->assertSee("x-data=\"{ tab: 'schedules' }\"", false)
            ->assertSeeLivewire(SchedulesIndex::class)
            ->assertSeeLivewire(HolidaysIndex::class)
            ->assertSeeLivewire(LeaveTypesIndex::class)
            ->assertSeeLivewire(OvertimeSettingsEdit::class);
    }

    public function test_organization_holds_departments_and_positions_only(): void
    {
        $this->actingAs($this->admin)->get(route('organization.index'))
            ->assertOk()
            ->assertSee('Manage the workforce structure: departments and positions.')
            ->assertSeeInOrder(['aria-label="Organization sections"', '>Departments<', '>Positions<'], false)
            ->assertSeeLivewire(DepartmentsIndex::class)
            ->assertSeeLivewire(PositionsIndex::class)
            ->assertDontSeeLivewire(SchedulesIndex::class)
            ->assertDontSeeLivewire(HolidaysIndex::class);
    }

    public function test_the_old_organization_tab_links_redirect_to_the_same_tab_on_policies(): void
    {
        $this->actingAs($this->admin)->get('/organization?tab=schedules')
            ->assertRedirect(route('policies.index', ['tab' => 'schedules']));

        $this->actingAs($this->admin)->get('/organization?tab=holidays')
            ->assertRedirect(route('policies.index', ['tab' => 'holidays']));
    }

    public function test_any_other_organization_tab_still_opens_organization(): void
    {
        foreach (['departments', 'positions', 'leave-types', 'nonsense'] as $tab) {
            $this->actingAs($this->admin)->get("/organization?tab={$tab}")
                ->assertOk()
                ->assertSeeLivewire(DepartmentsIndex::class);
        }
    }

    public function test_anyone_but_an_admin_is_still_forbidden_on_both_pages_and_the_old_links(): void
    {
        foreach (['manager', 'employee'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            $this->actingAs($user)->get(route('policies.index'))->assertForbidden();
            $this->actingAs($user)->get(route('policies.index', ['tab' => 'schedules']))->assertForbidden();
            $this->actingAs($user)->get(route('organization.index'))->assertForbidden();
            // Forbidden, not redirected to a page that would forbid them anyway.
            $this->actingAs($user)->get('/organization?tab=schedules')->assertForbidden();
            $this->actingAs($user)->get('/organization?tab=holidays')->assertForbidden();
        }
    }

    public function test_the_page_opens_on_the_requested_tab_and_falls_back_to_the_first(): void
    {
        $this->actingAs($this->admin)->get(route('policies.index', ['tab' => 'holidays']))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'holidays' }\"", false);

        $this->actingAs($this->admin)->get(route('policies.index', ['tab' => 'nonsense']))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'schedules' }\"", false);
    }

    public function test_the_page_and_its_sidebar_item_follow_each_tabs_own_ability(): void
    {
        // Phase 5a: Policies has its own ability, any tab the user may see by
        // the ability that tab's component checks. Grant a manager Holidays
        // only (not the first tab): the item shows, the page opens there,
        // and the other tabs aren't rendered at all.
        $manager = User::factory()->create()->assignRole('manager');
        Gate::before(fn (User $user, string $ability, array $arguments) => $user->is($manager) && $ability === 'viewAny' && $arguments === [Holiday::class] ? true : null);

        $this->assertTrue($manager->can('policies.view'));
        $this->assertSame(['holidays' => 'Holidays'], app(PoliciesPolicy::class)->tabs($manager));

        $this->actingAs($manager)->get(route('dashboard'))->assertSee(route('policies.index'), false);

        foreach ([[], ['tab' => 'schedules'], ['tab' => 'holidays']] as $query) {
            $this->actingAs($manager)->get(route('policies.index', $query))
                ->assertOk()
                ->assertSee("x-data=\"{ tab: 'holidays' }\"", false)
                ->assertSee('>Holidays</button>', false)
                ->assertDontSee('>Schedules</button>', false)
                ->assertDontSee('>Leave types</button>', false)
                ->assertDontSee('>Overtime</button>', false)
                ->assertSeeLivewire(HolidaysIndex::class)
                ->assertDontSeeLivewire(SchedulesIndex::class)
                ->assertDontSeeLivewire(LeaveTypesIndex::class)
                ->assertDontSeeLivewire(OvertimeSettingsEdit::class);
        }

        // An employee with no tab still has no item and no page.
        $employee = User::factory()->create()->assignRole('employee');
        $this->assertFalse($employee->can('policies.view'));
        $this->actingAs($employee)->get(route('dashboard'))->assertDontSee(route('policies.index'), false);
        $this->actingAs($employee)->get(route('policies.index'))->assertForbidden();
    }
}
