<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deactivation was confirmed against one list of affected leave and
 * overtime requests (EmployeeLifecycle::deactivationEffects()), but by the
 * time it was written the list was different (one was submitted, approved or
 * cancelled in between). Nothing is written; the caller shows the new list
 * (effects()) and asks again.
 */
class AffectedLeavesChangedException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $effects
     */
    public function __construct(private readonly array $effects)
    {
        parent::__construct('The leave and overtime affected by this deactivation changed — check the list again.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function effects(): array
    {
        return $this->effects;
    }
}
