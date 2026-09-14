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
            $table->timestamps();

            // Replaces the old (employee_id, punched_at) index — this composite
            // unique already serves those lookups via its leftmost columns, and
            // additionally enforces idempotency structurally: the same device
            // punch re-imported (or re-seeded) can't be stored twice.
            $table->unique(['employee_id', 'punched_at', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
