<?php

namespace App\Livewire\LeaveTypes;

use App\Enums\LeaveCounting;
use App\Enums\LeaveStatus;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\LeaveTypeInUseException;
use App\Exceptions\LeaveTypeLockedException;
use App\Models\LeaveType;
use App\Support\LeaveDays;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Policies → Leave types (Phase 3e): the leave policy, per type. The rules
 * live on the model (LeaveType::booted()): the fields a leave depends on
 * lock once one is taken, deletion is refused while anything points at the
 * type, and the balance options need a balance. This component shows them —
 * locked fields read-only with the reason, Delete only when nothing refers
 * to the type and Deactivate otherwise — and surfaces the model's message
 * when a save is refused anyway.
 */
class Index extends Component
{
    public bool $showModal = false;

    public ?LeaveType $editing = null;

    public string $name = '';

    /** '' = no yearly balance. */
    public string $days_per_year = '';

    public string $min_service_months = '';

    public bool $seniority_bonus = false;

    public string $carry_over_cap = '';

    public string $counts = 'workdays';

    public string $max_days_per_request = '';

    /** A leave type id, or '' / null for its own balance (a select's empty option). */
    public ?string $deducts_from_leave_type_id = null;

    public bool $allows_half_day = true;

    public bool $is_paid = true;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', LeaveType::class);
    }

    public function create(): void
    {
        $this->authorize('create', LeaveType::class);

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(LeaveType $type): void
    {
        $this->authorize('update', $type);

        $this->resetForm();
        $this->editing = $type;
        $this->name = $type->name;
        $this->days_per_year = self::field($type->days_per_year);
        $this->min_service_months = $type->min_service_months !== null ? (string) $type->min_service_months : '';
        $this->seniority_bonus = $type->seniority_bonus;
        $this->carry_over_cap = self::field($type->carry_over_cap);
        $this->counts = $type->counts->value;
        $this->max_days_per_request = self::field($type->max_days_per_request);
        $this->deducts_from_leave_type_id = $type->deducts_from_leave_type_id !== null ? (string) $type->deducts_from_leave_type_id : null;
        $this->allows_half_day = $type->allows_half_day;
        $this->is_paid = $type->is_paid;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? LeaveType::class);

        $days = ['nullable', 'numeric', 'decimal:0,1'];
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types', 'name')->ignore($this->editing?->id)],
            'days_per_year' => [...$days, 'min:0', 'max:365'],
            'min_service_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'carry_over_cap' => [...$days, 'min:0', 'max:365'],
            'counts' => ['required', Rule::enum(LeaveCounting::class)],
            'max_days_per_request' => [...$days, 'min:0.5', 'max:365'],
            'deducts_from_leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
        ], [
            'name.unique' => 'There is already a leave type with this name.',
            '*.decimal' => 'Use whole or tenths of a day, such as 18 or 0.5.',
        ]);

        $data = [
            'name' => trim($this->name),
            'days_per_year' => self::nullable($this->days_per_year),
            'min_service_months' => self::nullable($this->min_service_months),
            'seniority_bonus' => $this->seniority_bonus,
            'carry_over_cap' => self::nullable($this->carry_over_cap),
            'counts' => $this->counts,
            'max_days_per_request' => self::nullable($this->max_days_per_request),
            'deducts_from_leave_type_id' => filled($this->deducts_from_leave_type_id) ? (int) $this->deducts_from_leave_type_id : null,
            'allows_half_day' => $this->allows_half_day,
            'is_paid' => $this->is_paid,
        ];

        try {
            if ($this->editing) {
                $this->editing->update($data);
                $this->notice = "Saved {$this->editing->name}.";
            } else {
                $type = LeaveType::create([...$data, 'is_active' => true]);
                $this->notice = "Added {$type->name}.";
            }
        } catch (LeaveTypeLockedException|InvalidLeaveTypeException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(LeaveType $type): void
    {
        $this->authorize('delete', $type);

        try {
            $type->delete();
            $this->notice = "Deleted {$type->name}.";
        } catch (LeaveTypeInUseException $e) {
            $this->addError('delete', $e->getMessage());
        }
    }

    /**
     * Deactivate: no new requests and no new grants; pending requests stay
     * decidable (only submitting checks is_active) and balances stay.
     */
    public function setActive(LeaveType $type, bool $active): void
    {
        $this->authorize('update', $type);

        $type->update(['is_active' => $active]);
        $this->notice = ($active ? 'Reactivated ' : 'Deactivated ').$type->name.'.';
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'name', 'days_per_year', 'min_service_months', 'seniority_bonus', 'carry_over_cap', 'counts', 'max_days_per_request', 'deducts_from_leave_type_id', 'allows_half_day', 'is_paid']);
        $this->resetErrorBag();
    }

    /** A decimal:1 cast for a text field: "18.0" → "18", "0.5" → "0.5", null → "". */
    private static function field(?string $value): string
    {
        return $value === null ? '' : LeaveDays::format(LeaveDays::fromDecimal($value));
    }

    private static function nullable(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    public function render()
    {
        $this->authorize('viewAny', LeaveType::class);

        $types = LeaveType::query()
            ->with('deductsFrom')
            ->withCount([
                'leaves',
                'entitlements',
                'adjustments',
                'deductedBy',
                'leaves as pending_count' => fn ($query) => $query->where('status', LeaveStatus::Pending->value),
            ])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        return view('livewire.leave-types.index', [
            'types' => $types,
            // What a type may deduct from: one with a balance that doesn't itself deduct (one level only).
            'deductTargets' => $types->filter(fn (LeaveType $type) => $type->days_per_year !== null
                && $type->deducts_from_leave_type_id === null
                && $type->id !== $this->editing?->id)->values(),
            'locked' => $this->editing?->isUsedByLeaves() ?? false,
        ]);
    }
}
