<?php

namespace App\Livewire\Holidays;

use App\Models\Employee;
use App\Models\Holiday;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?Holiday $editing = null;

    public string $date = '';

    public string $name = '';

    public ?string $note = null;

    public string $yearFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Holiday::class);

        $this->yearFilter = (string) now()->year;
    }

    public function updatingYearFilter(): void
    {
        $this->resetPage('holidaysPage');
    }

    public function create(): void
    {
        $this->authorize('create', Holiday::class);

        $this->reset(['date', 'name', 'note', 'editing']);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(Holiday $holiday): void
    {
        $this->authorize('update', $holiday);

        $this->editing = $holiday;
        $this->date = $holiday->date->format('Y-m-d');
        $this->name = $holiday->name;
        $this->note = $holiday->note;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? Holiday::class);

        $this->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // A plain 'unique' rule would surface as "The date has already been
        // taken" — not useful to an admin trying to figure out what's
        // already on that date. Checked by hand instead so the message can
        // name the existing holiday, and so this is a normal validation
        // error rather than the unique index's DB exception.
        $duplicate = Holiday::query()
            ->whereDate('date', $this->date)
            ->when($this->editing, fn ($query) => $query->whereKeyNot($this->editing->id))
            ->first();

        if ($duplicate) {
            $this->addError('date', "That date already has a holiday: \"{$duplicate->name}\".");

            return;
        }

        $data = ['date' => $this->date, 'name' => $this->name, 'note' => $this->note];

        if ($this->editing) {
            // Capture the old date before it's overwritten — if it changed,
            // both dates need rebuilding (the old one reverts to whatever it
            // would be without this holiday, the new one picks it up).
            $oldDate = $this->editing->date->format('Y-m-d');

            $this->editing->update($data);

            $this->rebuildDate($oldDate);

            if ($oldDate !== $this->date) {
                $this->rebuildDate($this->date);
            }
        } else {
            $data['created_by'] = auth()->id();
            Holiday::create($data);

            $this->rebuildDate($this->date);
        }

        $this->showModal = false;
        $this->reset(['date', 'name', 'note', 'editing']);
    }

    public function delete(Holiday $holiday): void
    {
        $this->authorize('delete', $holiday);

        $date = $holiday->date->format('Y-m-d');
        $holiday->delete();

        $this->rebuildDate($date);
    }

    /**
     * Rebuilds one date for every employee active on it
     * (Employee::scopeActiveOn()) — adding, editing or
     * deleting a holiday changes how that date's punches (or lack of them)
     * are interpreted, for everyone, not just whoever happens to view their
     * own attendance page next.
     *
     * Deliberately just this one date, not the D-1/D/D+1 window
     * Attendance\Show::rebuildAround() uses for a punch change: that window
     * exists because a punch's timestamp can affect which day an adjacent
     * day's overnight pairing claims. A holiday never touches
     * attendance_logs — it only changes how one date's ALREADY-paired
     * punches are interpreted (holiday status, zeroed late/early) — so it
     * can never change what an adjacent day pairs as its own first_in/
     * last_out.
     */
    private function rebuildDate(string $date): void
    {
        $builder = app(DailySummaryBuilder::class);
        $day = Carbon::parse($date);

        // buildDates(): a holiday changes overtime categories, so credited time
        // off in lieu too (Phase 4b) — reconciled per employee, reported once.
        Employee::query()
            ->activeOn($day)
            ->each(fn (Employee $employee) => $builder->buildDates($employee, $day, $day));

        $builder->reportToil();
    }

    public function render()
    {
        return view('livewire.holidays.index', [
            'holidays' => Holiday::query()
                ->whereYear('date', $this->yearFilter)
                ->orderBy('date')
                ->paginate(15, ['*'], 'holidaysPage'),
            'years' => $this->yearOptions(),
        ]);
    }

    /**
     * @return int[]
     */
    private function yearOptions(): array
    {
        $current = (int) now()->year;

        return range($current - 1, $current + 2);
    }
}
