<?php

namespace App\Services;

use App\Models\Employee;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place an employee is deactivated or reactivated (Employees\
 * StatusModal is its only caller). Both change which dates the employee is
 * active on (Employee::scopeActiveOn()), so both are followed by a rebuild
 * from the day after left_on to today: deactivating removes the rows after
 * it (DailySummaryBuilder::build() keeps none outside the employment
 * period), reactivating builds them again.
 *
 * The same two steps, in the same order, as EmployeeScheduleAssigner: the
 * status write always lands, and a rebuild that fails partway is reported,
 * logged and named with the command that heals it — never rolled back into
 * the write. daily_attendances is derived, so a stale range is recoverable.
 *
 * Reactivating means "the deactivation was a mistake": the employment
 * period is one unbroken span again and the gap days become ordinary days
 * (absent if punchless). A rehire — a second employment period — isn't
 * modelled (CLAUDE.md, Employee lifecycle).
 */
class EmployeeLifecycle
{
    public function __construct(
        private DailySummaryBuilder $builder,
        private EmployeeScheduleAssigner $assigner,
    ) {}

    /**
     * @return array{days: int, rebuildError: ?string}
     */
    public function deactivate(Employee $employee, CarbonInterface $leftOn): array
    {
        $employee->update(['status' => 'inactive', 'left_on' => $leftOn->format('Y-m-d')]);

        return $this->rebuildAfter($employee, Carbon::instance($leftOn), 'Deactivation');
    }

    /**
     * @return array{days: int, rebuildError: ?string}
     */
    public function reactivate(Employee $employee): array
    {
        $leftOn = $employee->left_on?->copy();

        $employee->update(['status' => 'active', 'left_on' => null]);

        if ($leftOn === null) {
            return ['days' => 0, 'rebuildError' => null];
        }

        return $this->rebuildAfter($employee, $leftOn, 'Reactivation');
    }

    /**
     * @return array{days: int, rebuildError: ?string}
     */
    private function rebuildAfter(Employee $employee, Carbon $leftOn, string $action): array
    {
        $from = $leftOn->copy()->startOfDay()->addDay();

        try {
            return ['days' => $this->builder->rebuildFrom($employee, $from), 'rebuildError' => null];
        } catch (Throwable $e) {
            $message = $this->assigner->rebuildRecoveryMessage($from, "--employee={$employee->employee_code}");

            Log::error("{$action} of {$employee->employee_code}: rebuild failed partway ({$e->getMessage()}). {$message}");

            return ['days' => 0, 'rebuildError' => $message];
        }
    }
}
