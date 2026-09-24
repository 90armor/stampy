<?php

use App\Support\EmployeeScheduleBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not nullOnDelete like employees.work_schedule_id
            // used to be: a schedule referenced by any assignment can't be
            // deleted at all (see CLAUDE.md) — there's no "assignment with no
            // schedule" state to fall back to.
            $table->foreignId('work_schedule_id')->constrained()->restrictOnDelete();
            // One row applies until the employee's next row begins — no
            // effective_to. Storing an end date makes overlaps and gaps
            // possible; deriving it (Employee::scheduleOn()) makes both
            // impossible.
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });

        // Every employee that already exists (an install upgrading from
        // before this table existed) needs one row backfilled — see
        // EmployeeScheduleBackfill's own doc comment for the exact rule.
        // On a fresh install (migrate:fresh, before seeding) there are no
        // employees yet, so this backfills nothing.
        EmployeeScheduleBackfill::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedules');
    }
};
