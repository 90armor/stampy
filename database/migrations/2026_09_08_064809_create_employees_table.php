<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No work_schedule_id (Phase 2.5b replaced it with employee_work_schedules
     * — see Employee::scheduleOn() — before this ever reached a real
     * deployment, so there's no "column existed then got dropped" step to
     * preserve; EmployeeScheduleBackfill, which runs later when
     * employee_work_schedules is created, never reads this column anyway).
     * No softDeletes (employee lifecycle is handled entirely via `status`
     * — nothing ever soft-deleted an employee). `status` is indexed: it's
     * filtered on throughout the dashboard/list queries.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code')->unique();
            $table->string('full_name');
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->date('join_date');
            $table->string('device_user_id')->nullable()->unique();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
