<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the user last opened Time off (Phase 3e). A request decided after
     * it gets a "New" marker there — the only notification there is, since
     * many employees have no email.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('time_off_seen_at')->nullable()->after('temporary_password_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('time_off_seen_at');
        });
    }
};
