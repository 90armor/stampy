<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schedule an employee is on is no longer a single column here — it's
 * derived from employee_work_schedules (see Employee::scheduleOn()), which
 * this table's every row must already be backfilled into before this runs
 * (2026_09_22_000000_create_employee_work_schedules_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('device_user_id')->constrained()->nullOnDelete();
        });
    }
};
