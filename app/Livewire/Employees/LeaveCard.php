<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use App\Support\LeaveDays;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The employee profile's Leave card (Phase 3e): balances for a chosen year
 * (the same table as Time off), every request with its step history, and
 * for an admin the balance adjustments and "File leave" — the request modal
 * opened with this employee preselected and locked. A manager sees it read
 * only. Every figure comes from the leave services; nothing is re-derived.
 *
 * Its own Livewire component, nested in Employees\Show, so it authorizes in
 * mount(), in each action and in render() (CLAUDE.md, Authorization).
 */
class LeaveCard extends Component
{
    public Employee $employee;

    public int $year;

    public bool $showAdjustment = false;

    public string $adj_leave_type_id = '';

    public string $adj_year = '';

    /** Signed: "2" adds, "-1.5" takes away. */
    public string $adj_days = '';

    public string $adj_note = '';

    public ?string $notice = null;

    public ?string $warning = null;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employee = $employee;
        $this->year = today()->year;
    }

    public function openAdjustment(): void
    {
        $this->authorize('adjust', Leave::class);

        $this->reset(['adj_leave_type_id', 'adj_days', 'adj_note']);
        $this->adj_year = (string) $this->year;
        $this->resetErrorBag();
        $this->showAdjustment = true;
    }

    public function saveAdjustment(): void
    {
        $this->authorize('adjust', Leave::class);

        $this->validate([
            'adj_leave_type_id' => ['required', Rule::exists('leave_types', 'id')->whereNotNull('days_per_year')],
            'adj_year' => ['required', Rule::in(array_map('strval', $this->years()))],
            'adj_days' => ['required', 'numeric', 'decimal:0,1', 'between:-365,365', 'not_in:0,0.0,-0,-0.0'],
            'adj_note' => ['required', 'string', 'max:255'],
        ], [
            'adj_leave_type_id.required' => 'Choose the balance to adjust.',
            'adj_days.not_in' => 'An adjustment of 0 changes nothing.',
            'adj_days.decimal' => 'Use whole or tenths of a day, such as 2 or -0.5.',
            'adj_note.required' => 'Say why — the note is the record of this correction.',
        ]);

        $adjustment = LeaveAdjustment::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => (int) $this->adj_leave_type_id,
            'year' => (int) $this->adj_year,
            'days' => LeaveDays::toDecimal(LeaveDays::fromDecimal(self::normalized($this->adj_days))),
            'note' => trim($this->adj_note),
            'created_by' => auth()->id(),
        ]);

        $this->notice = 'Adjusted '.$adjustment->leaveType->name.' '.$adjustment->year.' by '.self::signed($adjustment->days).'.';
        $this->showAdjustment = false;
    }

    #[On('leave-saved')]
    public function leaveSaved(string $message = '', ?string $rebuildError = null): void
    {
        $this->notice = $message !== '' ? $message : null;
        $this->warning = $rebuildError;
    }

    /** What the validator accepts as numeric, in LeaveDays' form: "+2" → "2", ".5" → "0.5", "-.5" → "-0.5". */
    private static function normalized(string $days): string
    {
        $days = ltrim(trim($days), '+');

        return preg_replace('/^(-?)\./', '${1}0.', $days);
    }

    /** "+2 days", "−0.5 day". */
    public static function signed(string $decimal): string
    {
        $tenths = LeaveDays::fromDecimal($decimal);

        return ($tenths < 0 ? '−' : '+').LeaveDays::label(abs($tenths));
    }

    /**
     * Years with a grant or an adjustment, the current one, and next year
     * once granted — newest first.
     *
     * @return list<int>
     */
    private function years(): array
    {
        return collect([today()->year])
            ->merge(LeaveEntitlement::query()->where('employee_id', $this->employee->id)->pluck('year'))
            ->merge(LeaveAdjustment::query()->where('employee_id', $this->employee->id)->pluck('year'))
            ->map(fn ($year) => (int) $year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public function render(EntitlementCalculator $calculator, LeaveBalance $balances, LeaveDayCounter $counter)
    {
        // Every request ends here — re-assert access (CLAUDE.md, Authorization).
        $this->authorize('view', $this->employee);

        $years = $this->years();
        if (! in_array($this->year, $years, true)) {
            $this->year = $years[0];
        }

        // Types with a balance: the active ones, and any retired one this employee still has a grant or adjustment of.
        $types = LeaveType::query()
            ->whereNotNull('days_per_year')
            ->where(fn ($query) => $query->where('is_active', true)
                ->orWhereHas('entitlements', fn ($q) => $q->where('employee_id', $this->employee->id))
                ->orWhereHas('adjustments', fn ($q) => $q->where('employee_id', $this->employee->id)))
            ->orderBy('id')
            ->get();

        return view('livewire.employees.leave-card', [
            'years' => $years,
            'balanceRows' => $types->map(fn (LeaveType $type) => [
                'type' => $type,
                'balance' => $balances->for($this->employee, $type, $this->year),
                'earnedSoFar' => $calculator->earnedSoFar($this->employee, $type, today()),
            ])->all(),
            'requests' => $this->requests($counter),
            'adjustments' => LeaveAdjustment::query()
                ->where('employee_id', $this->employee->id)
                ->with(['leaveType', 'createdBy'])
                ->latest('id')
                ->get(),
            'balanceTypes' => $types,
            'canAdjust' => auth()->user()->can('adjust', Leave::class),
            'canFile' => auth()->user()->can('create', [Leave::class, $this->employee]) && auth()->user()->can('fileForOthers', Leave::class),
        ]);
    }

    /**
     * @return Collection<int, array{leave: Leave, days: int}>
     */
    private function requests(LeaveDayCounter $counter): Collection
    {
        return Leave::query()
            ->where('employee_id', $this->employee->id)
            ->with(['leaveType', 'approvalSteps.decidedBy', 'cancelledBy'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Leave $leave) use ($counter) {
                $leave->setRelation('employee', $this->employee);

                return ['leave' => $leave, 'days' => array_sum($counter->countLeave($leave))];
            });
    }
}
