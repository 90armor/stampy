<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3d (from 3c's report) — the row lock LeaveRequestService relies on
 * to serialize concurrent requests for one employee. The submit tests prove
 * the balance and overlap rules only sequentially; this catches a refactor
 * that drops the lockForUpdate() itself.
 */
class LeaveLockingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
    }

    /**
     * @return list<string> the SQL of every query $action ran
     */
    private function queriesOf(callable $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    private function locksEmployeeRow(array $queries): bool
    {
        return collect($queries)->contains(fn (string $sql) => str_contains($sql, 'from `employees`') && str_ends_with(trim($sql), 'for update'));
    }

    public function test_submit_and_approve_lock_the_employee_row(): void
    {
        $manager = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('manager')->id]);
        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id, 'manager_id' => $manager->id]);
        $service = app(LeaveRequestService::class);
        $leave = null;

        $submit = $this->queriesOf(function () use ($service, $employee, &$leave) {
            $leave = $service->submit($employee, LeaveType::first(), Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $employee->user)['leave'];
        });
        $this->assertTrue($this->locksEmployeeRow($submit), 'submit() must select the employee row FOR UPDATE.');

        $approve = $this->queriesOf(fn () => $service->approve($leave, $manager->user));
        $this->assertTrue($this->locksEmployeeRow($approve), 'approve() must select the employee row FOR UPDATE.');
    }
}
