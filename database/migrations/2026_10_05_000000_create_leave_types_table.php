<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave policy per type (Phase 3). days_per_year null = no balance
     * (Unpaid, Maternity); counts is a LeaveCounting value; a type may deduct
     * from one other type (Special from Annual), one level only. Model rules:
     * LeaveType::booted().
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('days_per_year', 4, 1)->nullable();
            $table->unsignedSmallInteger('min_service_months')->nullable();
            $table->boolean('seniority_bonus')->default(false);
            $table->decimal('carry_over_cap', 4, 1)->nullable();
            $table->string('counts');
            $table->decimal('max_days_per_request', 4, 1)->nullable();
            $table->foreignId('deducts_from_leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $table->boolean('allows_half_day')->default(true);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
