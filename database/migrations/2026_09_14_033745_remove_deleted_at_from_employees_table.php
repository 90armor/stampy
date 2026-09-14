<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Employee lifecycle is handled entirely via the `status` column
     * (active/inactive) — nothing ever soft-deleted an employee, so the
     * SoftDeletes trait and this column were dead weight.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
