<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attributes\QueryParam;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Support\AttendanceGaps;
use App\Support\DashboardAttendance;
use App\Support\EmployeeScope;
use App\Support\ListUrl;
use App\Support\StrictDate;
use App\Support\WorkdayCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    #[QueryParam(as: 'from', history: true)]
    public string $fromDate = '';

    #[QueryParam(as: 'to', history: true)]
    public string $toDate = '';

    #[QueryParam(as: 'q', history: true)]
    public string $employeeFilter = '';

    #[QueryParam(as: 'department', history: true)]
    public string $departmentFilter = '';

    /** @var string[] */
    #[QueryParam(as: 'status', history: true)]
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
    #[QueryParam(as: 'timing', history: true)]
    public array $timingFilters = [];

    /**
     * Only days worked on approved leave (DailyAttendance::workedOnLeave()) —
     * how an admin finds past cases, now that the dashboard's Needs attention
     * lists only today's (Phase 3e). Neither a status nor timing: its own
     * filter.
     */
    #[QueryParam(as: 'worked-on-leave', history: true)]
    public bool $workedOnLeave = false;

    /**
     * Only days with credited overtime (Phase 4d) — an annotation, so its own
     * filter, like Worked on leave.
     */
    #[QueryParam(as: 'overtime', history: true)]
    public bool $overtime = false;

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

        $this->normalizeFilters();
    }

    /**
     * Whatever the URL or a request carried, keep only what the filters know
     * (Phase 6h; #[QueryParam] already fitted each value to its type): a real
     * date, else today; known statuses and timings; a department id. A filter
     * left with nothing goes back to its default.
     */
    public function updated(): void
    {
        $this->normalizeFilters();
    }

    private function normalizeFilters(): void
    {
        if (! StrictDate::isDay($this->fromDate)) {
            $this->fromDate = today()->format('Y-m-d');
        }

        if (! StrictDate::isDay($this->toDate)) {
            $this->toDate = today()->format('Y-m-d');
        }

        if (! ctype_digit($this->departmentFilter)) {
            $this->departmentFilter = '';
        }

        $this->statuses = array_values(array_intersect($this->statuses, array_column(AttendanceStatus::cases(), 'value')));
        $this->timingFilters = array_values(array_intersect($this->timingFilters, ['late', 'early']));

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

    /**
     * The status chips narrow; they don't enumerate. The stored $statuses set
     * (and the query and URL built from it) is unchanged — the default is
     * still every status except Off — but the chips present it so that the
     * default reads as "no filter applied":
     *
     * - The working-status chips (everything but Off) show as unselected
     *   while ALL of them are in the set. Clicking one from there narrows the
     *   set to just that status; clicking further chips adds or removes
     *   them; removing the last one returns to "all working statuses".
     * - Off is a separate "Show off days" toggle that adds or removes 'off'
     *   independently of the working-status selection.
     */
    public function toggleStatus(string $status): void
    {
        $off = AttendanceStatus::Off->value;
        $includesOff = in_array($off, $this->statuses, true);

        if ($status === $off) {
            $includesOff = ! $includesOff;
            $working = array_values(array_diff($this->statuses, [$off]));
        } else {
            $allWorking = $this->defaultStatuses();
            $working = array_values(array_intersect($this->statuses, $allWorking));

            if (count($working) === count($allWorking)) {
                $working = [$status];
            } elseif (in_array($status, $working, true)) {
                $working = array_values(array_diff($working, [$status]));
            } else {
                $working[] = $status;
            }

            if ($working === []) {
                $working = $allWorking;
            }
        }

        $this->statuses = $includesOff ? [...$working, $off] : $working;

        $this->resetPage();
    }

    public function toggleWorkedOnLeave(): void
    {
        $this->workedOnLeave = ! $this->workedOnLeave;
        $this->resetPage();
    }

    public function toggleOvertime(): void
    {
        $this->overtime = ! $this->overtime;
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

    /**
     * The date picker's quick ranges, in display order: label and the
     * from/to dates (Y-m-d) each one sets. One definition for setRange() and
     * for the picker, which shows a preset as selected while the applied or
     * pending range equals it.
     *
     * @return array<string, array{label: string, from: string, to: string}>
     */
    public function presetRanges(): array
    {
        $today = today();
        $lastMonth = $today->copy()->startOfMonth()->subMonthNoOverflow();

        $ranges = [
            'today' => ['Today', $today, $today],
            'yesterday' => ['Yesterday', $today->copy()->subDay(), $today->copy()->subDay()],
            'last7' => ['Last 7 days', $today->copy()->subDays(6), $today],
            'last30' => ['Last 30 days', $today->copy()->subDays(29), $today],
            'thisMonth' => ['This month', $today->copy()->startOfMonth(), $today],
            'lastMonth' => ['Last month', $lastMonth, $lastMonth->copy()->endOfMonth()],
        ];

        return array_map(fn (array $range) => [
            'label' => $range[0],
            'from' => $range[1]->format('Y-m-d'),
            'to' => $range[2]->format('Y-m-d'),
        ], $ranges);
    }

    public function setRange(string $preset): void
    {
        $range = $this->presetRanges()[$preset] ?? $this->presetRanges()['today'];

        $this->fromDate = $range['from'];
        $this->toDate = $range['to'];

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
        $this->workedOnLeave = false;
        $this->overtime = false;
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
     * Whose missing days the "hasn't been calculated" notice names: the
     * people the current filters could show — scope, search and department —
     * so a search for a few people never warns about someone it hides. The
     * row chips don't narrow it: a missing workday has no status or timing
     * yet and could become any of them, except Off and Holiday, which a
     * workday never is — so a selection of only those gets no notice.
     *
     * @return int[]|null null = everyone
     */
    private function gapEmployeeIds(): ?array
    {
        $statuses = $this->statuses !== [] ? $this->statuses : $this->defaultStatuses();

        if (array_diff($statuses, [AttendanceStatus::Off->value, AttendanceStatus::Holiday->value]) === []) {
            return [];
        }

        if ($this->employeeFilter === '' && $this->departmentFilter === '') {
            return $this->scopedEmployeeIds();
        }

        return Employee::query()
            ->when($this->scopedEmployeeIds() !== null, fn (Builder $query) => $query->whereIn('id', $this->scopedEmployeeIds() ?? []))
            ->when($this->employeeFilter, fn (Builder $query) => $query->where(function (Builder $query) {
                $query->where('full_name', 'like', "%{$this->employeeFilter}%")
                    ->orWhere('employee_code', 'like', "%{$this->employeeFilter}%");
            }))
            ->when($this->departmentFilter, fn (Builder $query) => $query->where('department_id', $this->departmentFilter))
            ->pluck('id')
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
            )
            ->when(
                $this->workedOnLeave,
                fn (Builder $query) => $query->whereIn('daily_attendances.id', $this->workedOnLeaveIds())
            )
            ->when(
                $this->overtime,
                fn (Builder $query) => $query->whereRaw('(daily_attendances.overtime_workday_minutes + daily_attendances.overtime_night_minutes + daily_attendances.overtime_rest_day_minutes + daily_attendances.overtime_holiday_minutes) > 0')
            );
    }

    /**
     * The rows in range (and scope) that were worked on approved leave,
     * decided by DailyAttendance::workedOnLeave() itself rather than a
     * second definition in SQL. SQL only narrows to the candidates — a row
     * with a leave and a punch — which are few.
     *
     * @return list<int>
     */
    private function workedOnLeaveIds(): array
    {
        $candidates = DailyAttendance::query()
            ->whereBetween('work_date', [$this->fromDate, $this->toDate])
            ->when($this->scopedEmployeeIds() !== null, fn (Builder $query) => $query->whereIn('employee_id', $this->scopedEmployeeIds() ?? []))
            ->whereNotNull('leave_id')
            ->where(fn (Builder $query) => $query->whereNotNull('first_in')->orWhereNotNull('last_out'))
            ->with('workSchedule')
            ->get();
        DailyAttendance::withLeaveDays($candidates);

        return $candidates->filter(fn (DailyAttendance $row) => $row->workedOnLeave())->pluck('id')->values()->all();
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
        // CLAUDE.md's "Status vs. timing" note). Since Phase 2.6 late can
        // also sit on in_progress and incomplete rows, so the late count is
        // restricted to Present rows to stay a breakdown OF Present; the
        // "Late arrival" filter itself returns late rows of every status.
        // For Present rows the two agree — covered by a test.
        $lateCount = (clone $query)->where('status', AttendanceStatus::Present->value)->where('late_minutes', '>', 0)->count();
        $earlyCount = (clone $query)->where('early_leave_minutes', '>', 0)->count();
        // Late annotates its own status group: incomplete days with a late
        // in-punch are counted under Incomplete, not folded into Present.
        $incompleteLateCount = (clone $query)->where('status', AttendanceStatus::Incomplete->value)->where('late_minutes', '>', 0)->count();

        return collect([
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            'late' => $lateCount,
            'early' => $earlyCount,
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
            'incomplete_late' => $incompleteLateCount,
        ]);
    }

    /**
     * The list as the user has it now, for a detail page's way back
     * (ListUrl, Phase 6i): every filter that isn't at its default, and the
     * page. A range of exactly today is the default, so going back the next
     * day shows that day, not a stale one.
     *
     * @return array<string, string|array<int, string>>
     */
    private function listQuery(): array
    {
        $today = today()->format('Y-m-d');
        $todayOnly = $this->fromDate === $today && $this->toDate === $today;
        $statuses = $this->statuses;
        $defaults = $this->defaultStatuses();
        sort($statuses);
        sort($defaults);

        return [
            'from' => $todayOnly ? '' : $this->fromDate,
            'to' => $todayOnly ? '' : $this->toDate,
            'q' => $this->employeeFilter,
            'department' => $this->departmentFilter,
            'status' => $statuses === $defaults ? [] : $this->statuses,
            'timing' => $this->timingFilters,
            'worked-on-leave' => $this->workedOnLeave ? '1' : '',
            'overtime' => $this->overtime ? '1' : '',
            'page' => $this->getPage() > 1 ? (string) $this->getPage() : '',
        ];
    }

    public function render()
    {
        ListUrl::remember('attendance.index', $this->listQuery());

        $scopedIds = $this->scopedEmployeeIds();

        $attendances = $this->baseQuery()
            ->select('daily_attendances.*')
            ->with(['employee.department', 'workSchedule'])
            ->orderBy('daily_attendances.work_date', 'desc')
            ->orderBy('employees.full_name')
            ->paginate(self::PER_PAGE);
        // The page's leave annotations read leaveDay(): one query for the page.
        DailyAttendance::withLeaveDays($attendances->getCollection());

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
            // A range of exactly today shows the live "who is here now"
            // strip instead of end-of-day status counts.
            'live' => $this->fromDate === $this->toDate && $this->fromDate === today()->format('Y-m-d')
                ? DashboardAttendance::liveToday($this->scopedEmployeeIds())
                : null,
            // F07's rule (Phase 6e follow-up): only past workdays in the range,
            // for the people in scope, that have no row — never the future.
            'missingDates' => AttendanceGaps::missingDates($this->gapEmployeeIds(), Carbon::parse($this->fromDate), Carbon::parse($this->toDate)),
            'allStatuses' => AttendanceStatus::cases(),
            // Holiday names for the annotation, from the builder's own lookup.
            'holidays' => WorkdayCalendar::holidayNamesBetween(Carbon::parse($this->fromDate), Carbon::parse($this->toDate)),
            'presetRanges' => $this->presetRanges(),
            'scopeHasNoEmployeeRecord' => $this->scope()->hasNoEmployeeRecord,
        ])->layout('layouts.app', ['header' => 'Attendance']);
    }
}
