<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * What a leave request would do, checked by LeaveRequestService::preview()
 * with the same rules as submit() and nothing written — the review step of
 * the request modal (Phase 3e). Amounts are tenths of a day (LeaveDays).
 *
 * - cost: per year, what the request charges.
 * - balances: per year, the balance type's available before and after
 *   (empty for a type with no balance).
 * - notCharged: the dates inside the range that cost nothing, with why
 *   ("Saturday", a holiday's name, "Day off").
 * - approvedOnSubmit: an admin filing for someone else — final on submit.
 */
final readonly class LeavePreview
{
    /**
     * @param  array<int, int>  $cost
     * @param  array<int, array{before: int, after: int}>  $balances
     * @param  list<array{date: CarbonImmutable, reason: string}>  $notCharged
     */
    public function __construct(
        public Employee $employee,
        public LeaveType $type,
        public LeaveType $balanceType,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $half,
        public array $cost,
        public array $balances,
        public array $notCharged,
        public bool $approvedOnSubmit,
    ) {}

    public function total(): int
    {
        return array_sum($this->cost);
    }

    /**
     * Everything the reviewer saw, as one string: if a second check gives a
     * different key, something changed (a holiday added, another request
     * approved) and the review is shown again instead of writing.
     */
    public function key(): string
    {
        return json_encode([
            $this->employee->id, $this->type->id, $this->start->format('Y-m-d'), $this->end->format('Y-m-d'), $this->half,
            $this->cost, $this->balances, array_map(fn (array $day) => $day['date']->format('Y-m-d').$day['reason'], $this->notCharged),
            $this->approvedOnSubmit,
        ]);
    }
}
