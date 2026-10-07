<?php

namespace App\Contracts;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A request that goes through the shared approval engine (ApprovalFlow):
 * leaves now, overtime requests in Phase 4. Only what the engine needs — the
 * request's own data stays in its own typed table and model.
 */
interface Approvable
{
    /** The employee the request is for: step 1 is their manager's. */
    public function approvalSubject(): Employee;

    /** Decided steps, in order (approval_steps, morph alias). */
    public function approvalSteps(): MorphMany;

    /** The step waiting for a decision, or null once none is. */
    public function currentApprovalStep(): ?int;
}
