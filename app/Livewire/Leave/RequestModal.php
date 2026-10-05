<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveHalf;
use App\Exceptions\LeaveValidationException;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Services\Leave\LeavePreview;
use App\Services\Leave\LeaveRequestService;
use App\Support\DisplayDate;
use App\Support\LeaveDays;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The leave request modal (Phase 3e) — one component, two entry points: an
 * employee's own request ('request-leave') and an admin's "File for an
 * employee" ('file-leave', optionally with the employee preselected and
 * locked, as the profile's Leave card will open it).
 *
 * Two steps, the same pattern as deactivation: the fields, then a review
 * built by LeaveRequestService::preview() — the same rules as submit, nothing
 * written — showing the cost and its effect. Submit checks again: if the
 * preview has changed in between (a holiday added, another request
 * approved), it shows the new one and writes nothing. Validation errors land
 * on their fields (LeaveValidationException's keys).
 */
class RequestModal extends Component
{
    public bool $showModal = false;

    /** 'form' or 'review'. */
    public string $step = 'form';

    /** An admin filing for someone else. */
    public bool $forOthers = false;

    public ?int $employeeId = null;

    public bool $employeeLocked = false;

    public string $employeeSearch = '';

    public ?int $leave_type_id = null;

    public string $start_date = '';

    /** Nullable: blank (or cleared in the picker) means the same day as start_date. */
    public ?string $end_date = null;

    /** '', 'am' or 'pm' — a half day, only for a single date of a type that allows it. */
    public string $half = '';

    public string $reason = '';

    /** @var array<string, mixed> the review step's display, from the preview */
    public array $review = [];

    public ?string $previewKey = null;

    public ?string $changed = null;

    #[On('request-leave')]
    public function open(): void
    {
        $employee = auth()->user()->employee;
        abort_if($employee === null, 403);
        $this->authorize('create', [Leave::class, $employee]);

        $this->resetModal();
        $this->employeeId = $employee->id;
        $this->showModal = true;
    }

    #[On('file-leave')]
    public function openForOthers(?int $employeeId = null, bool $lock = false): void
    {
        $this->authorize('fileForOthers', Leave::class);

        $this->resetModal();
        $this->forOthers = true;
        $this->employeeId = $employeeId;
        $this->employeeLocked = $employeeId !== null && $lock;
        $this->showModal = true;
    }

    public function selectEmployee(int $id): void
    {
        $this->authorize('fileForOthers', Leave::class);
        abort_if($this->employeeLocked, 403);

        $this->employeeId = Employee::findOrFail($id)->id;
        $this->employeeSearch = '';
        $this->resetErrorBag('employee');
    }

    public function clearEmployee(): void
    {
        abort_if($this->employeeLocked, 403);

        $this->employeeId = null;
    }

    public function review(LeaveRequestService $service): void
    {
        $preview = $this->preview($service);

        if ($preview !== null) {
            $this->showReview($preview);
        }
    }

    public function back(): void
    {
        $this->step = 'form';
        $this->changed = null;
    }

    public function submit(LeaveRequestService $service): void
    {
        $preview = $this->preview($service);

        if ($preview === null) {
            return;
        }

        if ($preview->key() !== $this->previewKey) {
            $this->showReview($preview);
            $this->changed = 'Something changed since you reviewed this request — check the details again before submitting.';

            return;
        }

        try {
            $result = $service->submit(
                $preview->employee,
                $preview->type,
                $preview->start,
                $preview->end,
                $preview->half !== null ? LeaveHalf::from($preview->half) : null,
                $this->reason !== '' ? $this->reason : null,
                auth()->user(),
            );
        } catch (LeaveValidationException $e) {
            $this->step = 'form';
            $this->addErrors($e);

            return;
        }

        $message = $this->forOthers
            ? "Filed {$preview->type->name} leave for {$preview->employee->full_name} — approved."
            : "Requested {$preview->type->name} leave for {$this->review['dates']}.";

        $this->dispatch('leave-saved', message: $message, rebuildError: $result['rebuildError']);
        $this->showModal = false;
        $this->resetModal();
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->resetModal();
    }

    /**
     * The service's preview for the fields, or null with the errors added.
     */
    private function preview(LeaveRequestService $service): ?LeavePreview
    {
        $this->resetErrorBag();

        $this->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'half' => ['in:,am,pm'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'leave_type_id.required' => 'Choose a type of leave.',
            'start_date.required' => 'Choose the first day.',
        ]);

        if ($this->employeeId === null) {
            $this->addError('employee', 'Choose the employee this leave is for.');

            return null;
        }

        $employee = Employee::findOrFail($this->employeeId);
        $start = Carbon::parse($this->start_date);
        $end = filled($this->end_date) ? Carbon::parse($this->end_date) : $start->copy();

        try {
            return $service->preview(
                $employee,
                LeaveType::findOrFail($this->leave_type_id),
                $start,
                $end,
                $this->half !== '' ? LeaveHalf::from($this->half) : null,
                auth()->user(),
            );
        } catch (LeaveValidationException $e) {
            $this->step = 'form';
            $this->addErrors($e);

            return null;
        }
    }

    private function addErrors(LeaveValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function showReview(LeavePreview $preview): void
    {
        $this->previewKey = $preview->key();
        $this->step = 'review';
        $this->changed = null;

        $sameType = $preview->balanceType->is($preview->type);
        $years = array_keys($preview->cost);

        $this->review = [
            'employee' => $preview->employee->full_name,
            'type' => $preview->type->name,
            'dates' => ($preview->start->eq($preview->end)
                ? DisplayDate::compact($preview->start)
                : DisplayDate::range($preview->start, $preview->end))
                .($preview->half !== null ? ' · '.LeaveHalf::from($preview->half)->label() : ''),
            'total' => LeaveDays::label($preview->total()),
            'charges' => $sameType ? $preview->type->name : "{$preview->balanceType->name} (".$preview->type->name.' is deducted from '.$preview->balanceType->name.')',
            'hasBalance' => $preview->balances !== [],
            // One line per year; more than one when the leave crosses New Year.
            'years' => array_map(fn (int $year) => [
                'year' => $year,
                'cost' => LeaveDays::label($preview->cost[$year]),
                'before' => isset($preview->balances[$year]) ? LeaveDays::format($preview->balances[$year]['before']) : null,
                'after' => isset($preview->balances[$year]) ? LeaveDays::format($preview->balances[$year]['after']) : null,
            ], $years),
            'notCharged' => array_map(fn (array $day) => DisplayDate::compact($day['date']).' ('.$day['reason'].')', $preview->notCharged),
            'approvedOnSubmit' => $preview->approvedOnSubmit,
        ];
    }

    private function resetModal(): void
    {
        $this->reset(['step', 'forOthers', 'employeeId', 'employeeLocked', 'employeeSearch', 'leave_type_id', 'start_date', 'end_date', 'half', 'reason', 'review', 'previewKey', 'changed']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $employee = $this->employeeId !== null ? Employee::find($this->employeeId) : null;

        if ($this->showModal && $employee !== null) {
            $this->authorize('create', [Leave::class, $employee]);
        }

        $nextYear = today()->year + 1;
        $latestYear = $employee !== null && LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $nextYear)->exists()
            ? $nextYear
            : today()->year;

        return view('livewire.leave.request-modal', [
            'employee' => $employee,
            'types' => LeaveType::query()->where('is_active', true)->orderBy('id')->get(),
            'matches' => $this->forOthers && $employee === null && trim($this->employeeSearch) !== ''
                ? Employee::query()
                    ->where(fn ($query) => $query->where('full_name', 'like', '%'.trim($this->employeeSearch).'%')
                        ->orWhere('employee_code', 'like', '%'.trim($this->employeeSearch).'%'))
                    ->orderBy('full_name')
                    ->limit(8)
                    ->get()
                : collect(),
            // Mirrors the service's own date rules (the server stays the authority).
            'minDate' => $this->forOthers ? $employee?->join_date?->format('Y-m-d') : today()->subDays(LeaveRequestService::RETROACTIVE_DAYS)->format('Y-m-d'),
            'maxDate' => "{$latestYear}-12-31",
        ]);
    }
}
