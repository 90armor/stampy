<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only admin corrections to a balance — signed days, a required
     * note. A wrong adjustment is fixed with a reversing one, never edited
     * or deleted (LeaveAdjustment::booted()).
     */
    public function up(): void
    {
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 1);
            $table->string('note');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
    }
};
