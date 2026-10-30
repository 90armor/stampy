<?php

use App\Http\Controllers\ProfileController;
use App\Livewire\Attendance\Index as AttendanceIndex;
use App\Livewire\Attendance\Show as AttendanceShow;
use App\Livewire\Employees\Index as EmployeesIndex;
use App\Livewire\Employees\Show as ShowEmployee;
use App\Livewire\Leave\Approvals;
use App\Livewire\Leave\TimeOff;
use App\Livewire\Overtime\Index as OvertimeIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Policies\PoliciesPolicy;
use App\Policies\ReportPolicy;
use App\Support\DashboardAttendance;
use App\Support\EmployeeDashboard;
use App\Support\EmployeeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    $stats = null;
    $attendance = null;

    if (auth()->user()->hasAnyRole(['admin', 'manager'])) {
        $user = auth()->user();

        // The shared scope rule: null = admin, no restriction; a manager gets
        // themself plus their reports; one with no linked employee record gets
        // an empty scope (every figure below reads all-zero, which is accurate —
        // they have no team to show attendance for). Total employees / new this
        // month use it too, same as the employee directory's own stats, so a
        // manager's dashboard agrees with the directory beside it.
        $employeeIds = EmployeeScope::for($user, 'Dashboard')->ids;

        $totalEmployees = Employee::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->count();

        $stats = [
            'total_employees' => $totalEmployees,
            'new_this_month' => Employee::query()
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
                ->whereMonth('join_date', now()->month)
                ->whereYear('join_date', now()->year)
                ->count(),
        ];

        // Employees active today (Employee::scopeActiveOn()) — the Department
        // card's "N / M", so M must match DashboardAttendance's own count.
        $departments = Department::withCount(['employees' => fn ($query) => $query
            ->activeOn(today())
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
            'live' => DashboardAttendance::liveToday($employeeIds),
            'needsAttention' => DashboardAttendance::needsAttention($employeeIds),
            'needsAttentionTotal' => DashboardAttendance::needsAttentionTotal($employeeIds),
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

    // Without the team figures: their own leave and attendance (Phase 3e),
    // for anyone with an employee record.
    $mine = $stats === null && auth()->user()->employee !== null
        ? EmployeeDashboard::for(auth()->user()->employee, auth()->user())
        : null;

    return view('dashboard', ['stats' => $stats, 'attendance' => $attendance, 'mine' => $mine]);
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // No role restriction: resolves the viewer's own linked employee record
    // (EmployeePolicy::view already allows anyone to view their own record
    // regardless of role). Row-level scoping, not route middleware, is what
    // keeps this from exposing anyone else's data.
    Route::get('/my-attendance', AttendanceShow::class)->name('attendance.mine');

    // Leave (Phase 3e). No role middleware: who may open each page is a
    // LeavePolicy ability (timeOff), checked in the component's mount() and
    // render(), and the same ability gates the sidebar item (timeOff,
    // decideAny).
    Route::get('/time-off', TimeOff::class)->name('time-off.index');
    Route::get('/approvals', Approvals::class)->name('approvals.index');
    Route::get('/overtime', OvertimeIndex::class)->name('overtime.index');

    // The rules attendance, leave and overtime are counted by (Phase 3e):
    // Schedules, Holidays, Leave types, Overtime. Its own ability (Phase 5a,
    // PoliciesPolicy) — any tab the user may see, each by what its component
    // checks — is the route's gate too, so the route can't drift from the
    // page; every tab is admin-only today. The page opens on the requested
    // tab if the user may see it, else on the first one they may.
    Route::get('/policies', function (Request $request) {
        $tabs = app(PoliciesPolicy::class)->tabs($request->user());
        $tab = array_key_exists((string) $request->query('tab'), $tabs) ? $request->query('tab') : array_key_first($tabs);

        return view('policies', ['tabs' => $tabs, 'tab' => $tab]);
    })->middleware('can:policies.view')->name('policies.index');

    // Reports (Phase 5b): one page, a tab per report — Attendance (admins,
    // and managers for their team), Overtime (admins). The same shape as
    // Policies: its own ability (reports.view, ReportPolicy) is the route's
    // gate, the page renders only the tabs the user may see, and each tab's
    // component checks its own ability again in mount(), render() and on
    // export.
    Route::get('/reports', function (Request $request) {
        $tabs = app(ReportPolicy::class)->tabs($request->user());
        $tab = array_key_exists((string) $request->query('tab'), $tabs) ? $request->query('tab') : array_key_first($tabs);

        return view('reports', ['tabs' => $tabs, 'tab' => $tab]);
    })->middleware('can:reports.view')->name('reports.index');

    // The overtime report's own page until Phase 5b: a bookmark lands on its
    // tab, keeping the month. Who may see that tab is the tab's business —
    // anyone else lands on their first tab, or is refused by /reports.
    Route::get('/reports/overtime', function (Request $request) {
        return redirect()->route('reports.index', array_filter([
            'tab' => 'overtime',
            'month' => $request->query('month'),
        ]));
    })->name('reports.overtime');
});

Route::middleware(['auth', 'role:admin|manager'])->group(function () {
    Route::get('/employees', EmployeesIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', ShowEmployee::class)->name('employees.show');
    Route::get('/attendance', AttendanceIndex::class)->name('attendance.index');
    Route::get('/attendance/{employee}', AttendanceShow::class)->name('attendance.show');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    // Structure only: Departments and Positions. Schedules and Holidays moved
    // to Policies (owner decision, after Phase 4): an old bookmark to their
    // Organization tab lands on the same tab there. Behind role:admin like
    // before, so anyone else following one is still forbidden, not redirected.
    Route::get('/organization', function (Request $request) {
        if (in_array($request->query('tab'), ['schedules', 'holidays'], true)) {
            return redirect()->route('policies.index', ['tab' => $request->query('tab')]);
        }

        return view('organization', ['tab' => $request->query('tab') === 'positions' ? 'positions' : 'departments']);
    })->name('organization.index');
});

require __DIR__.'/auth.php';

// An unmatched URL (Phase 6b): without a route the web group never runs, so
// no session starts and a signed-in user got the guest 404. As a fallback
// route it does, and errors/partials/page picks the app shell for them. A
// model that isn't found (/employees/99999) was already a 404 in the shell.
// Every method, not Route::fallback()'s GET only (which turned an unknown POST
// into a 405) — and since a catch-all for every method would otherwise also
// swallow a wrong method on a real URL, that case is still a 405.
Route::any('{fallbackPlaceholder}', function (Request $request) {
    $allowed = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => $route->isFallback)
        ->filter(fn ($route) => $route->matches($request, false))
        ->flatMap(fn ($route) => $route->methods())
        ->unique();

    abort_if($allowed->isNotEmpty(), 405, '', ['Allow' => $allowed->implode(', ')]);
    abort(404);
})->where('fallbackPlaceholder', '.*')->fallback();
