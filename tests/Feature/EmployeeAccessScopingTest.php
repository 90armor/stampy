<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeScope;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeAccessScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_subordinate_ids_resolve_transitively(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id]);
        $unrelated = Employee::factory()->create();

        $ids = $top->subordinateIds();

        $this->assertContains($mid->id, $ids);
        $this->assertContains($leaf->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertNotContains($top->id, $ids);
    }

    public function test_is_manager_of_checks_the_transitive_set(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id]);
        $peer = Employee::factory()->create();

        $this->assertTrue($top->isManagerOf($leaf));
        $this->assertTrue($top->isManagerOf($mid));
        $this->assertFalse($top->isManagerOf($peer));
        $this->assertFalse($mid->isManagerOf($top));
    }

    /**
     * manager_id has no DB constraint preventing a cycle — a single
     * mis-typed form entry could create one. subordinateIds() must
     * terminate on its own (via its visited-id set) rather than looping.
     */
    public function test_a_cyclic_manager_chain_terminates_without_error(): void
    {
        $a = Employee::factory()->create();
        $b = Employee::factory()->create(['manager_id' => $a->id]);
        $c = Employee::factory()->create(['manager_id' => $b->id]);

        // Close the loop: a now reports to c, which reports to b, which
        // reports to a.
        $a->update(['manager_id' => $c->id]);

        $ids = $a->subordinateIds();

        $this->assertIsArray($ids);
        $this->assertContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
        // The cycle must not re-include a itself.
        $this->assertNotContains($a->id, $ids);
    }

    /**
     * @return array{0: Employee, 1: list<int>} the top of the chain, then each report's id, shallowest first
     */
    private function reportingChain(int $levelsBelowTop): array
    {
        $top = Employee::factory()->create();
        $previous = $top;
        $ids = [];

        for ($level = 1; $level <= $levelsBelowTop; $level++) {
            $previous = Employee::factory()->create(['manager_id' => $previous->id]);
            $ids[] = $previous->id;
        }

        return [$top, $ids];
    }

    // 10 mirrors the depth cap inside Employee::resolveSubordinateIds().
    public function test_a_chain_exactly_at_the_depth_cap_resolves_fully_without_warning(): void
    {
        Log::spy();

        [$top, $ids] = $this->reportingChain(10);

        $this->assertEqualsCanonicalizing($ids, $top->subordinateIds());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_chain_beyond_the_depth_cap_is_truncated_and_warns(): void
    {
        Log::spy();

        [$top, $ids] = $this->reportingChain(11);

        $resolved = $top->subordinateIds();

        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 10), $resolved);
        $this->assertNotContains($ids[10], $resolved);
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * The list scope (EmployeeScope) and the policy answer the same question — "may this
     * user see this employee?" — for a list and for one record. This keeps them from drifting.
     */
    public function test_the_list_scope_and_the_view_policy_agree_for_every_kind_of_user(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id]);
        $peer = Employee::factory()->create();
        $otherBranch = Employee::factory()->create(['manager_id' => $peer->id]);
        $pool = [$top, $mid, $leaf, $peer, $otherBranch];

        $roleless = User::factory()->create();
        $leaf->update(['user_id' => $roleless->id]);

        $employeeRole = User::factory()->create()->assignRole('employee');
        $peer->update(['user_id' => $employeeRole->id]);

        $users = [
            'admin' => User::factory()->create()->assignRole('admin'),
            'manager with reports' => $this->managerUser($top),
            'manager with no employee record' => User::factory()->create()->assignRole('manager'),
            'employee-role user with their own record' => $employeeRole,
            'user with no role but an employee record' => $roleless,
            'user with no role and no record' => User::factory()->create(),
        ];

        foreach ($users as $label => $user) {
            $ids = EmployeeScope::for($user, 'test')->ids;

            foreach ($pool as $employee) {
                $inScope = $ids === null || in_array($employee->id, $ids, true);

                $this->assertSame($user->can('view', $employee), $inScope, "{$label} / employee {$employee->id}");
            }
        }
    }

    public function test_policy_allows_admin_to_view_any_employee(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();

        $this->assertTrue($admin->can('view', $employee));
    }

    public function test_policy_allows_viewing_own_record_regardless_of_role(): void
    {
        $employeeUser = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

        $this->assertTrue($employeeUser->can('view', $employee));
    }

    public function test_policy_allows_a_manager_to_view_a_transitive_subordinate(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id]);

        $manager = $this->managerUser($top);

        $this->assertTrue($manager->can('view', $leaf));
    }

    public function test_policy_denies_a_manager_viewing_a_peer(): void
    {
        $topA = Employee::factory()->create();
        $topB = Employee::factory()->create();
        $peer = Employee::factory()->create(['manager_id' => $topB->id]);

        $manager = $this->managerUser($topA);

        $this->assertFalse($manager->can('view', $peer));
    }

    public function test_policy_denies_a_manager_viewing_someone_in_another_branch(): void
    {
        $topA = Employee::factory()->create();
        $midA = Employee::factory()->create(['manager_id' => $topA->id]);

        $topB = Employee::factory()->create();
        $otherBranch = Employee::factory()->create(['manager_id' => $topB->id]);

        $manager = $this->managerUser($midA);

        $this->assertFalse($manager->can('view', $otherBranch));
    }

    public function test_policy_denies_a_manager_role_user_with_no_linked_employee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create();

        $this->assertFalse($manager->can('view', $employee));
    }
}
