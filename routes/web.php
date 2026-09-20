<?php

use App\Http\Controllers\ProfileController;
use App\Livewire\Attendance\Index as AttendanceIndex;
use App\Livewire\Attendance\Show as AttendanceShow;
use App\Livewire\Employees\Index as EmployeesIndex;
use App\Livewire\Employees\Show as ShowEmployee;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\DashboardAttendance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    $stats = null;
    $attendance = null;

    if (auth()->user()->hasAnyRole(['admin', 'manager'])) {
        $user = auth()->user();

        // Total employees / new this month are organisational headcount
        // facts, not attendance records — the employee directory itself
        // (Employees\Index) is fully visible to both admin and manager with
        // no row-level scoping, so these stay unscoped too. Only the
        // ATTENDANCE figures below get the manager's own-team-only scope,
        // matching Attendance\Index/Show's row-level rule (attendance
        // records are the sensitive, team-specific data here, not the
        // directory).
        $totalEmployees = Employee::count();

        $stats = [
            'total_employees' => $totalEmployees,
            'new_this_month' => Employee::whereMonth('join_date', now()->month)
                ->whereYear('join_date', now()->year)
                ->count(),
        ];

        // null = admin, no restriction. A manager with no linked employee
        // record gets an empty scope (matching Attendance\Index's own
        // handling of that edge case) rather than an error — every figure
        // below just reads as all-zero/empty, which is accurate: they have
        // no team to show attendance for.
        $employeeIds = null;

        if ($user->hasRole('manager')) {
            $employee = $user->employee;

            if ($employee === null) {
                Log::warning('Dashboard viewed by a manager with no linked employee record — showing an empty scope.', [
                    'user_id' => $user->id,
                ]);
            }

            $employeeIds = $employee ? [$employee->id, ...$employee->subordinateIds()] : [];
        }

        $departments = Department::withCount(['employees' => fn ($query) => $query
            ->where('status', 'active')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds)),
        ])
            ->orderBy('name')
            ->get()
            // Not worth showing a manager a department they have no one in —
            // an admin's unrestricted scope never filters anything out here.
            ->filter(fn (Department $department) => $department->employees_count > 0)
            ->values();

        $attendance = [
            'today' => DashboardAttendance::todayBreakdown($employeeIds),
            'needsAttention' => DashboardAttendance::needsAttention($employeeIds),
            'trend' => DashboardAttendance::weeklyTrend($employeeIds),
            'departments' => DashboardAttendance::departmentAttendance($departments, $employeeIds),
            'recent' => DashboardAttendance::recentActivity($employeeIds),
            'onboarding' => [
                'departments' => Department::count() > 0,
                'positions' => Position::count() > 0,
                'employees' => $totalEmployees > 0,
            ],
        ];
    }

    return view('dashboard', ['stats' => $stats, 'attendance' => $attendance]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // No role restriction: resolves the viewer's own linked employee record
    // (EmployeePolicy::view already allows anyone to view their own record
    // regardless of role). Row-level scoping, not route middleware, is what
    // keeps this from exposing anyone else's data.
    Route::get('/my-attendance', AttendanceShow::class)->name('attendance.mine');
});

Route::middleware(['auth', 'verified', 'role:admin|manager'])->group(function () {
    Route::get('/employees', EmployeesIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', ShowEmployee::class)->name('employees.show');
    Route::get('/attendance', AttendanceIndex::class)->name('attendance.index');
    Route::get('/attendance/{employee}', AttendanceShow::class)->name('attendance.show');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/organization', function () {
        return view('organization');
    })->name('organization.index');
});

require __DIR__.'/auth.php';
