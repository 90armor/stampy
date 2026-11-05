<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;

// AUDIT-ONLY (Phase 6a): 70 extra employees (no login, no punches) so the
// directory has 100+ rows. Removed by the final reseed.
$dept = Department::where('name', 'Operations')->first();
$pos = Position::orderBy('id')->first();
for ($i = 1; $i <= 70; $i++) {
    Employee::create(['employee_code' => sprintf('STR-%03d', $i), 'full_name' => "Stress Test Person $i", 'department_id' => $dept->id, 'position_id' => $pos->id, 'join_date' => '2024-01-01']);
}
echo Employee::count(), " employees\n";
