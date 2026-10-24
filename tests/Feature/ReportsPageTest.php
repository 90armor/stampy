<?php

namespace Tests\Feature;

use App\Livewire\Reports\Attendance;
use App\Livewire\Reports\Overtime;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b — one Reports page with a tab per report: Attendance for admins
 * and for managers with an employee record (their team), Overtime for admins.
 * Its own ability (reports.view) gates the route and the sidebar item, the
 * page renders only the tabs the user may see, and each tab's component
 * checks its own ability again.
 */
class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->manager = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['user_id' => $this->manager->id]);
    }

    public function test_an_admin_sees_every_tab_and_opens_on_attendance(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Report sections"', '>Attendance<', '>Overtime<'], false)
            ->assertSee("x-data=\"{ tab: 'attendance' }\"", false)
            ->assertSeeLivewire(Attendance::class)
            ->assertSeeLivewire(Overtime::class);

        $this->assertTrue($this->admin->can('reports.view'));
    }

    public function test_a_manager_with_an_employee_record_sees_attendance_only(): void
    {
        $this->actingAs($this->manager)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('>Attendance<', false)
            ->assertDontSee('>Overtime<', false)
            ->assertSeeLivewire(Attendance::class)
            ->assertDontSeeLivewire(Overtime::class);

        $this->assertTrue($this->manager->can('reports.attendance'));
        $this->assertTrue($this->manager->can('reports.leave'));
        $this->assertFalse($this->manager->can('reports.overtime'));
    }

    public function test_a_forbidden_or_unknown_tab_falls_back_to_the_first_one_the_user_may_see(): void
    {
        $this->actingAs($this->manager)->get(route('reports.index', ['tab' => 'overtime']))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'attendance' }\"", false)
            ->assertDontSeeLivewire(Overtime::class);

        $this->actingAs($this->admin)->get(route('reports.index', ['tab' => 'nonsense']))
            ->assertSee("x-data=\"{ tab: 'attendance' }\"", false);

        $this->actingAs($this->admin)->get(route('reports.index', ['tab' => 'overtime']))
            ->assertSee("x-data=\"{ tab: 'overtime' }\"", false);
    }

    public function test_a_manager_without_an_employee_record_and_an_employee_have_no_reports(): void
    {
        $unlinkedManager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create()->assignRole('employee');
        Employee::factory()->create(['user_id' => $employee->id]);

        foreach ([$unlinkedManager, $employee] as $user) {
            $this->assertFalse($user->can('reports.view'));
            $this->assertFalse($user->can('reports.attendance'));
            $this->assertFalse($user->can('reports.leave'));
            $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
            $this->actingAs($user)->get(route('reports.index', ['tab' => 'attendance']))->assertForbidden();
            $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('reports.index'), false);

            Livewire::actingAs($user)->test(Attendance::class)->assertForbidden();
            Livewire::actingAs($user)->test(Overtime::class)->assertForbidden();
        }
    }

    public function test_the_sidebar_links_to_reports_for_whoever_may_see_a_tab(): void
    {
        // No "Soon" any more: a manager reaches their team's attendance report.
        foreach ([$this->admin, $this->manager] as $user) {
            $this->actingAs($user)->get(route('dashboard'))
                ->assertSee(route('reports.index'), false)
                ->assertDontSee('aria-disabled="true"', false);
        }
    }

    public function test_the_old_overtime_report_link_redirects_to_its_tab_keeping_the_month(): void
    {
        $this->actingAs($this->admin)->get('/reports/overtime?month=2026-05')
            ->assertStatus(302)
            ->assertRedirect(route('reports.index', ['tab' => 'overtime', 'month' => '2026-05']));

        $this->actingAs($this->admin)->get('/reports/overtime')
            ->assertRedirect(route('reports.index', ['tab' => 'overtime']));

        // A manager following one lands on the tab they may see.
        $this->actingAs($this->manager)->followingRedirects()->get('/reports/overtime')
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'attendance' }\"", false);
    }

    public function test_every_tab_checks_its_own_ability_on_export_too(): void
    {
        // A component the manager may mount refuses an action it can't take
        // (Overtime refuses the mount itself, so its download is never reachable).
        Livewire::actingAs($this->manager)->test(Overtime::class)->assertForbidden();
        Livewire::actingAs($this->admin)->test(Overtime::class)->call('download')->assertOk();
    }

    public function test_the_reports_keep_each_others_month_in_step(): void
    {
        Livewire::actingAs($this->admin)->test(Overtime::class)
            ->set('month', '2026-05')
            ->assertDispatched('report-month', month: '2026-05');

        Livewire::actingAs($this->admin)->test(Overtime::class)
            ->dispatch('report-month', month: '2026-04')
            ->assertSet('month', '2026-04');
    }
}
