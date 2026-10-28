<?php

use App\Support\LeaveBalanceSourceBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a type's balance comes from (Phase 4a, LeaveBalanceSource):
     * yearly, earned (adjustments only — Time off in lieu) or none. Until now
     * "has a balance" was implied by days_per_year being set, which can't
     * describe a balance with no yearly grant. Backfilled from exactly that
     * rule (LeaveBalanceSourceBackfill), so no existing type changes meaning.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('balance_source')->default('yearly')->after('name');
        });

        LeaveBalanceSourceBackfill::run();
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('balance_source');
        });
    }
};
