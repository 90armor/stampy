<?php

use App\Http\Controllers\ProfileController;
use App\Livewire\Employees\Index as EmployeesIndex;
use App\Livewire\Employees\Show as ShowEmployee;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\DemoAttendance;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    $stats = null;
    $attendance = null;

    if (auth()->user()->hasAnyRole(['admin', 'manager'])) {
        $totalEmployees = Employee::count();

        // Inactive employees are still on the books but don't attend, so every
        // attendance figure below (today's breakdown, needsAttention,
        // recentActivity, department %) is scoped to the active workforce only —
        // deactivating someone must never move the Present-today percentage.
        $activeEmployees = Employee::where('status', 'active')->count();

        $stats = [
            'total_employees' => $totalEmployees,
            'new_this_month' => Employee::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        // Attendance figures are demo data until a real Attendance model /
        // device-punch pipeline exists — see App\Support\DemoAttendance.
        // Both needsAttention and recentActivity read from the same status
        // assignment below so they can't contradict each other (e.g. an
        // employee can't be "absent" and "checked in" at once).
        $todayBreakdown = DemoAttendance::todayBreakdown($activeEmployees);
        $assignedStatuses = DemoAttendance::assignStatuses(
            Employee::where('status', 'active')->orderBy('id')->get(),
            $todayBreakdown
        );

        $attendance = [
            'today' => $todayBreakdown,
            'needsAttention' => DemoAttendance::needsAttention($assignedStatuses),
            'trend' => DemoAttendance::weeklyTrend(),
            'departments' => DemoAttendance::departmentAttendance(
                Department::withCount(['employees' => fn ($query) => $query->where('status', 'active')])
                    ->orderBy('name')
                    ->get()
            ),
            'recent' => DemoAttendance::recentActivity($assignedStatuses),
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
});

Route::middleware(['auth', 'verified', 'role:admin|manager'])->group(function () {
    Route::get('/employees', EmployeesIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', ShowEmployee::class)->name('employees.show');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/organization', function () {
        return view('organization');
    })->name('organization.index');
});

require __DIR__.'/auth.php';
