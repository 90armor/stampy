<?php

namespace App\Contracts;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A request that goes through the shared approval engine (ApprovalFlow) and
 * the approvals inbox (ApprovalInbox): leaves and overtime requests. Only what
 * those need — the request's own data stays in its own typed table and model.
 * Implemented by Eloquent models (the inbox reads created_at).
 */
interface Approvable
{
    /** The employee the request is for: step 1 is their manager's. */
    public function approvalSubject(): Employee;

    /** Decided steps, in order (approval_steps, morph alias). */
    public function approvalSteps(): MorphMany;

    /** The step waiting for a decision, or null once none is. */
    public function currentApprovalStep(): ?int;

    /** The date the request is about, for ordering: a leave's first day, overtime's work date. */
    public function approvalDate(): CarbonInterface;

    /**
     * When the requested time starts, for the inbox's "starts soon" urgency —
     * or null when there's nothing still to start (an overtime claim: the
     * work is done, so only its waiting time can make it stuck).
     */
    public function approvalStartsOn(): ?CarbonInterface;
}
