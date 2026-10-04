<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One grant per employee, type and leave year (the calendar year). The
     * grant is a stored fact; usage and carry-over are derived from it.
     * granted_by null = granted automatically (leave:grant).
     */
    public function up(): void
    {
        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 4, 1);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
