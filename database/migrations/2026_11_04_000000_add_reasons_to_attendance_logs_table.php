<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why an admin corrected a day's punches (Phase 6g, F34): `reason` for a
     * manual punch they added, `void_reason` for a punch they voided — two
     * columns, since a manual punch can be added and later voided, each with
     * its own reason. Required by validation for every new correction;
     * nullable because corrections made before this have none (the punch
     * list shows "—"), and device punches never have one.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('reason', 200)->nullable()->after('created_by');
            $table->string('void_reason', 200)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['reason', 'void_reason']);
        });
    }
};
