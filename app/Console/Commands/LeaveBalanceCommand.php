<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Leave\Balance;
use App\Services\Leave\LeaveBalance;
use App\Support\DisplayDate;
use App\Support\LeaveDays;
use Illuminate\Console\Command;

/**
 * Read-only: every leave type's balance for one employee and year, for HR and
 * for checking LeaveBalance by hand. Writes nothing.
 */
class LeaveBalanceCommand extends Command
{
    protected $signature = 'leave:balance
        {employee : The employee_code}
        {--year= : The leave year (defaults to the current year)}';

    protected $description = 'Show an employee\'s leave balances for a year (read-only).';

    public function handle(LeaveBalance $balances): int
    {
        $employee = Employee::query()->where('employee_code', $this->argument('employee'))->first();

        if ($employee === null) {
            $this->error("No employee found with code \"{$this->argument('employee')}\".");

            return self::FAILURE;
        }

        $year = $this->option('year') !== null ? (int) $this->option('year') : today()->year;

        if ($year < 2000 || $year > 2100) {
            $this->error("Invalid --year \"{$this->option('year')}\".");

            return self::FAILURE;
        }

        $this->info("{$employee->full_name} ({$employee->employee_code}) — {$year}. Joined ".DisplayDate::compact($employee->join_date)
            .($employee->left_on ? ', last day '.DisplayDate::compact($employee->left_on) : '').'.');

        $rows = LeaveType::query()->orderBy('id')->get()
            ->map(fn (LeaveType $type) => $this->row($balances->for($employee, $type, $year)))
            ->all();

        $this->table(['Type', 'Entitled', 'Carried in', 'Adjustments', 'Used', 'Pending', 'Available', 'Notes'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function row(Balance $balance): array
    {
        $type = $balance->type;

        if (! $balance->hasBalance) {
            $note = $type->deductsFrom ? "no balance of its own — counts against {$type->deductsFrom->name}" : 'no balance';

            return [$type->name, '—', '—', '—', '—', '—', '—', $note];
        }

        $notes = [];

        if ($balance->usableFrom !== null) {
            $notes[] = 'usable from '.DisplayDate::compact($balance->usableFrom);
        }

        if ($balance->used > 0 && $balance->carriedIn > 0) {
            $notes[] = 'used '.LeaveDays::format($balance->usedFromCarry).' from carry, '.LeaveDays::format($balance->usedFromGrant).' from grant';
        }

        foreach ($balance->usedByType + $balance->pendingByType as $name => $_) {
            if ($name !== $type->name) {
                $notes[] = "includes {$name}: ".LeaveDays::format(($balance->usedByType[$name] ?? 0) + ($balance->pendingByType[$name] ?? 0));
            }
        }

        if ($balance->earnedToLastDay !== null) {
            $notes[] = 'earned to last day '.LeaveDays::format($balance->earnedToLastDay);
        }

        return [
            $type->name.($type->is_active ? '' : ' (inactive)'),
            LeaveDays::format($balance->entitled),
            LeaveDays::format($balance->carriedIn),
            LeaveDays::format($balance->adjustments),
            LeaveDays::format($balance->used),
            LeaveDays::format($balance->pending),
            LeaveDays::format($balance->available()),
            implode('; ', $notes),
        ];
    }
}
