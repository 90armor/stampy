<div>
@if ($showModal)
    <x-modal
        name="employee-form-modal"
        :show="true"
        entangle="showModal"
        surface="solid"
        backdrop="bg-slate-900/50"
        maxWidth="employee-form"
        panelClass="mt-10"
    >
        @if ($generatedPassword)
            <div class="p-6">
                <h3 class="text-base font-semibold text-green-700 dark:text-green-400">Employee saved — account created</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Share these credentials with {{ $full_name }}. This password is shown once and cannot be retrieved later.
                </p>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-20">Username</dt><dd class="text-slate-900 dark:text-slate-100">{{ $username }}</dd></div>
                    <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-20">Email</dt><dd class="text-slate-900 dark:text-slate-100">{{ $email }}</dd></div>
                    <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-20">Password</dt><dd class="font-mono text-slate-900 dark:text-slate-100">{{ $generatedPassword }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-button type="button" variant="primary" wire:click="$set('showModal', false)">Done</x-button>
                </div>
            </div>
        @else
            <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ $editing ? 'Edit — '.$editing->full_name : 'Add Employee' }}
                </h3>
            </div>

            {{-- Plain block flow (not flex) on purpose: max-height on a flex-grow
            child depends on the flex container resolving a definite height, which
            is inconsistent enough across browsers/nesting that it silently clipped
            content instead of scrolling. A plain max-height + overflow-y-auto on a
            normal block always works, no flex involved. --}}
            <div
                class="relative"
                x-data="{ atBottom: true }"
            >
                <div
                    class="overflow-y-auto overscroll-contain px-6 pt-6 pb-4"
                    style="max-height: calc(85vh - 150px)"
                    x-init="atBottom = Math.abs($el.scrollHeight - $el.clientHeight - $el.scrollTop) < 4"
                    @scroll="atBottom = Math.abs($el.scrollHeight - $el.clientHeight - $el.scrollTop) < 4"
                >
                    <form id="employee-form" wire:submit="save" class="space-y-8">
                        {{-- Identity --}}
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Identity</p>

                            <div class="mt-3 divide-y divide-slate-200/60 dark:divide-slate-800/60">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_full_name" value="Full name" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-text-input id="emp_full_name" type="text" surface="solid" wire:model="full_name" autofocus />
                                        <x-input-error :messages="$errors->get('full_name')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_employee_code" value="Employee code" class="!mb-0" />
                                        @if ($editing)
                                            <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Can't be changed after creation.</p>
                                        @endif
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        @if ($editing)
                                            <p id="emp_employee_code" class="text-sm text-slate-500 dark:text-slate-400">{{ $employee_code }}</p>
                                        @else
                                            <x-text-input id="emp_employee_code" type="text" surface="solid" wire:model="employee_code" />
                                            <x-input-error :messages="$errors->get('employee_code')" class="mt-1" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Work --}}
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Work</p>

                            <div class="mt-3 divide-y divide-slate-200/60 dark:divide-slate-800/60">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_department_id" value="Department" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_department_id" surface="solid" wire:model="department_id">
                                            <option value="">Select department</option>
                                            @foreach ($departments as $department)
                                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                                            @endforeach
                                        </x-select>
                                        <x-input-error :messages="$errors->get('department_id')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_position_id" value="Position" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_position_id" surface="solid" wire:model="position_id">
                                            <option value="">Select position</option>
                                            @foreach ($positions as $position)
                                                <option value="{{ $position->id }}">{{ $position->name }}</option>
                                            @endforeach
                                        </x-select>
                                        <x-input-error :messages="$errors->get('position_id')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_join_date" value="Join date" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-text-input id="emp_join_date" type="date" surface="solid" wire:model="join_date" />
                                        <x-input-error :messages="$errors->get('join_date')" class="mt-1" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- System --}}
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">System</p>

                            <div class="mt-3 divide-y divide-slate-200/60 dark:divide-slate-800/60">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_device_user_id" value="Device user ID" class="!mb-0" />
                                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Must match the user ID enrolled on the fingerprint device.</p>
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-text-input id="emp_device_user_id" type="text" surface="solid" wire:model="device_user_id" placeholder="ZKTeco device user ID" />
                                        <x-input-error :messages="$errors->get('device_user_id')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_status" value="Status" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_status" surface="solid" wire:model="status">
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </x-select>
                                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                                    </div>
                                </div>

                                @if ($editing?->user_id)
                                    <div class="flex items-start gap-2 py-4">
                                        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
                                        <p class="text-sm text-slate-500 dark:text-slate-400">This employee already has a linked login account.</p>
                                    </div>
                                @else
                                    <div class="py-4">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" wire:model.live="create_user" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Create a login account for this employee</span>
                                        </label>

                                        @if ($create_user)
                                            <div class="mt-4 space-y-4 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_username" value="Username" class="!mb-0" />
                                                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Login identifier. Defaults to the employee code.</p>
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-text-input id="emp_username" type="text" surface="solid" wire:model="username" />
                                                        <x-input-error :messages="$errors->get('username')" class="mt-1" />
                                                    </div>
                                                </div>

                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_email" value="Email" class="!mb-0" />
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-text-input id="emp_email" type="email" surface="solid" wire:model="email" />
                                                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                                                    </div>
                                                </div>

                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_role" value="Role" class="!mb-0" />
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-select id="emp_role" surface="solid" wire:model="role">
                                                            <option value="admin">Admin</option>
                                                            <option value="manager">Manager</option>
                                                            <option value="employee">Employee</option>
                                                        </x-select>
                                                        <x-input-error :messages="$errors->get('role')" class="mt-1" />
                                                    </div>
                                                </div>

                                                <p class="text-xs text-slate-500 dark:text-slate-400">A random password will be generated and shown once after saving.</p>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>

                <div
                    class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-white to-transparent transition-opacity duration-150 dark:from-slate-900"
                    :class="atBottom ? 'opacity-0' : 'opacity-100'"
                ></div>
            </div>

            <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                <x-button type="submit" form="employee-form" variant="primary">Save Employee</x-button>
            </div>
        @endif
    </x-modal>
@endif
</div>
