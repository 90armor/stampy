<?php

namespace App\Services\Overtime;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Models\Employee;
use App\Services\Attendance\OvertimeCredit;
use Carbon\CarbonImmutable;

/**
 * What OvertimeRequestService::submit() would file, checked by the same rules
 * and nothing written (preview()) — the request modal's review (Phase 4d).
 *
 * - credit: the window's minutes by category if all of it were worked
 *   (OvertimeCalculator), counted: the spans that count, normal: the part
 *   inside normal working hours (null when none is).
 * - limitProblems: an admin over a daily limit with no override reason yet
 *   (anyone else is refused before a review exists).
 * - punches: the day's paired punches for a claim, when it has a row, and
 *   punchCredit: what those punches would credit if it were approved (the
 *   builder's own calculation) — null without both punches. A claim for
 *   5–6 PM by someone who punched out at 5:08 PM credits 8m, not 1h.
 * - toil: for time off — the minutes it adds (after the ratio), the minutes
 *   already saved toward the next half day, and how many half days it would
 *   complete.
 */
final readonly class OvertimePreview
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $counted
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $normal
     * @param  list<string>  $limitProblems
     * @param  array{in: ?CarbonImmutable, out: ?CarbonImmutable}|null  $punches
     * @param  array{adds: int, saved: int, completes: int}|null  $toil
     */
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public OvertimeKind $kind,
        public OvertimeCompensation $compensation,
        public OvertimeCredit $credit,
        public array $counted,
        public ?array $normal,
        public array $limitProblems,
        public ?string $overrideReason,
        public ?array $punches,
        public ?OvertimeCredit $punchCredit,
        public ?array $toil,
        public bool $approvedOnSubmit,
        public string $reviewers,
    ) {}

    /** Ends on the next day — "(+1)". */
    public function isOvernight(): bool
    {
        return ! $this->endsAt->isSameDay($this->startsAt);
    }

    /**
     * Changes when anything the review shows would — submit() compares it with
     * the reviewed one. Not the limit problems: an admin's override reason
     * clears them, and submit() checks the limits again under the lock.
     */
    public function key(): string
    {
        return md5(json_encode([
            $this->employee->id, $this->date->format('Y-m-d'), $this->startsAt->toIso8601String(), $this->endsAt->toIso8601String(),
            $this->kind->value, $this->compensation->value, $this->credit->attributes(), $this->toil,
            $this->approvedOnSubmit,
        ]));
    }
}
