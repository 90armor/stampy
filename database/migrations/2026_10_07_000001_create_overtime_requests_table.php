<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime requests (Phase 4). date is the work date; starts_at/ends_at
     * the approved window, which may end after midnight. kind is an
     * OvertimeKind value, compensation an OvertimeCompensation value, status
     * an OvertimeStatus value. The approval history lives in approval_steps
     * (morph 'overtime'); current_step is the step waiting for a decision.
     * Window shape and status transitions: OvertimeRequest::booted().
     *
     * One non-cancelled, non-rejected request per employee per date is NOT an
     * index: MySQL has no partial unique index. The request service enforces
     * it under the employee row lock (Phase 4c), as leave overlap is.
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('kind');
            $table->string('compensation');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('limit_override_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date']);
            $table->index(['status', 'current_step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
