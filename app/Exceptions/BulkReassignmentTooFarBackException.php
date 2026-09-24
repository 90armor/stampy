<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A bulk reassignment rebuilds every moved employee for the full range from
 * its effective date through today — unlike a single per-employee
 * assignment, that cost multiplies by however many employees are moved.
 * Measured on the dev database: 200 employees 60 days back took ~15s; 200
 * employees 365 days back took ~88s, past nginx's default 60s
 * fastcgi_read_timeout in this app's own docker-compose stack, with no
 * execution-time limit on the PHP side to stop it running (and no
 * ignore_user_abort(), so the request is simply cut off mid-work once nginx
 * gives up). Capped here rather than left to be discovered by a slow
 * production request. A correction further back than the cap goes through
 * per-employee assignment (EmployeeScheduleAssigner::assign(), no cap —
 * rebuilding one employee over a year is still well under a second) or the
 * manual attendance:build-daily command, which has no web-request timeout
 * at all.
 */
class BulkReassignmentTooFarBackException extends InvalidArgumentException
{
    public function __construct(CarbonInterface $effectiveFrom, int $maxDays)
    {
        parent::__construct(
            "Bulk reassignment can't be backdated more than {$maxDays} days ({$effectiveFrom->format('Y-m-d')} is "
            .'further back than that) — rebuilding many employees over a long range risks a web request timing '
            .'out partway. For a correction further back, reassign the affected employees individually, or run '
            .'attendance:build-daily by hand.'
        );
    }
}
