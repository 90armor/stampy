<?php

namespace App\Services\Overtime;

use App\Exceptions\OvertimeValidationException;

/**
 * Collects OvertimeRequestService's refusals by field, a stage at a time —
 * throwIfAny() ends the stage (LeaveRequestService does the same with two
 * closures).
 */
final class ValidationErrors
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** @throws OvertimeValidationException */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw new OvertimeValidationException($this->errors);
        }
    }
}
