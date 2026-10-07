<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave requests. status is a LeaveStatus value, half a LeaveHalf value
     * (null = whole days); a half day is always a single date. The approval
     * history lives in approval_steps (morph 'leave'); current_step is the
     * step waiting for a decision, null once there is none.
     */
    public function up(): void
    {
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('half')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index(['status', 'current_step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaves');
    }
};
