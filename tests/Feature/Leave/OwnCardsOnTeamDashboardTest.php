<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F11 — a manager, and an admin linked to an employee record, are
 * employees too: their team dashboard carries their own leave cards (recent
 * decisions, leave balance, upcoming), after the team's cards — in the side
 * column from xl, last below it. An admin with no record has none. What the
 * cards cost is ApprovalsQueryCostTest's. The clock is Wed 17 Jun 2026.
 */
class OwnCardsOnTeamDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'seniority_bonus' => false, 'min_service_months' => null]);
    }

    private function person(string $name, string $role, ?Employee $manager = null): Employee
    {
        $user = User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user->id, 'manager_id' => $manager?->id, 'join_date' => '2020-01-01']);
    }

    private function request(Employee $employee, string $from, string $to): Leave
    {
        return app(LeaveRequestService::class)->submit($employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, null, $employee->user)['leave'];
    }

    public function test_a_manager_sees_their_own_cards_after_the_team_view(): void
    {
        $senior = $this->person('Senior Manager', 'manager');
        $m1 = $this->person('Aye Aye Mon', 'manager', $senior);
        $this->person('Kyaw Kyaw', 'employee', $m1);

        // One request decided (by their own manager) since they last opened
        // Time off, one still waiting.
        $m1->user->forceFill(['time_off_seen_at' => now()])->save();
        $decided = $this->request($m1, '2026-06-22', '2026-06-22');
        $this->travelTo(now()->addHour());
        app(LeaveRequestService::class)->reject($decided, $senior->user, 'Stock count that day');
        $this->request($m1, '2026-06-29', '2026-06-30');

        $this->actingAs($m1->user)->get(route('dashboard'))
            ->assertOk()
            // The team view first, then their own: the DOM order is the order at xl,
            // and the order-* classes put them last below it.
            ->assertSeeInOrder(['Needs attention', 'Recent activity', 'Decided since your last visit', 'Leave balance', 'Upcoming'])
            ->assertSee('Stock count that day')
            ->assertSeeInOrder(['Annual', '16', 'available'])
            ->assertSee('1 waiting')
            ->assertSeeHtml('order-5')
            ->assertSeeHtml('order-6')
            ->assertSeeHtml('order-7');
    }

    public function test_a_linked_admin_sees_their_own_cards(): void
    {
        $a1 = $this->person('Sophea Chan', 'admin');

        $this->actingAs($a1->user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Leave balance')
            ->assertSee('Upcoming');
    }

    public function test_an_admin_with_no_employee_record_sees_no_own_cards(): void
    {
        $this->person('Kyaw Kyaw', 'employee');

        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertDontSee('Leave balance')
            ->assertDontSee('Decided since your last visit')
            ->assertViewHas('mine', null);
    }
}
