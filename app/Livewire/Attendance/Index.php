<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 20;

    /**
     * Resolved lazily the first time it's needed within a request and
     * reused by baseQuery(), summaryQuery(), and the department dropdown —
     * cheap either way (one BFS over a small org tree, not per attendance
     * row), but no reason to walk it twice in the same render().
     *
     * @var int[]|null
     */
    private ?EmployeeScope $scope = null;

    #[Url(as: 'from', history: true)]
    public string $fromDate = '';

    #[Url(as: 'to', history: true)]
    public string $toDate = '';

    #[Url(as: 'q', history: true)]
    public string $employeeFilter = '';

    #[Url(as: 'department', history: true)]
    public string $departmentFilter = '';

    /** @var string[] */
    #[Url(as: 'status', history: true)]
    public array $statuses = [];

    /**
     * Independent of $statuses — timing (late arrival / early departure)
     * isn't a status (see AttendanceStatus's doc comment), so it gets its
     * own filter rather than being folded into the status chips. Values are
     * 'late'/'early'; empty means unrestricted, matching $employeeFilter/
     * $departmentFilter's convention (only $statuses defaults to a
     * non-empty set, to hide Off by default).
     *
     * @var string[]
     */
    #[Url(as: 'timing', history: true)]
    public array $timingFilters = [];

    public function mount(): void
    {
        // Attendance visibility is gated the same as the employee directory
        // itself (admin|manager) — there's no dedicated attendance policy,
        // reusing Employee's viewAny keeps this consistent with route
        // middleware rather than inventing a second gate for the same rule.
        // viewAny only decides whether this page is reachable at all; which
        // rows it shows is narrowed separately by scopedEmployeeIds(),
        // mirroring EmployeePolicy::view's row-level rule.
        $this->authorize('viewAny', Employee::class);

        if ($this->fromDate === '') {
            $this->fromDate = today()->format('Y-m-d');
        }

        if ($this->toDate === '') {
            $this->toDate = today()->format('Y-m-d');
        }

        if ($this->statuses === []) {
            $this->statuses = $this->defaultStatuses();
        }
    }

    public function updatingFromDate(): void
    {
        $this->resetPage();
    }

    public function updatingToDate(): void
    {
        $this->resetPage();
    }

    public function updatingEmployeeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatuses(): void
    {
        $this->resetPage();
    }

    public function updatingTimingFilters(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(string $status): void
    {
        if (in_array($status, $this->statuses, true)) {
            $this->statuses = array_values(array_diff($this->statuses, [$status]));
        } else {
            $this->statuses[] = $status;
        }

        $this->resetPage();
    }

    public function toggleTimingFilter(string $timing): void
    {
        if (in_array($timing, $this->timingFilters, true)) {
            $this->timingFilters = array_values(array_diff($this->timingFilters, [$timing]));
        } else {
            $this->timingFilters[] = $timing;
        }

        $this->resetPage();
    }

    public function setRange(string $preset): void
    {
        $today = today();

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last7' => [$today->copy()->subDays(6), $today],
            'last30' => [$today->copy()->subDays(29), $today],
            'thisMonth' => [$today->copy()->startOfMonth(), $today],
            default => [$today, $today],
        };

        $this->fromDate = $from->format('Y-m-d');
        $this->toDate = $to->format('Y-m-d');

        // Setting these properties directly (not via wire:model) doesn't
        // trigger updatingFromDate()/updatingToDate(), so reset explicitly.
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->fromDate = today()->format('Y-m-d');
        $this->toDate = today()->format('Y-m-d');
        $this->employeeFilter = '';
        $this->departmentFilter = '';
        $this->statuses = $this->defaultStatuses();
        $this->timingFilters = [];
        $this->resetPage();
    }

    /**
     * @return string[]
     */
    private function defaultStatuses(): array
    {
        return collect(AttendanceStatus::cases())
            ->reject(fn (AttendanceStatus $status) => $status === AttendanceStatus::Off)
            ->map(fn (AttendanceStatus $status) => $status->value)
            ->values()
            ->all();
    }

    /**
     * Row-level scope for the acting user — the shared EmployeeScope rule,
     * resolved once per request: baseQuery(), summaryQuery(), and the
     * department dropdown in render() all call this, and memoizing means the
     * subordinate BFS (one query per org-tree level, not per row) only runs
     * once. A user with no linked employee record gets an empty scope and the
     * view shows an explicit message instead of a plain "no results".
     */
    private function scope(): EmployeeScope
    {
        return $this->scope ??= EmployeeScope::for(auth()->user(), 'Attendance list');
    }

    /**
     * @return int[]|null null = no restriction
     */
    private function scopedEmployeeIds(): ?array
    {
        return $this->scope()->ids;
    }

    /**
     * Powers the paginated table only. Joins (not whereHas) because the
     * default sort crosses the employee relation — whereHas can filter that
     * but can't drive an ORDER BY.
     */
    private function baseQuery(): Builder
    {
        return DailyAttendance::query()
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->whereBetween('daily_attendances.work_date', [$this->fromDate, $this->toDate])
            ->when(
                $this->scopedEmployeeIds() !== null,
                fn (Builder $query) => $query->whereIn('daily_attendances.employee_id', $this->scopedEmployeeIds() ?? [])
            )
            ->when(
                $this->employeeFilter,
                fn (Builder $query) => $query->where(function (Builder $query) {
                    $query->where('employees.full_name', 'like', "%{$this->employeeFilter}%")
                        ->orWhere('employees.employee_code', 'like', "%{$this->employeeFilter}%");
                })
            )
            ->when(
                $this->departmentFilter,
                fn (Builder $query) => $query->where('employees.department_id', $this->departmentFilter)
            )
            ->when(
                $this->statuses !== [],
                fn (Builder $query) => $query->whereIn('daily_attendances.status', $this->statuses)
            )
            ->when(
                $this->timingFilters !== [],
                // OR across selected timing chips (late OR early), matching
                // how the status chips above combine via whereIn — "any of
                // the selected chips", not "all of them".
                fn (Builder $query) => $query->where(function (Builder $query) {
                    if (in_array('late', $this->timingFilters, true)) {
                        $query->orWhere('daily_attendances.late_minutes', '>', 0);
                    }

                    if (in_array('early', $this->timingFilters, true)) {
                        $query->orWhere('daily_attendances.early_leave_minutes', '>', 0);
                    }
                })
            );
    }

    /**
     * Powers the stat-card summary only. Deliberately scoped to the date
     * range alone — not employee search, department, or the status chips —
     * so the cards stay a stable "what does this date range look like"
     * overview (matching how Employees' Total/Active/Inactive cards ignore
     * that page's own search/filters) instead of vanishing whenever a chip
     * is toggled off. No join needed since nothing here touches employees.
     *
     * Row-level scoping is NOT one of the filters this deliberately ignores
     * — it's access control, not a user-adjustable filter, so it applies
     * here the same as it does to baseQuery().
     */
    private function summaryQuery(): Builder
    {
        return DailyAttendance::query()
            ->whereBetween('work_date', [$this->fromDate, $this->toDate])
            ->when(
                $this->scopedEmployeeIds() !== null,
                fn (Builder $query) => $query->whereIn('employee_id', $this->scopedEmployeeIds() ?? [])
            );
    }

    /**
     * A fixed, always-shown set of the actionable statuses — Off/Holiday/
     * Leave are passive/expected states (and Holiday/Leave are always zero
     * this phase, nothing assigns them yet), so they're left out of the
     * stat cards entirely rather than showing as a "0" card no one needs.
     * They're still available as status-filter chips for the table.
     *
     * Every key is always present (defaulting to 0) so the cards never
     * appear/disappear based on whether a status happens to have rows —
     * same as Employees' Total/Active/Inactive, which always render all
     * three regardless of the current search/filter.
     *
     * @return Collection<string, int>
     */
    private function summary(): Collection
    {
        $query = $this->summaryQuery();

        $counts = (clone $query)
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // 'late'/'early' aren't AttendanceStatus values (timing isn't a
        // status — see its doc comment), so they're counted from late_/
        // early_leave_minutes directly, the same way DailyAttendance's
        // isLate()/leftEarly() would per-row. A late/early day is already
        // counted in 'present' above — these two are a breakdown of it, not
        // additional rows — which is why the view renders them as a
        // sub-line under the Present tile rather than as peer tiles (see
        // CLAUDE.md's "Status vs. timing" note). This must stay in sync
        // with what the list's timing filter itself returns — covered by a
        // test asserting the two agree.
        $lateCount = (clone $query)->where('late_minutes', '>', 0)->count();
        $earlyCount = (clone $query)->where('early_leave_minutes', '>', 0)->count();

        return collect([
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            'late' => $lateCount,
            'early' => $earlyCount,
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
        ]);
    }

    public function render()
    {
        $scopedIds = $this->scopedEmployeeIds();

        $attendances = $this->baseQuery()
            ->select('daily_attendances.*')
            ->with(['employee.department'])
            ->orderBy('daily_attendances.work_date', 'desc')
            ->orderBy('employees.full_name')
            ->paginate(self::PER_PAGE);

        $summary = $this->summary();

        $departments = Department::query()
            ->when(
                $scopedIds !== null,
                fn (Builder $query) => $query->whereHas('employees', fn (Builder $q) => $q->whereIn('id', $scopedIds ?? []))
            )
            ->orderBy('name')
            ->get();

        return view('livewire.attendance.index', [
            'attendances' => $attendances,
            'departments' => $departments,
            'summary' => $summary,
            'maxBuiltDate' => DailyAttendance::max('work_date'),
            'allStatuses' => AttendanceStatus::cases(),
            'scopeHasNoEmployeeRecord' => $this->scope()->hasNoEmployeeRecord,
        ])->layout('layouts.app', ['header' => 'Attendance']);
    }
}
