<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Show;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceShowTest extends TestCase
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

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * A real request starts with an empty per-process cache; within one test
     * process it would otherwise still hold the previous "request's" answer.
     */
    private function forgetCachedSubordinates(): void
    {
        (new ReflectionProperty(Employee::class, 'subordinateIdsCache'))->setValue(null, []);
    }

    public function test_render_re_asserts_access_even_if_the_employee_property_is_set_directly(): void
    {
        $top = Employee::factory()->create();
        $peer = Employee::factory()->create();

        $this->actingAs($this->managerUser($top));

        $component = new Show;
        $component->employee = $peer;

        $this->expectException(AuthorizationException::class);

        $component->render();
    }

    public function test_a_page_opened_while_authorized_stops_serving_data_once_access_is_revoked(): void
    {
        $top = Employee::factory()->create();
        $report = Employee::factory()->create(['manager_id' => $top->id, 'full_name' => 'Revoked Report']);

        $component = Livewire::actingAs($this->managerUser($top))
            ->test(Show::class, ['employee' => $report])
            ->assertSee('Revoked Report');

        // The report is moved out of this manager's team while their page is still open.
        $report->update(['manager_id' => null]);
        $this->forgetCachedSubordinates();

        $component->call('previousMonth')->assertForbidden();
    }

    public function test_admin_can_view_any_employees_detail_page(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Detail Page Target']);

        $this->actingAs($this->admin())
            ->get(route('attendance.show', $employee))
            ->assertOk()
            ->assertSee('Detail Page Target');
    }

    public function test_a_manager_can_view_a_report_of_a_report(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id, 'full_name' => 'Leaf Report Target']);

        $manager = $this->managerUser($top);

        $this->actingAs($manager)
            ->get(route('attendance.show', $leaf))
            ->assertOk()
            ->assertSee('Leaf Report Target');
    }

    public function test_a_manager_cannot_view_a_peer(): void
    {
        $topA = Employee::factory()->create();
        $topB = Employee::factory()->create();

        $manager = $this->managerUser($topA);

        $this->actingAs($manager)
            ->get(route('attendance.show', $topB))
            ->assertForbidden();
    }

    public function test_a_manager_cannot_view_someone_in_another_branch(): void
    {
        $topA = Employee::factory()->create();
        $midA = Employee::factory()->create(['manager_id' => $topA->id]);

        $topB = Employee::factory()->create();
        $otherBranch = Employee::factory()->create(['manager_id' => $topB->id]);

        $manager = $this->managerUser($midA);

        $this->actingAs($manager)
            ->get(route('attendance.show', $otherBranch))
            ->assertForbidden();
    }

    public function test_an_employee_can_view_their_own_detail_page_but_not_a_colleagues(): void
    {
        $own = Employee::factory()->create();
        $other = Employee::factory()->create();

        $user = User::factory()->create()->assignRole('employee');
        $own->update(['user_id' => $user->id]);

        // /attendance/{employee} is gated by role:admin|manager route
        // middleware, so a plain employee reaches their own record via
        // /my-attendance instead — see the next test.
        $this->actingAs($user)
            ->get(route('attendance.show', $other))
            ->assertForbidden();
    }

    public function test_my_attendance_resolves_to_the_authenticated_users_own_employee(): void
    {
        $user = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id, 'full_name' => 'Self Viewer']);

        $this->actingAs($user)
            ->get(route('attendance.mine'))
            ->assertOk()
            ->assertSee('Self Viewer');
    }

    public function test_my_attendance_shows_a_clear_message_when_theres_no_linked_employee(): void
    {
        $user = User::factory()->create()->assignRole('employee');

        $this->actingAs($user)
            ->get(route('attendance.mine'))
            ->assertOk()
            ->assertSee("isn't linked to an employee record");
    }

    public function test_a_day_with_no_daily_attendance_row_renders_as_not_calculated(): void
    {
        $employee = Employee::factory()->create();
        $month = today()->startOfMonth();

        // Only the first day of the month has been calculated; every other
        // day in the month must show as "Not calculated", not "Absent" —
        // asserted via the summary counts (workdays/absent stay at what the
        // one real row contributes) rather than a page-wide assertDontSee,
        // since "Absent" is also a summary-bar category label that's always
        // printed (with a 0 count) regardless of whether any day has it.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $month->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', $month->format('Y-m'))
            ->assertSee('Not calculated');

        $summary = $component->instance()->render()->getData()['summary'];

        $this->assertSame(1, $summary['workdays']);
        $this->assertSame(1, $summary['present']);
        $this->assertSame(0, $summary['absent']);
    }

    public function test_month_summary_counts_match_the_calculated_rows(): void
    {
        $employee = Employee::factory()->create();
        $month = today()->startOfMonth();

        // A late arrival is Present with late_minutes set, not a separate
        // status (see AttendanceStatus's doc comment) — 'late' below is
        // counted from that field, not from status.
        $rows = [
            ['status' => AttendanceStatus::Present, 'late_minutes' => 0],
            ['status' => AttendanceStatus::Present, 'late_minutes' => 0],
            ['status' => AttendanceStatus::Present, 'late_minutes' => 15],
            ['status' => AttendanceStatus::Absent, 'late_minutes' => 0],
        ];

        foreach ($rows as $i => $row) {
            DailyAttendance::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $month->copy()->addDays($i)->format('Y-m-d'),
                'status' => $row['status'],
                'late_minutes' => $row['late_minutes'],
            ]);
        }

        $summary = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', $month->format('Y-m'))
            ->instance()
            ->render()
            ->getData()['summary'];

        $this->assertSame(3, $summary['present']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(0, $summary['incomplete']);
        $this->assertSame(4, $summary['workdays']);
    }
}
