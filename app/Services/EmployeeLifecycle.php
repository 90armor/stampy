<?php

namespace App\Services;

use App\Exceptions\AffectedLeavesChangedException;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 *
 * Deactivating also settles the employee's pending and approved leaves after
 * left_on, in the same write (LeaveRequestService::applyDeactivation()): one
 * that starts after it is cancelled, one that spans it is cut to end on it (or
 * cancelled, if nothing working is left) — and, since Phase 4c, cancels their
 * pending and approved overtime dated after it (OvertimeRequestService::
 * applyDeactivation()); the rebuild then takes back any time off in lieu that
 * overtime credited. Reactivating doesn't restore any of them.
 */
class EmployeeLifecycle
{
    public function __construct(
        private DailySummaryBuilder $builder,
        private EmployeeScheduleAssigner $assigner,
        private LeaveRequestService $leaves,
        private OvertimeRequestService $overtime,
    ) {}

    /**
     * Everything deactivating with this last day changes — leave and overtime
     * requests in one list, each item with its kind — shown to the admin
     * before they confirm (Employees\StatusModal).
     *
     * @return list<array<string, mixed>>
     */
    public function deactivationEffects(Employee $employee, CarbonInterface $leftOn): array
    {
        return [
            ...array_map(fn (array $effect) => ['kind' => 'leave', ...$effect], $this->leaves->deactivationEffects($employee, $leftOn)),
            ...$this->overtime->deactivationEffects($employee, $leftOn),
        ];
    }

    /**
     * Two effect lists describe the same change.
     *
     * @param  list<array<string, mixed>>  $effects
     */
    public static function effectsKey(array $effects): string
    {
        return collect($effects)->map(fn (array $effect) => $effect['kind'].':'.$effect['id'].':'.$effect['action'].':'.$effect['end'])->implode('|');
    }

    /**
     * $expectedLeaveEffects is the list of affected leave and overtime the
     * admin was shown (deactivationEffects()); if it no longer matches,
     * nothing is written (AffectedLeavesChangedException). Null skips the check.
     *
     * @param  list<array<string, mixed>>|null  $expectedLeaveEffects
     * @return array{days: int, rebuildError: ?string}
     */
    public function deactivate(Employee $employee, CarbonInterface $leftOn, ?User $actor = null, ?array $expectedLeaveEffects = null): array
    {
        DB::transaction(function () use ($employee, $leftOn, $actor, $expectedLeaveEffects) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);

            $effects = $this->deactivationEffects($employee, $leftOn);

            if ($expectedLeaveEffects !== null && self::effectsKey($effects) !== self::effectsKey($expectedLeaveEffects)) {
                throw new AffectedLeavesChangedException($effects);
            }

            $this->leaves->applyDeactivation($employee, $leftOn, $actor);
            $this->overtime->applyDeactivation($employee, $leftOn, $actor);
            $employee->update(['status' => 'inactive', 'left_on' => $leftOn->format('Y-m-d')]);
        });

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
