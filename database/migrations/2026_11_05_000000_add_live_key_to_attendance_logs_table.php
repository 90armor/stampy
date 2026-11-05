<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6h — re-adding a voided manual punch is a new row, so the history
 * keeps who added the first one, who voided it and both reasons. Until now
 * the unique index (employee_id, punched_at, source) counted voided rows, and
 * Attendance\Show::addPunch() revived the voided row in place, overwriting
 * all of that.
 *
 * MySQL has no partial index, so the key gains a virtual column that is NULL
 * for a voided manual punch and 1 for every other row. A unique index treats
 * NULLs as distinct, so voided manual punches never collide with anything,
 * while live punches collide exactly as before. Device punches are 1 whether
 * voided or not: a voided device punch still holds its key, so re-importing
 * the same file can't bring it back as a live duplicate (the reason voided_at
 * itself was never put in the key — create_attendance_logs_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('live_key')->nullable()
                ->virtualAs("case when source = 'manual' and voided_at is not null then null else 1 end")
                ->after('void_reason');
            // Added before the old index goes: the employee_id foreign key
            // needs an index that starts with it at every moment.
            $table->unique(['employee_id', 'punched_at', 'source', 'live_key'], 'attendance_logs_live_punch_unique');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'punched_at', 'source']);
        });
    }

    /**
     * Fails while a voided manual punch and its re-added row share a key:
     * the old index can't hold both, and deleting either would lose history.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unique(['employee_id', 'punched_at', 'source']);
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('attendance_logs_live_punch_unique');
            $table->dropColumn('live_key');
        });
    }
};
