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
 * One LeaveBalance per row, so queries grow with the rows (about seven an
 * employee): measured at 50 employees, one type took 72 ms and every type
 * (the CSV) 185 ms — not worth a second, batched calculation to keep equal.
 */
final class LeaveReport
{
    /** The figures summed in the Total row, in column order. */
    public const FIGURES = ['entitled', 'carriedIn', 'adjustments', 'used', 'pending', 'available'];

    /**
     * @return array{
     *     rows: list<array{employee: Employee, joined: ?Carbon, left: ?Carbon, balance: Balance, earnedSoFar: ?int}>,
     *     totals: array<string, int>,
     *     hasBalance: bool,
     *     current: bool,
     *     departments: array<int, string>,
     *     department: ?int,
     * }
     *
     * Amounts are tenths of a day (LeaveDays). `earnedSoFar` is set while the
     * type isn't usable yet (EntitlementCalculator::earnedSoFar(), as Time
     * off shows it). `current` is this year: its pending is as of now.
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
            ->get()
            ->filter(fn (Employee $employee) => $type->isShownFor($employee));

        $balances = app(LeaveBalance::class);
        $calculator = app(EntitlementCalculator::class);
        $rows = [];
        $totals = array_fill_keys(self::FIGURES, 0);

        foreach ($employees as $employee) {
            $balance = $balances->for($employee, $type, $year);

            foreach (self::FIGURES as $figure) {
                // A type without a balance has nothing available to sum.
                $totals[$figure] += $figure !== 'available' ? $balance->{$figure} : ($balance->hasBalance ? $balance->available() : 0);
            }

            $rows[] = [
                'employee' => $employee,
                'joined' => $employee->join_date->year === $year ? $employee->join_date : null,
                'left' => $employee->left_on?->year === $year ? $employee->left_on : null,
                'balance' => $balance,
                'earnedSoFar' => $balance->usableFrom !== null ? $calculator->earnedSoFar($employee, $type, today()) : null,
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
     * @return \Illuminate\Support\Collection<int, LeaveType>
     */
    public static function types(int $year): \Illuminate\Support\Collection
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
