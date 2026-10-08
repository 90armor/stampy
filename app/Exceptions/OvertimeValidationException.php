<?php

namespace App\Exceptions;

/**
 * Every reason OvertimeRequestService refuses a request, keyed by form field
 * (the shape is RequestValidationException's). Keys: date (employment, the
 * claim window, the planning horizon, leave, one request per date), starts_at
 * / ends_at (the window and whether any of it is overtime), compensation (no
 * TOIL type), limit_override_reason (an admin's override), note (a rejection
 * needs one), and overtime for the request as a whole (the daily limits).
 */
class OvertimeValidationException extends RequestValidationException {}
