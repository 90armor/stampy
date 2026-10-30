<?php
// AUDIT-ONLY (Phase 6b follow-up screenshots): the id of persona A1's own
// employee record (AUD-001, linked to audit.admin).
echo 'ID='.App\Models\Employee::where('employee_code', 'AUD-001')->value('id')."\n";
