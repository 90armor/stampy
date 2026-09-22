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
use App\Support\EmployeeScope;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    $stats = null;
    $attendance = null;

    if (auth()->user()->hasAnyRole(['admin', 'manager'])) {
        $user = auth()->user();

        // Total employees / new this month stay company-wide headcount even for a
        // manager — unlike the employee directory and its own stats, which are
        // scoped to their team. Only the ATTENDANCE figures below get the
        // manager's own-team-only scope, matching Attendance\Index/Show.
        $totalEmployees = Employee::count();

        $stats = [
            'total_employees' => $totalEmployees,
            'new_this_month' => Employee::whereMonth('join_date', now()->month)
                ->whereYear('join_date', now()->year)
                ->count(),
        ];

        // The shared scope rule: null = admin, no restriction; a manager gets
        // themself plus their reports; one with no linked employee record gets
        // an empty scope (every figure below reads all-zero, which is accurate —
        // they have no team to show attendance for).
        $employeeIds = EmployeeScope::for($user, 'Dashboard')->ids;

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
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // No role restriction: resolves the viewer's own linked employee record
    // (EmployeePolicy::view already allows anyone to view their own record
    // regardless of role). Row-level scoping, not route middleware, is what
    // keeps this from exposing anyone else's data.
    Route::get('/my-attendance', AttendanceShow::class)->name('attendance.mine');
});

Route::middleware(['auth', 'role:admin|manager'])->group(function () {
    Route::get('/employees', EmployeesIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', ShowEmployee::class)->name('employees.show');
    Route::get('/attendance', AttendanceIndex::class)->name('attendance.index');
    Route::get('/attendance/{employee}', AttendanceShow::class)->name('attendance.show');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/organization', function () {
        return view('organization');
    })->name('organization.index');
});

require __DIR__.'/auth.php';
