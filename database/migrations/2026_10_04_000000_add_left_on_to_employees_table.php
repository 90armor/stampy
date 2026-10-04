<?php

use App\Support\EmployeeLeftOnBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's last day of employment (Phase 3a). Required while
     * status = inactive, null while active (Employee::booted()) — "active on
     * a date" is join_date ≤ date ≤ left_on (Employee::scopeActiveOn()).
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('left_on')->nullable()->after('status');
        });

        EmployeeLeftOnBackfill::run();
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('left_on');
        });
    }
};
