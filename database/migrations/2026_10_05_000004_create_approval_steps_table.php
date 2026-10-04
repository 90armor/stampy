<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared half of the approval engine: one row per decided step of
     * any approvable request (leaves now, overtime_requests in Phase 4).
     * approvable_type is a morph-map alias ('leave'), not a class name.
     * outcome is an ApprovalOutcome value; decided_by null = an automatic
     * skip. Append-only (ApprovalStep::booted()).
     */
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->unsignedTinyInteger('step');
            $table->string('outcome');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['approvable_type', 'approvable_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
