<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('punched_at');
            $table->string('punch_type');
            $table->string('source')->default('device');
            $table->string('device_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('raw')->nullable();
            // attendance_logs is append-only, so a wrong punch (e.g. someone
            // else's finger matched) can't be edited — only excluded from
            // every query DailySummaryBuilder runs, while staying visible
            // (struck through, with who voided it) for troubleshooting.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Replaces the old (employee_id, punched_at) index — this composite
            // unique already serves those lookups via its leftmost columns, and
            // additionally enforces idempotency structurally: the same device
            // punch re-imported (or re-seeded) can't be stored twice.
            //
            // Deliberately does NOT include voided_at, even though a voided
            // row then permanently occupies its exact key (see
            // Attendance\Show::addPunch(), which revives rather than
            // re-inserts when that happens). Adding voided_at looks like the
            // obvious fix but is wrong on MySQL: a unique index treats every
            // NULL as distinct from every other NULL, so once voided_at sits
            // in the key, two LIVE rows (both voided_at IS NULL) at the same
            // employee_id/punched_at/source would no longer collide either —
            // silently reopening the exact duplicate-device-punch bug this
            // index exists to prevent. Confirmed empirically against MySQL,
            // not assumed.
            $table->unique(['employee_id', 'punched_at', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
