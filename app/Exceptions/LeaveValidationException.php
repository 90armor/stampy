<?php

namespace App\Exceptions;

/**
 * Every reason LeaveRequestService refuses a request, keyed by form field
 * (the shape is RequestValidationException's). Keys: leave_type_id (the
 * type), start_date / end_date (dates, eligibility, cost, overlap — and, since
 * Phase 4c, an overtime request on a date the leave would cover), half
 * (half-day rules), and leave for what belongs to the request as a whole
 * (balance).
 */
class LeaveValidationException extends RequestValidationException {}
