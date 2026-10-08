<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every reason a request service refuses a request, keyed by the form field
 * it belongs to, so a Livewire form can show each under its field:
 *
 *     catch (RequestValidationException $e) {
 *         foreach ($e->errors() as $field => $messages) {
 *             foreach ($messages as $message) { $this->addError($field, $message); }
 *         }
 *     }
 *
 * Checks run in stages and the first stage with a failure throws, so later
 * checks never run on input an earlier one rejected. getMessage() is the
 * first message, for logs and the console. One shape for every request type:
 * LeaveValidationException (leave) and OvertimeValidationException (overtime)
 * add only their name and their field keys.
 */
class RequestValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(collect($errors)->flatten()->first() ?? 'The request is invalid.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
