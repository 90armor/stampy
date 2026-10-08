<?php

namespace App\Services\Overtime;

use App\Models\LeaveAdjustment;

/**
 * What one TimeOffInLieuReconciler::reconcile() did, in tenths of a day:
 *
 * - settled: target and posted already agree — nothing written;
 * - posted: the difference was written as $adjustment;
 * - noToilType: the employee has time-off overtime to credit, but the
 *   settings name no TOIL leave type — nothing written (the caller logs one
 *   summary line per run, not one per employee).
 */
final readonly class ToilReconciliation
{
    private function __construct(
        public string $outcome,
        public int $target,
        public int $posted,
        public ?LeaveAdjustment $adjustment = null,
    ) {}

    public static function settled(int $target): self
    {
        return new self('settled', $target, $target);
    }

    public static function posted(int $target, int $posted, LeaveAdjustment $adjustment): self
    {
        return new self('posted', $target, $posted, $adjustment);
    }

    public static function noToilType(int $target): self
    {
        return new self('noToilType', $target, 0);
    }

    public function isNoToilType(): bool
    {
        return $this->outcome === 'noToilType';
    }
}
