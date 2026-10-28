<?php

use App\Support\WorkScheduleBreakStartBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the schedule's break begins (Phase 3a) — the boundary between the
     * morning and afternoon halves that half-day leave will use: AM is
     * start_time–break_start, PM is break_start + break_minutes–end_time.
     * Nullable: a schedule without it can't take half-day leave.
     */
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->time('break_start')->nullable()->after('break_minutes');
        });

        WorkScheduleBreakStartBackfill::run();
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn('break_start');
        });
    }
};
