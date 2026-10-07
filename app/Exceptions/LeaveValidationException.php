<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every reason LeaveRequestService refuses a request, keyed by the form field
 * it belongs to, so a Livewire form can show each under its field:
 *
 *     catch (LeaveValidationException $e) {
 *         foreach ($e->errors() as $field => $messages) {
 *             foreach ($messages as $message) { $this->addError($field, $message); }
 *         }
 *     }
 *
 * Keys: leave_type_id (the type), start_date / end_date (dates, eligibility,
 * cost, overlap), half (half-day rules), and leave for what belongs to the
 * request as a whole (balance). Checks run in stages and the first stage with
 * a failure throws, so later checks never run on input an earlier one
 * rejected. getMessage() is the first message, for logs and the console.
 */
class LeaveValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(collect($errors)->flatten()->first() ?? 'The leave request is invalid.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
