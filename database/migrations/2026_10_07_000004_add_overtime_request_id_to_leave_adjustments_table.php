<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A system-authored TOIL adjustment (Phase 4: created_by null) points at
     * the overtime request whose change triggered it — "triggered by", not
     * "earned from only this request": TOIL settlement reconciles the
     * employee's all-time total, so one block can be filled by several
     * requests. restrictOnDelete: the request is the adjustment's reason, and
     * adjustments are append-only.
     */
    public function up(): void
    {
        Schema::table('leave_adjustments', function (Blueprint $table) {
            $table->foreignId('overtime_request_id')->nullable()->after('note')->constrained('overtime_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overtime_request_id');
        });
    }
};
