<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6h — role checks cost the same few queries on every page, however
 * many approvers there are: the signed-in user's roles once, and the step-1
 * candidates with their roles in one go. spatie's User::role() looked each
 * role up by name first, and every hasRole() on a candidate loaded that
 * user's roles again.
 */
class RoleLookupQueryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{byName: int, roleLoads: int} */
    private function roleQueries(User $user, string $path): array
    {
        $byName = 0;
        $roleLoads = 0;
        DB::listen(function ($query) use (&$byName, &$roleLoads) {
            $byName += (int) str_contains($query->sql, 'from `roles` where `name`');
            $roleLoads += (int) str_contains($query->sql, 'select `roles`.*');
        });

        $this->actingAs($user)->get($path)->assertOk();

        return ['byName' => $byName, 'roleLoads' => $roleLoads];
    }

    public function test_an_admin_page_looks_roles_up_a_fixed_number_of_times(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->seed([RoleSeeder::class, LeaveTypeSeeder::class]);
        WorkSchedule::factory()->create(['is_default' => true]);

        $admin = User::factory()->create()->assignRole('admin');
        $counts = [];

        foreach ([2, 8] as $managers) {
            // Managers, each with a report whose request waits on them, and
            // each away today — so the inbox asks every approver's role.
            for ($i = 0; $i < $managers - ($counts === [] ? 0 : 2); $i++) {
                $manager = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('manager')->id]);
                $report = Employee::factory()->create(['manager_id' => $manager->id]);
                Leave::factory()->pending()->between('2026-10-22', '2026-10-22')->create(['employee_id' => $report->id]);
                Leave::factory()->approved()->between('2026-10-21', '2026-10-30')->create(['employee_id' => $manager->id]);
            }

            $counts[$managers] = $this->roleQueries($admin->fresh(), '/approvals');
        }

        $this->assertSame(0, $counts[2]['byName'], 'No role is looked up by name.');
        $this->assertSame($counts[2], $counts[8], 'Role queries grow with approvers.');
        $this->assertLessThanOrEqual(2, $counts[8]['roleLoads']);
    }
}
