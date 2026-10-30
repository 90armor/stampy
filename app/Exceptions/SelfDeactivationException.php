<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An admin tried to deactivate the employee record linked to their own
 * login (Phase 6b). Refused before anything is written: it would end their
 * own access the next day (EnsureStillEmployed), and could leave the company
 * without an admin. Another admin does it.
 */
class SelfDeactivationException extends RuntimeException
{
    public const MESSAGE = "You can't deactivate your own record. Ask another admin.";

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
