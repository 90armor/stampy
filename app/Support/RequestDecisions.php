<?php

namespace App\Support;

use App\Contracts\Approvable;
use App\Enums\ApprovalOutcome;
use App\Models\ApprovalStep;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The "New" state on an employee's own requests — with no email, it's how they
 * find out a request was decided. One rule for every request type (Phase 4d):
 * LeaveDecisions applies it to leave (Time off), the Overtime page to
 * overtime, each page with its own "last seen" time.
 *
 * A request is new when someone other than the user decided it after the
 * user last looked — a step approved or rejected, or the request cancelled.
 * One the user cancelled themself isn't news, whatever was decided before:
 * their own last word supersedes it. Nothing is new before the first visit
 * ($seenAt null): everything would be, which says nothing.
 *
 * Every request must have its approvalSteps.decidedBy loaded and a status,
 * cancelled_by and cancelled_at (Leave, OvertimeRequest).
 */
final class RequestDecisions
{
    /**
     * @template T of Approvable
     *
     * @param  Collection<int, T>  $requests  the employee's requests
     * @return Collection<int, T> those decided after $seenAt, newest decision first
     */
    public static function since(Collection $requests, User $user, ?CarbonInterface $seenAt): Collection
    {
        if ($seenAt === null) {
            return collect();
        }

        return $requests
            ->reject(fn ($request) => $request->status->value === 'cancelled' && $request->cancelled_by === $user->id)
            ->map(fn ($request) => ['request' => $request, 'at' => self::decidedAt($request, $user)])
            ->filter(fn (array $item) => $item['at'] !== null && $item['at']->gt($seenAt))
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->pluck('request')
            ->values();
    }

    /** The decision's own note (approve or reject), the requester's feedback. */
    public static function note(Approvable $request): ?ApprovalStep
    {
        return $request->approvalSteps
            ->filter(fn (ApprovalStep $step) => $step->note !== null && in_array($step->outcome, [ApprovalOutcome::Approved, ApprovalOutcome::Rejected], true))
            ->last();
    }

    /**
     * When someone other than $user last decided the request: the latest
     * approved or rejected step they recorded, or a cancellation — what the
     * dashboard orders leave and overtime decisions by together.
     */
    public static function decidedAt($request, User $user): ?CarbonInterface
    {
        $times = $request->approvalSteps
            ->filter(fn (ApprovalStep $step) => in_array($step->outcome, [ApprovalOutcome::Approved, ApprovalOutcome::Rejected], true) && $step->decided_by !== $user->id)
            ->pluck('decided_at');

        if ($request->status->value === 'cancelled' && $request->cancelled_by !== $user->id && $request->cancelled_at !== null) {
            $times->push($request->cancelled_at);
        }

        return $times->sortByDesc(fn (CarbonInterface $at) => $at->getTimestamp())->first();
    }
}
