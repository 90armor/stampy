<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime on a day (Phase 4): the approved request it was credited
     * against, and the credited minutes per category — every minute in
     * exactly one, holiday > rest day > night > workday (CLAUDE.md, Phase 4,
     * rule 3). Minutes, never rates or money: the rates are settings, applied
     * by the monthly report. Derived by DailySummaryBuilder like every other
     * column here; it writes them from Phase 4b on.
     */
    public function up(): void
    {
        Schema::table('daily_attendances', function (Blueprint $table) {
            $table->foreignId('overtime_request_id')->nullable()->after('leave_id')->constrained('overtime_requests')->nullOnDelete();
            $table->unsignedInteger('overtime_workday_minutes')->default(0)->after('early_leave_minutes');
            $table->unsignedInteger('overtime_night_minutes')->default(0)->after('overtime_workday_minutes');
            $table->unsignedInteger('overtime_rest_day_minutes')->default(0)->after('overtime_night_minutes');
            $table->unsignedInteger('overtime_holiday_minutes')->default(0)->after('overtime_rest_day_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overtime_request_id');
            $table->dropColumn(['overtime_workday_minutes', 'overtime_night_minutes', 'overtime_rest_day_minutes', 'overtime_holiday_minutes']);
        });
    }
};
