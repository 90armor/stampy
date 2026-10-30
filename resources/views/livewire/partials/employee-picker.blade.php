{{-- The employee an admin files a request for (leave, overtime): search by
name or code — employees without a login included — or the one preselected
(and, from a profile, locked). The component has employeeSearch,
selectEmployee(), clearEmployee() and $employeeLocked; the view passes $id,
$employee, $matches and $matchTotal (App\Support\EmployeePicker).

The results are a plain list of buttons, not a listbox: Tab reaches them, and
nothing here handles the arrow keys a listbox promises. The count is announced
once typing has paused: a request already waits for a 300ms pause, but someone
typing slower than that would hear a count per letter. The announcing element
is keyed by its text, so a new text starts a new x-init; the region itself is
wire:ignore, or a re-render would blank what Alpine put there. --}}
@php
    $shown = $matches->count();
    $searching = ! $employee && trim($employeeSearch) !== '';
    $announcement = match (true) {
        ! $searching => '',
        $matchTotal === 0 => 'No employee matches',
        $matchTotal > $shown => "Showing {$shown} of {$matchTotal}",
        default => $matchTotal.' '.\Illuminate\Support\Str::plural('employee', $matchTotal).' found',
    };
@endphp
<div x-data="{ count: '', timer: null, announce(text) { clearTimeout(this.timer); this.timer = setTimeout(() => this.count = text, 700) } }">
    <p class="sr-only" aria-live="polite" wire:ignore x-text="count"></p>
    <span hidden wire:key="{{ $id }}-count-{{ md5($announcement) }}" x-init="announce(@js($announcement))"></span>
    <x-input-label for="{{ $id }}" value="Employee" />
    @if ($employee)
        <div class="flex h-control items-center justify-between gap-3 rounded-lg border border-slate-border bg-slate-50 px-3 text-sm dark:bg-slate-800">
            <span class="truncate text-slate-900 dark:text-slate-100">{{ $employee->full_name }} <span class="text-slate-500 dark:text-slate-400">· {{ $employee->employee_code }}</span></span>
            @unless ($employeeLocked)
                <button type="button" wire:click="clearEmployee" class="shrink-0 rounded text-sm font-medium text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">Change</button>
            @endunless
        </div>
    @else
        <x-text-input id="{{ $id }}" type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Search by name or employee code" autocomplete="off" autofocus />
        @if ($matches->isNotEmpty())
            <ul class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-border bg-white dark:bg-slate-750" aria-label="Matching employees">
                @foreach ($matches as $match)
                    <li>
                        <button type="button" wire:click="selectEmployee({{ $match->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-slate-900 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none dark:text-slate-100 dark:hover:bg-slate-600/30 dark:focus:bg-slate-600/30">
                            <span class="truncate">{{ $match->full_name }}</span>
                            <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $match->employee_code }}{{ $match->status === 'inactive' ? ' · inactive' : '' }}{{ $match->user_id === null ? ' · no login' : '' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($matchTotal > $shown)
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Showing {{ $shown }} of {{ $matchTotal }} — keep typing</p>
            @endif
        @elseif (trim($employeeSearch) !== '')
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No employee matches “{{ $employeeSearch }}”.</p>
        @endif
    @endif
    <x-input-error :messages="$errors->get('employee')" class="mt-1" />
</div>
