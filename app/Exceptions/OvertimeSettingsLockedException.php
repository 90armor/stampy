<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Once any system-authored TOIL adjustment exists, toil_ratio_percent,
 * toil_block_minutes and toil_leave_type_id can't change (OvertimeSettings):
 * TimeOffInLieuReconciler recomputes from all-time minutes, so a new ratio or
 * block would silently re-value every past overtime hour, and a new type
 * would orphan what was posted to the old one. Changing them later is a
 * deliberate data operation, not a settings edit.
 */
class OvertimeSettingsLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The time-off-in-lieu ratio, block and leave type can\'t change once time off in lieu has been credited: '
            .'every past overtime hour would be re-valued. Changing them is a deliberate data operation, not a settings edit.'
        );
    }
}
