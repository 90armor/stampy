<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The weekly rest day (Phase 4, owner): the one weekday whose overtime is
     * the rest_day category (200%) — an ISO weekday, 7 = Sunday. Any other
     * non-workday (Saturday) has no normal window but earns workday (or
     * night) minutes. Editable; a change applies to days built afterwards.
     */
    public function up(): void
    {
        Schema::table('overtime_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_rest_day')->default(7)->after('night_ends');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_settings', function (Blueprint $table) {
            $table->dropColumn('weekly_rest_day');
        });
    }
};
