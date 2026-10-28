{{-- The employee an admin files a request for (leave, overtime): search by
name or code — employees without a login included — or the one preselected
(and, from a profile, locked). The component has employeeSearch,
selectEmployee(), clearEmployee() and $employeeLocked; the view passes $id,
$employee and $matches. --}}
<div>
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
            <ul class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-border bg-white dark:bg-slate-750" role="listbox" aria-label="Matching employees">
                @foreach ($matches as $match)
                    <li>
                        <button type="button" wire:click="selectEmployee({{ $match->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-slate-900 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none dark:text-slate-100 dark:hover:bg-slate-600/30 dark:focus:bg-slate-600/30">
                            <span class="truncate">{{ $match->full_name }}</span>
                            <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $match->employee_code }}{{ $match->status === 'inactive' ? ' · inactive' : '' }}{{ $match->user_id === null ? ' · no login' : '' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif (trim($employeeSearch) !== '')
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No employee matches “{{ $employeeSearch }}”.</p>
        @endif
    @endif
    <x-input-error :messages="$errors->get('employee')" class="mt-1" />
</div>
