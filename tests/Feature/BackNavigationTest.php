<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Index as AttendanceIndex;
use App\Livewire\Attendance\Show as AttendanceShow;
use App\Livewire\Employees\Index as EmployeesIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6i: a detail page's way back is its breadcrumb's parent crumb, which
 * returns to the list as the user left it; there are no in-page back links.
 */
class BackNavigationTest extends TestCase
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

    public function test_my_attendance_stays_my_attendance_after_a_round_trip(): void
    {
        $this->travelTo('2026-04-15 12:00:00');

        $user = User::factory()->create()->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id]);

        // The bug: render() asked request()->routeIs('attendance.mine'), which is
        // false on every Livewire update, so opening a day (or a month change)
        // grew a "Back to attendance" link on the employee's own page.
        $component = Livewire::actingAs($user)->withoutLazyLoading()->test(AttendanceShow::class)
            ->assertSet('selfView', true)
            ->call('openDay', '2026-04-14')
            ->assertSet('dayModalOpen', true)
            ->assertSet('selfView', true)
            ->assertDontSee('Back to attendance')
            ->assertDontSee(route('attendance.index'))
            ->call('previousMonth')
            ->assertSet('selfView', true)
            ->assertDontSee(route('attendance.index'));

        // Locked: a request can't flip it.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('selfView', false);
    }

    public function test_my_attendance_has_a_plain_title_and_no_back_link(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['user_id' => $user->id]);

        $topbar = $this->topbar($this->actingAs($user)->get(route('attendance.mine'))->assertOk()->getContent());

        $this->assertStringContainsString('My attendance', $topbar);
        $this->assertStringNotContainsString('data-back-link', $topbar);
        $this->assertStringNotContainsString('href="'.route('attendance.index'), $topbar);
    }

    public function test_detail_pages_have_no_in_page_back_links(): void
    {
        foreach (['attendance/show', 'employees/show'] as $view) {
            $this->assertStringNotContainsString('Back to', File::get(resource_path("views/livewire/{$view}.blade.php")), $view);
        }

        $employee = Employee::factory()->create();

        foreach ([route('attendance.show', $employee), route('employees.show', $employee)] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('Back to');
        }
    }

    public function test_the_attendance_crumb_returns_to_the_filtered_list(): void
    {
        $this->travelTo('2026-04-15 12:00:00');

        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id, 'full_name' => 'Kyaw Kyaw Naing']);

        // Unvisited: the plain list.
        $this->actingAs($this->admin)->get(route('attendance.show', $employee))
            ->assertSee('href="'.route('attendance.index').'"', false);

        Livewire::actingAs($this->admin)->test(AttendanceIndex::class)
            ->set('departmentFilter', (string) $department->id)
            ->set('fromDate', '2026-04-01')
            ->set('toDate', '2026-04-10')
            ->call('toggleTimingFilter', 'late');

        $expected = route('attendance.index', ['from' => '2026-04-01', 'to' => '2026-04-10', 'department' => $department->id, 'timing' => ['late']]);

        $response = $this->actingAs($this->admin)->get(route('attendance.show', $employee))->assertOk();
        // The crumb at every width, and the phone's "‹ Attendance".
        $this->assertSame(2, substr_count($response->getContent(), 'href="'.e($expected).'"'));
        $response->assertSee('data-back-link', false);

        // The URL it returns to opens the list as it was left.
        Livewire::withQueryParams(['from' => '2026-04-01', 'to' => '2026-04-10', 'department' => (string) $department->id, 'timing' => ['late']])
            ->actingAs($this->admin)->test(AttendanceIndex::class)
            ->assertSet('departmentFilter', (string) $department->id)
            ->assertSet('fromDate', '2026-04-01')
            ->assertSet('timingFilters', ['late']);

        // Back to defaults: today's range and default statuses leave no parameters.
        Livewire::actingAs($this->admin)->test(AttendanceIndex::class)->call('resetFilters');
        $this->actingAs($this->admin)->get(route('attendance.show', $employee))
            ->assertSee('href="'.route('attendance.index').'"', false);
    }

    public function test_the_employees_crumb_returns_to_the_filtered_page(): void
    {
        Employee::factory()->count(30)->create(['status' => 'active']);
        $employee = Employee::first();

        Livewire::actingAs($this->admin)->test(EmployeesIndex::class)
            ->set('statusFilter', 'active')
            ->set('search', 'a')
            ->call('gotoPage', 2);

        $this->actingAs($this->admin)->get(route('employees.show', $employee))
            ->assertSee('href="'.e(route('employees.index', ['q' => 'a', 'status' => 'active', 'page' => 2])).'"', false);
    }

    public function test_the_lists_are_remembered_separately(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin)->test(EmployeesIndex::class)->set('departmentFilter', (string) $department->id);

        $this->actingAs($this->admin)->get(route('attendance.show', $employee))
            ->assertSee('href="'.route('attendance.index').'"', false);
    }

    public function test_no_link_to_a_list_the_user_may_not_open(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Kyaw Kyaw Naing']);

        Gate::before(fn (User $user, string $ability) => $ability === 'viewAny' ? false : null);

        foreach ([route('attendance.show', $employee) => 'attendance.index', route('employees.show', $employee) => 'employees.index'] as $url => $list) {
            // The route layer still lets an admin in; only the list's own ability is withdrawn.
            $topbar = $this->topbar($this->actingAs($this->admin)->get($url)->assertOk()->getContent());

            $this->assertStringNotContainsString('href="'.route($list), $topbar);
            $this->assertStringNotContainsString('data-back-link', $topbar);
            $this->assertStringContainsString('Kyaw Kyaw Naing', $topbar);
        }
    }

    private function topbar(string $html): string
    {
        $start = strpos($html, '<header class="sticky top-0');

        return substr($html, $start, strpos($html, '</header>', $start) - $start);
    }
}
