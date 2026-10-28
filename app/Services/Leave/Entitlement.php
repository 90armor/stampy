<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * EntitlementCalculator's answer for one employee, type and year:
 *
 * - noBalance(): the type isn't granted yearly (LeaveBalanceSource) — no
 *   balance, or one earned from overtime.
 * - notEligible(): nothing is granted for this year — it is before the
 *   employee's eligibility year (what they earn meanwhile is folded into
 *   that year's grant). eligibleOn says when it becomes usable.
 * - grant(): this year's grant, in tenths of a day (LeaveDays), usable from
 *   eligibleOn — the service-requirement date, or join_date for a type
 *   without one.
 */
final readonly class Entitlement
{
    private function __construct(
        public bool $hasBalance,
        public ?int $days,
        public ?CarbonImmutable $eligibleOn,
    ) {}

    public static function noBalance(): self
    {
        return new self(false, null, null);
    }

    public static function notEligible(CarbonInterface $eligibleOn): self
    {
        return new self(true, null, CarbonImmutable::instance($eligibleOn)->startOfDay());
    }

    public static function grant(int $days, CarbonInterface $eligibleOn): self
    {
        return new self(true, $days, CarbonImmutable::instance($eligibleOn)->startOfDay());
    }

    public function isGrant(): bool
    {
        return $this->days !== null;
    }

    /** A grant whose service requirement is met by $date — what LeaveGranter creates. */
    public function isGrantableOn(CarbonInterface $date): bool
    {
        return $this->isGrant() && $this->eligibleOn->lte($date->copy()->startOfDay());
    }
}
