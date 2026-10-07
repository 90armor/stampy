<?php

namespace App\Services\Approval;

use App\Contracts\Approvable;
use App\Enums\ApprovalOutcome;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The shared approval engine: who may decide each step of an Approvable, and
 * the one way a step is recorded (CLAUDE.md, Phase 3, Approval rule 8). The
 * request's own status lives on its model; this class knows only steps.
 *
 * - Step 1 (MANAGER_STEP): users with the manager or admin role whose employee
 *   manages the requester — Employee::isManagerOf(), transitive, so a
 *   skip-level manager can act. The same rule EmployeeScope uses to show a
 *   manager their team. An employee-role user who happens to be someone's
 *   manager_id is not an approver: they can't see that employee anywhere.
 * - Step 2 (ADMIN_STEP): admins.
 * - Nobody decides a step of their own request. The exception: a requester who
 *   is the only admin has their step 2 recorded as self_approved (after step 1,
 *   which still runs).
 * - An admin may act at step 1 (overriding the manager); their one decision
 *   completes both steps. A manager who is also an admin does the same.
 *
 * Eligibility is evaluated whenever it's asked, never snapshotted at submit:
 * if the chain changes while a request is pending, it waits at step 1 for an
 * admin's override (ApprovalInbox lists it).
 */
class ApprovalFlow
{
    public const MANAGER_STEP = 1;

    public const ADMIN_STEP = 2;

    /** The user whose request this is: the subject employee's login, if any. */
    public function requester(Approvable $approvable): ?User
    {
        return $approvable->approvalSubject()->user;
    }

    /**
     * @return Collection<int, User>
     */
    public function stepOneApprovers(Approvable $approvable): Collection
    {
        $subject = $approvable->approvalSubject();
        $requesterId = $this->requester($approvable)?->id;

        return User::role(['manager', 'admin'])
            ->with('employee')
            ->get()
            ->filter(fn (User $user) => $user->id !== $requesterId
                && $user->employee !== null
                && $user->employee->isManagerOf($subject))
            ->values();
    }

    /**
     * Why step 1 has nobody to decide it, or null when someone can — recorded
     * as the skipped step's note. An employee-role login in the chain is named:
     * that's usually a setup mistake an admin should see.
     */
    public function stepOneSkipReason(Approvable $approvable): ?string
    {
        if ($this->stepOneApprovers($approvable)->isNotEmpty()) {
            return null;
        }

        $subject = $approvable->approvalSubject();

        if ($subject->manager_id === null) {
            return "No manager is assigned to {$subject->full_name}.";
        }

        $chain = $this->managersOf($subject);
        $withoutRole = $chain->filter(fn (Employee $manager) => $manager->user !== null);

        if ($withoutRole->isNotEmpty()) {
            $names = $withoutRole->pluck('full_name')->implode(', ');

            return "{$names} ".($withoutRole->count() === 1 ? 'manages' : 'manage')." {$subject->full_name} but ".($withoutRole->count() === 1 ? "doesn't" : "don't").' have the manager role.';
        }

        return "No one who manages {$subject->full_name} has a login.";
    }

    /**
     * @return Collection<int, User>
     */
    public function stepTwoApprovers(Approvable $approvable): Collection
    {
        $requesterId = $this->requester($approvable)?->id;

        return User::role('admin')->get()->reject(fn (User $user) => $user->id === $requesterId)->values();
    }

    /** The requester is the only admin, so their step 2 is self_approved. */
    public function isSelfApprovedAtStepTwo(Approvable $approvable): bool
    {
        $requester = $this->requester($approvable);

        return $requester !== null && $requester->hasRole('admin') && $this->stepTwoApprovers($approvable)->isEmpty();
    }

    /** May $actor decide the step $approvable is waiting at? */
    public function canDecide(User $actor, Approvable $approvable): bool
    {
        $step = $approvable->currentApprovalStep();

        if ($step === null || $actor->is($this->requester($approvable))) {
            return false;
        }

        if ($actor->hasRole('admin')) {
            return true;
        }

        return $step === self::MANAGER_STEP
            && $this->stepOneApprovers($approvable)->contains(fn (User $user) => $user->is($actor));
    }

    /**
     * Whether $actor's decision at step 1 completes both steps: an admin's
     * (overriding the manager, or a manager who is also an admin).
     */
    public function decidesBothSteps(User $actor, Approvable $approvable): bool
    {
        return $approvable->currentApprovalStep() === self::MANAGER_STEP && $actor->hasRole('admin');
    }

    public function record(Approvable $approvable, int $step, ApprovalOutcome $outcome, ?User $decidedBy, ?string $note = null): ApprovalStep
    {
        return $approvable->approvalSteps()->create([
            'step' => $step,
            'outcome' => $outcome,
            'decided_by' => $decidedBy?->id,
            'note' => $note,
            'decided_at' => now(),
        ]);
    }

    /**
     * Everyone above $subject in the reporting tree, found through
     * isManagerOf() over the employees who manage anyone — never a second walk
     * of manager_id.
     *
     * @return Collection<int, Employee>
     */
    private function managersOf(Employee $subject): Collection
    {
        return Employee::query()
            ->whereIn('id', Employee::query()->whereNotNull('manager_id')->select('manager_id'))
            ->with('user')
            ->get()
            ->filter(fn (Employee $manager) => $manager->isManagerOf($subject))
            ->values();
    }
}
