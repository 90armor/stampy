<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 20;

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

    public function mount(): void
    {
        // Attendance visibility is gated the same as the employee directory
        // itself (admin|manager) — there's no dedicated attendance policy,
        // reusing Employee's viewAny keeps this consistent with route
        // middleware rather than inventing a second gate for the same rule.
        //
        // Phase 3: managers currently see every employee's attendance here,
        // same as EmployeePolicy::viewAny. Scoping this list down to a
        // manager's own subordinates (via Employee::manager_id) belongs here.
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

    public function toggleStatus(string $status): void
    {
        if (in_array($status, $this->statuses, true)) {
            $this->statuses = array_values(array_diff($this->statuses, [$status]));
        } else {
            $this->statuses[] = $status;
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
            );
    }

    /**
     * Powers the stat-card summary only. Deliberately scoped to the date
     * range alone — not employee search, department, or the status chips —
     * so the cards stay a stable "what does this date range look like"
     * overview (matching how Employees' Total/Active/Inactive cards ignore
     * that page's own search/filters) instead of vanishing whenever a chip
     * is toggled off. No join needed since nothing here touches employees.
     */
    private function summaryQuery(): Builder
    {
        return DailyAttendance::query()
            ->whereBetween('work_date', [$this->fromDate, $this->toDate]);
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
        $counts = $this->summaryQuery()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect([
            AttendanceStatus::Present,
            AttendanceStatus::Late,
            AttendanceStatus::Absent,
            AttendanceStatus::Incomplete,
        ])->mapWithKeys(fn (AttendanceStatus $status) => [
            $status->value => $counts->get($status->value, 0),
        ]);
    }

    public function render()
    {
        $attendances = $this->baseQuery()
            ->select('daily_attendances.*')
            ->with(['employee.department'])
            ->orderBy('daily_attendances.work_date', 'desc')
            ->orderBy('employees.full_name')
            ->paginate(self::PER_PAGE);

        $summary = $this->summary();

        return view('livewire.attendance.index', [
            'attendances' => $attendances,
            'departments' => Department::orderBy('name')->get(),
            'summary' => $summary,
            'maxBuiltDate' => DailyAttendance::max('work_date'),
            'allStatuses' => AttendanceStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Attendance']);
    }
}
