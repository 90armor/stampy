<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the user last opened the Overtime page (Phase 4d) — overtime
     * requests decided after it are "New" (RequestDecisions), as
     * time_off_seen_at does for leave. Its own column: opening Time off
     * mustn't mark overtime decisions seen.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('overtime_seen_at')->nullable()->after('time_off_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('overtime_seen_at');
        });
    }
};
