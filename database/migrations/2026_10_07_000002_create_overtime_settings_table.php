<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The overtime policy (Phase 4): one row. The defaults are the Cambodian
     * Labour Law and Prakas 112/25 until HR confirms the company's own:
     *
     * - one rate per category (workday 150%, night, rest day and holiday
     *   200%); every minute belongs to exactly one category, holiday > rest
     *   day > night > workday, so the rates must be ordered the same way;
     * - night hours 22:00–05:00;
     * - at most 2 hours of overtime and 10 hours of work a day;
     * - claims up to 7 days back;
     * - TOIL 1:1 (toil_ratio_percent 100), credited per full 240 minutes as
     *   half a day of the TOIL leave type.
     *
     * The row is inserted here, not by a seeder: production needs it and can't
     * run the demo seed. LeaveTypeSeeder points toil_leave_type_id at
     * "Time off in lieu" when it's empty. Model rules: OvertimeSettings.
     */
    public function up(): void
    {
        Schema::create('overtime_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('workday_rate_percent')->default(150);
            $table->unsignedSmallInteger('night_rate_percent')->default(200);
            $table->unsignedSmallInteger('rest_day_rate_percent')->default(200);
            $table->unsignedSmallInteger('holiday_rate_percent')->default(200);
            $table->time('night_starts')->default('22:00:00');
            $table->time('night_ends')->default('05:00:00');
            $table->unsignedSmallInteger('max_overtime_minutes_per_day')->default(120);
            $table->unsignedSmallInteger('max_work_minutes_per_day')->default(600);
            $table->unsignedSmallInteger('claim_window_days')->default(7);
            $table->unsignedSmallInteger('toil_ratio_percent')->default(100);
            $table->unsignedSmallInteger('toil_block_minutes')->default(240);
            $table->foreignId('toil_leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $table->timestamps();
        });

        DB::table('overtime_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_settings');
    }
};
