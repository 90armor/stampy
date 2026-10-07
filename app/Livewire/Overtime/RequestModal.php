<?php

namespace App\Livewire\Overtime;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Exceptions\OvertimeValidationException;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Services\Overtime\OvertimePreview;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\AttendanceTime;
use App\Support\DisplayDate;
use App\Support\Duration;
use App\Support\OvertimeSummary;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The overtime request modal (Phase 4d) — Leave\RequestModal's twin, one
 * component with two entry points: an employee's own request
 * ('request-overtime') and an admin's "File for an employee"
 * ('file-overtime', optionally preselected and locked, as the profile will
 * open it).
 *
 * Two steps: the fields, then a review built by OvertimeRequestService::
 * preview() — the same rules as submit, nothing written — saying whether it's
 * planned or a claim, what part of the window counts, the category split with
 * its rates, what it adds toward time off in lieu, any limit problem (with an
 * override reason for an admin), the punches already on record for a claim
 * and who reviews it. Submit checks again: if the preview changed in between
 * it shows the new one and writes nothing. Errors land on their fields
 * (OvertimeValidationException's keys).
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

    public string $date = '';

    /** 'HH:MM'. An end earlier than the start is on the next day. */
    public string $start_time = '';

    public string $end_time = '';

    public string $compensation = 'pay';

    public string $reason = '';

    /** An admin's reason to go over a daily limit — asked for on the review, only when one fails. */
    public string $override_reason = '';

    /**
     * The review step's display, from the preview. Not named $review (the
     * method): in Livewire's JS proxy a property shadows a method of the same
     * name (LivewireNamingTest).
     *
     * @var array<string, mixed>
     */
    public array $summary = [];

    public ?string $previewKey = null;

    public ?string $changed = null;

    #[On('request-overtime')]
    public function open(): void
    {
        $employee = auth()->user()->employee;
        abort_if($employee === null, 403);
        $this->authorize('create', [OvertimeRequest::class, $employee]);

        $this->resetModal();
        $this->employeeId = $employee->id;
        $this->showModal = true;
    }

    #[On('file-overtime')]
    public function openForOthers(?int $employeeId = null, bool $lock = false): void
    {
        $this->authorize('fileForOthers', OvertimeRequest::class);

        $this->resetModal();
        $this->forOthers = true;
        $this->employeeId = $employeeId;
        $this->employeeLocked = $employeeId !== null && $lock;
        $this->showModal = true;
    }

    public function selectEmployee(int $id): void
    {
        $this->authorize('fileForOthers', OvertimeRequest::class);
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

    public function review(OvertimeRequestService $service): void
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

    public function submit(OvertimeRequestService $service): void
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
                $preview->date,
                $preview->startsAt,
                $preview->endsAt,
                $preview->compensation,
                $this->reason !== '' ? $this->reason : null,
                auth()->user(),
                $this->override_reason !== '' ? $this->override_reason : null,
            );
        } catch (OvertimeValidationException $e) {
            $this->addErrors($e);

            if (array_diff(array_keys($e->errors()), ['limit_override_reason', 'overtime']) !== []) {
                $this->step = 'form';
            }

            return;
        }

        $message = $this->forOthers
            ? "Filed overtime for {$preview->employee->full_name} — approved."
            : 'Requested overtime for '.$this->summary['when'].'.';

        $this->dispatch('overtime-saved', message: $message, rebuildError: $result['rebuildError']);
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
    private function preview(OvertimeRequestService $service): ?OvertimePreview
    {
        $this->resetErrorBag();

        $this->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'compensation' => ['required', 'in:pay,time_off'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'date.required' => 'Choose the date.',
            'start_time.required' => 'Choose when it starts.',
            'end_time.required' => 'Choose when it ends.',
        ]);

        if ($this->employeeId === null) {
            $this->addError('employee', 'Choose the employee this overtime is for.');

            return null;
        }

        $startsAt = Carbon::parse("{$this->date} {$this->start_time}");
        $endsAt = Carbon::parse("{$this->date} {$this->end_time}");

        if ($endsAt->lte($startsAt)) {
            $endsAt->addDay();
        }

        try {
            return $service->preview(
                Employee::findOrFail($this->employeeId),
                Carbon::parse($this->date),
                $startsAt,
                $endsAt,
                OvertimeCompensation::from($this->compensation),
                auth()->user(),
                $this->override_reason !== '' ? $this->override_reason : null,
            );
        } catch (OvertimeValidationException $e) {
            $this->step = 'form';
            $this->addErrors($e);

            return null;
        }
    }

    private function addErrors(OvertimeValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function showReview(OvertimePreview $preview): void
    {
        $this->previewKey = $preview->key();
        $this->step = 'review';
        $this->changed = null;

        $time = fn ($at) => AttendanceTime::format($at);
        $span = fn (array $span) => $time($span[0]).' – '.$time($span[1]);
        $you = $this->forOthers ? 'They' : 'You';
        $settings = OvertimeSettings::current();

        $punches = null;
        if ($preview->kind === OvertimeKind::Claim) {
            $in = $preview->punches['in'] ?? null;
            $out = $preview->punches['out'] ?? null;
            $punches = match (true) {
                $in !== null && $out !== null => "{$you} punched in at {$time($in)} and out at {$time($out)}".($out->isSameDay($preview->date) ? '' : ' (+1)').'.',
                $in !== null => "{$you} punched in at {$time($in)}; there's no out-punch yet.",
                $out !== null => "{$you} punched out at {$time($out)}; there's no in-punch.",
                default => 'No punches on record for '.DisplayDate::compact($preview->date).' yet.',
            };
        }

        $toil = null;
        if ($preview->toil !== null) {
            ['adds' => $adds, 'saved' => $saved, 'completes' => $completes] = $preview->toil;
            $toil = 'Adds '.Duration::format($adds).' toward time off in lieu'
                .($saved > 0 || $completes > 0 ? ' ('
                    .($saved > 0 ? 'you have '.Duration::format($saved).' saved' : '')
                    .($saved > 0 && $completes > 0 ? ' — ' : '')
                    .($completes > 0 ? 'this completes '.($completes === 1 ? 'a half day' : $completes.' half days') : '')
                    .')' : '')
                .'.';
        }

        $this->summary = [
            'employee' => $preview->employee->full_name,
            'when' => DisplayDate::compact($preview->date).', '.$span([$preview->startsAt, $preview->endsAt]).($preview->isOvernight() ? ' (+1)' : ''),
            'kind' => $preview->kind === OvertimeKind::Claim
                ? 'This is a claim: the time has already started.'
                : 'This is planned: it hasn\'t started yet.',
            'compensation' => $preview->compensation->label(),
            'total' => Duration::format($preview->credit->total()),
            'counts' => $preview->normal !== null
                ? 'Only '.collect($preview->counted)->map($span)->implode(' and ').' counts. '.$span($preview->normal).' is normal working hours.'
                : null,
            'split' => OvertimeSummary::categoryLine([
                'workday' => $preview->credit->workday, 'night' => $preview->credit->night,
                'rest_day' => $preview->credit->restDay, 'holiday' => $preview->credit->holiday,
            ]),
            'toil' => $toil,
            'limitProblems' => $preview->limitProblems,
            'punches' => $punches,
            'reviewers' => $preview->reviewers,
            'approvedOnSubmit' => $preview->approvedOnSubmit,
            'block' => $settings->toil_block_minutes,
        ];
    }

    private function resetModal(): void
    {
        $this->reset(['step', 'forOthers', 'employeeId', 'employeeLocked', 'employeeSearch', 'date', 'start_time', 'end_time', 'compensation', 'reason', 'override_reason', 'summary', 'previewKey', 'changed']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $employee = $this->employeeId !== null ? Employee::find($this->employeeId) : null;

        if ($this->showModal && $employee !== null) {
            $this->authorize('create', [OvertimeRequest::class, $employee]);
        }

        $settings = OvertimeSettings::current();

        return view('livewire.overtime.request-modal', [
            'employee' => $employee,
            // Time off only while there's a TOIL type to credit it to (Phase 4b).
            'timeOffOffered' => $settings->toil_leave_type_id !== null,
            'matches' => $this->forOthers && $employee === null && trim($this->employeeSearch) !== ''
                ? Employee::query()
                    ->where(fn ($query) => $query->where('full_name', 'like', '%'.trim($this->employeeSearch).'%')
                        ->orWhere('employee_code', 'like', '%'.trim($this->employeeSearch).'%'))
                    ->orderBy('full_name')
                    ->limit(8)
                    ->get()
                : collect(),
            // Mirrors the service's own date rules (the server stays the authority).
            'minDate' => $this->forOthers ? $employee?->join_date?->format('Y-m-d') : today()->subDays($settings->claim_window_days)->format('Y-m-d'),
            'maxDate' => today()->addDays(OvertimeRequestService::PLANNED_DAYS_AHEAD)->format('Y-m-d'),
        ]);
    }
}
