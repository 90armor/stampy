<?php

namespace App\Services\Leave;

use App\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * One leave type's balance for one employee and year (LeaveBalance). Every
 * amount is integer tenths of a day (LeaveDays).
 *
 * available = entitled + carriedIn + adjustments − used − pending. used and
 * pending include leaves of types that deduct from this one (Special from
 * Annual); usedByType/pendingByType break both down by the leave's own type.
 * Usage consumes the carry first (FIFO): usedFromCarry + usedFromGrant = used.
 * usableFrom is set while the type isn't usable yet (the eligibility date);
 * earnedToLastDay only in a leaver's last year — display only.
 */
final readonly class Balance
{
    /**
     * @param  array<string, int>  $usedByType
     * @param  array<string, int>  $pendingByType
     */
    public function __construct(
        public LeaveType $type,
        public int $year,
        public bool $hasBalance,
        public int $entitled = 0,
        public int $carriedIn = 0,
        public int $adjustments = 0,
        public int $used = 0,
        public int $pending = 0,
        public int $usedFromCarry = 0,
        public int $usedFromGrant = 0,
        public array $usedByType = [],
        public array $pendingByType = [],
        public ?CarbonImmutable $usableFrom = null,
        public ?int $earnedToLastDay = null,
    ) {}

    public function available(): int
    {
        return $this->entitled + $this->carriedIn + $this->adjustments - $this->used - $this->pending;
    }
}
