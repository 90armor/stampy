<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The approved leave covering this date (Phase 3d), set by
     * DailySummaryBuilder whatever the resulting status — off, holiday,
     * present and half-day days included — so a view can annotate any covered
     * day without a second query. Derived, like every other column here.
     */
    public function up(): void
    {
        Schema::table('daily_attendances', function (Blueprint $table) {
            $table->foreignId('leave_id')->nullable()->after('work_schedule_id')->constrained('leaves')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_id');
        });
    }
};
