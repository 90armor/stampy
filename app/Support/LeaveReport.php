<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\Balance;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The yearly leave report (Phase 5b): for one leave type and year, one row
 * per employee active on any day of the year in the viewer's scope
 * (EmployeeScope — today's team), ordered by employee code.
 *
 * Every row's figures are LeaveBalance's own Balance — the numbers Time off
 * and the profile's Leave card show — never a second calculation
 * (LeaveReportTest compares them). A type without a balance (Unpaid,
 * Maternity, Special) has only its used days. An earned type (Time off in
 * lieu) lists only the employees it is shown for elsewhere
 * (LeaveType::isShownFor()), so the report agrees with Time off; everyone
 * else is left out, not a row of zeros.
 *
 * Every row is computed at once (LeaveBalance::forMany(), LeaveType::
 * shownFor() and toilRemaindersFor()), the same Balance for() gives one
 * employee, so the queries don't grow with the rows (Phase 6c; until then
 * about seven an employee).
 */
final class LeaveReport
{
    /** The figures summed in the Total row, in column order. */
    public const FIGURES = ['entitled', 'carriedIn', 'adjustments', 'used', 'pending', 'available'];

    /**
     * @return array{
     *     rows: list<array{employee: Employee, joined: ?Carbon, left: ?Carbon, balance: Balance, earnedSoFar: ?int, toilRemainder: ?int}>,
     *     totals: array<string, int>,
     *     hasBalance: bool,
     *     current: bool,
     *     departments: array<int, string>,
     *     department: ?int,
     * }
     *
     * Amounts are tenths of a day (LeaveDays). `earnedSoFar` is set while the
     * type isn't usable yet (EntitlementCalculator::earnedSoFar(), as Time
     * off shows it); `toilRemainder` for Time off in lieu, the overtime minutes
     * saved toward the next half day (LeaveType::toilRemainderFor()) — why
     * someone with 0 available is listed at all. `current` is this year: its
     * pending is as of now.
     */
    public static function forYear(int $year, LeaveType $type, User $viewer, ?int $departmentId = null): array
    {
        $from = Carbon::create($year)->startOfYear();
        $to = $from->copy()->endOfYear()->startOfDay();
        $scope = EmployeeScope::for($viewer, 'Leave report')->ids;

        $departments = Department::query()
            ->when($scope !== null, fn (Builder $query) => $query->whereHas('employees', fn (Builder $q) => $q->whereIn('id', $scope)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
        $departmentId = array_key_exists((int) $departmentId, $departments) ? (int) $departmentId : null;

        $employees = Employee::query()
            ->activeBetween($from, $to)
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('id', $scope))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->with('department')
            ->orderBy('employee_code')
            ->get();
        $shown = $type->shownFor($employees);
        $employees = $employees->filter(fn (Employee $employee) => $shown[$employee->id]);

        $balances = app(LeaveBalance::class)->forMany($employees, $type, $year);
        $remainders = $type->toilRemaindersFor($employees->pluck('id')->all());
        $calculator = app(EntitlementCalculator::class);
        $rows = [];
        $totals = array_fill_keys(self::FIGURES, 0);

        foreach ($employees as $employee) {
            $balance = $balances[$employee->id];

            foreach (self::FIGURES as $figure) {
                $totals[$figure] += $figure !== 'available' ? $balance->{$figure} : self::available($balance);
            }

            $rows[] = [
                'employee' => $employee,
                'joined' => $employee->join_date->year === $year ? $employee->join_date : null,
                'left' => $employee->left_on?->year === $year ? $employee->left_on : null,
                'balance' => $balance,
                'earnedSoFar' => $balance->usableFrom !== null ? $calculator->earnedSoFar($employee, $type, today()) : null,
                'toilRemainder' => $remainders[$employee->id],
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'hasBalance' => $type->hasBalance(),
            'current' => $year === today()->year,
            'departments' => $departments,
            'department' => $departmentId,
        ];
    }

    /**
     * The Available a row shows in bold and the Total sums: a leaver's at
     * their last day (Balance::availableAtLastDay()), everyone else's for the
     * year. A type without a balance has nothing available.
     */
    public static function available(Balance $balance): int
    {
        return ! $balance->hasBalance ? 0 : ($balance->availableAtLastDay() ?? $balance->available());
    }

    /**
     * The years to offer: earlier years with any grant, this year, and next
     * year once anything is granted for it. Newest first.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        $now = today()->year;
        $granted = LeaveEntitlement::query()->distinct()->pluck('year')->map(fn ($year) => (int) $year);

        return $granted
            ->filter(fn (int $year) => $year < $now || $year === $now + 1)
            ->push($now)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * The types to offer for a year: every active type, and an inactive one
     * that has a leave, grant or adjustment that year. Annual (the first with
     * a yearly grant) first is the caller's default.
     *
     * @return Collection<int, LeaveType>
     */
    public static function types(int $year): Collection
    {
        $from = Carbon::create($year)->startOfYear()->format('Y-m-d');
        $to = Carbon::create($year)->endOfYear()->format('Y-m-d');

        return LeaveType::query()
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->orWhereIn('id', Leave::query()->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->select('leave_type_id'))
                ->orWhereIn('id', LeaveEntitlement::query()->where('year', $year)->select('leave_type_id'))
                ->orWhereIn('id', LeaveAdjustment::query()->where('year', $year)->select('leave_type_id')))
            ->orderBy('id')
            ->get();
    }
}
