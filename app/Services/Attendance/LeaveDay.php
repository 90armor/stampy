<?php

namespace App\Services\Attendance;

use App\Enums\LeaveHalf;
use App\Models\Leave;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What approved leave means for one date (CLAUDE.md, Phase 3, rules 16–18),
 * resolved from the leaves DailySummaryBuilder loaded for the range:
 *
 * - fullDay: a whole-day leave covers the date — or an AM and a PM leave
 *   together, which leave no expected window either.
 * - half: the one half that's on leave, when only one is.
 * - leaveId: the leave to record on the row (daily_attendances.leave_id) —
 *   the whole-day one, else the AM one, else the PM one.
 */
final readonly class LeaveDay
{
    private function __construct(
        public ?int $leaveId,
        public bool $fullDay,
        public ?LeaveHalf $half,
    ) {}

    /**
     * @param  Collection<int, Leave>  $leaves  approved leaves of the employee
     */
    public static function on(Collection $leaves, CarbonInterface $date): self
    {
        $covering = $leaves->filter(fn (Leave $leave) => $leave->start_date->lte($date) && $leave->end_date->gte($date));
        $whole = $covering->first(fn (Leave $leave) => $leave->half === null);
        $am = $covering->first(fn (Leave $leave) => $leave->half === LeaveHalf::Am);
        $pm = $covering->first(fn (Leave $leave) => $leave->half === LeaveHalf::Pm);

        $fullDay = $whole !== null || ($am !== null && $pm !== null);

        return new self(
            ($whole ?? $am ?? $pm)?->id,
            $fullDay,
            $fullDay ? null : ($am ?? $pm)?->half,
        );
    }

    public static function none(): self
    {
        return new self(null, false, null);
    }

    /** The same leave, its half ignored — the builder's fallback for a half-day on a schedule with no break_start. */
    public function withoutHalf(): self
    {
        return new self($this->leaveId, $this->fullDay, null);
    }

    public function isHalfDay(): bool
    {
        return $this->half !== null;
    }
}
