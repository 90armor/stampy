<?php

namespace App\Support;

use App\Enums\LeaveStatus;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Livewire\Leave\TimeOff;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the dashboard shows someone without the team figures (Phase 3e):
 * requests decided since they last opened Time off (LeaveDecisions — with
 * no email, this is where an employee finds out), their leave balances, the
 * next approved leave, their pending requests and this month's attendance.
 * Since Phase 4d overtime too: decisions since they last opened Overtime
 * (RequestDecisions, users.overtime_seen_at), pending and approved planned
 * overtime under Upcoming, and the month's credited overtime
 * (OvertimeSummary).
 * Every figure comes from the shared definitions — LeaveBalance,
 * EntitlementCalculator, LeaveDayCounter, LeaveDecisions and
 * AttendanceSummary — the same ones Time off and My attendance show, so the
 * pages can't disagree. The dashboard doesn't mark decisions seen: they stay
 * here until Time off is opened, for at most DECISIONS_DAYS.
 */
final class EmployeeDashboard
{
    /** How far back the decisions card looks, whenever Time off was last opened. */
    public const DECISIONS_DAYS = 30;

    /**
     * @return array{decided: list<Leave|OvertimeRequest>, balances: list<array{name: string, available: int, usableFrom: ?CarbonImmutable, earnedSoFar: ?int, toilRemainder: ?int}>, next: ?array{leave: Leave, days: int}, month: array<string, int>, pending: list<array{leave: Leave, days: int}>, overtime: list<OvertimeRequest>, overtimeThisMonth: int}
     */
    public static function for(Employee $employee, User $user): array
    {
        $balances = app(LeaveBalance::class);
        $calculator = app(EntitlementCalculator::class);
        $counter = app(LeaveDayCounter::class);

        $withDays = function (Leave $leave) use ($employee, $counter) {
            $leave->setRelation('employee', $employee);

            return ['leave' => $leave, 'days' => array_sum($counter->countLeave($leave))];
        };
        $next = Leave::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('end_date', '>=', today())
            ->with('leaveType')
            ->orderBy('start_date')
            ->orderByRaw("half = 'pm'")
            ->first();

        $since = fn (?CarbonInterface $seenAt) => $seenAt?->max(now()->subDays(self::DECISIONS_DAYS));
        $overtime = OvertimeRequest::query()->where('employee_id', $employee->id)->with('approvalSteps.decidedBy')->get();

        return [
            // At most the last 30 days, so someone who never opens Time off (or
            // Overtime) doesn't carry old decisions here forever. Both types
            // together, newest decision first.
            'decided' => LeaveDecisions::since($employee, $user, $since($user->time_off_seen_at))
                ->concat(RequestDecisions::since($overtime, $user, $since($user->overtime_seen_at)))
                ->sortByDesc(fn ($request) => RequestDecisions::decidedAt($request, $user)->getTimestamp())
                ->values()
                ->all(),
            // Waiting for a decision, then approved and still ahead.
            'overtime' => $overtime
                ->filter(fn (OvertimeRequest $request) => $request->status === OvertimeStatus::Pending
                    || ($request->status === OvertimeStatus::Approved && $request->kind === OvertimeKind::Planned && ! $request->date->lt(today())))
                ->sortBy(fn (OvertimeRequest $request) => [$request->status === OvertimeStatus::Pending ? 0 : 1, $request->date->getTimestamp()])
                ->values()
                ->all(),
            'overtimeThisMonth' => OvertimeSummary::forMonth($employee, today())['total'],
            'next' => $next !== null ? $withDays($next) : null,
            'balances' => LeaveType::query()
                ->where('is_active', true)
                ->shownFor($employee)
                ->orderBy('id')
                ->get()
                ->map(function (LeaveType $type) use ($employee, $balances, $calculator) {
                    $balance = $balances->for($employee, $type, today()->year);

                    return [
                        'name' => $type->name,
                        'available' => $balance->available(),
                        'usableFrom' => $balance->usableFrom,
                        'earnedSoFar' => $calculator->earnedSoFar($employee, $type, today()),
                        'toilRemainder' => TimeOff::toilRemainder($employee, $type),
                    ];
                })
                ->all(),
            'month' => AttendanceSummary::forMonth($employee, today()),
            'pending' => Leave::query()
                ->where('employee_id', $employee->id)
                ->where('status', LeaveStatus::Pending->value)
                ->with('leaveType')
                ->orderBy('start_date')
                ->get()
                ->map($withDays)
                ->all(),
        ];
    }
}
