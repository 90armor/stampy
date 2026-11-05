<?php

use App\Models\Employee;

// AUDIT-ONLY (Phase 6b follow-up screenshots): the id of persona A1's own
// employee record (AUD-001, linked to audit.admin).
echo 'ID='.Employee::where('employee_code', 'AUD-001')->value('id')."\n";
