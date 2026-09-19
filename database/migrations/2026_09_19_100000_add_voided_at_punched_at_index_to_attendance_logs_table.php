<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The dashboard's admin "recent activity" query is
     * `WHERE voided_at IS NULL ORDER BY punched_at DESC LIMIT n` with no
     * employee_id filter, which the (employee_id, punched_at, source) unique
     * index can't serve — its leading column is unconstrained — so it was a
     * full table scan plus filesort on every dashboard load. A plain
     * (non-unique) index; the uniqueness guarantee stays on the existing one.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index(['voided_at', 'punched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['voided_at', 'punched_at']);
        });
    }
};
