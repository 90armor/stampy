<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plain B-tree index only — it speeds up the `status = 'active'` filters
     * used throughout the dashboard/list queries. It does NOT help the
     * employee list's `LIKE '%term%'` search on full_name/employee_code
     * (leading wildcard, so a full-text index would be the real fix there,
     * not a plain index). Not added here since current scale doesn't need it.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
