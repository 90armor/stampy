<div>
@if ($showModal)
    <x-modal
        name="employee-form-modal"
        :show="true"
        entangle="showModal"
        backdrop="bg-slate-900/50"
        maxWidth="employee-form"
        panelClass="mt-10"
    >
        @if ($resetPasswordValue)
            <div class="p-6">
                <h3 class="text-base font-semibold text-green-700 dark:text-green-400">Password reset</h3>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Share this temporary password with {{ $editing->full_name }}. It's shown once and can't be retrieved later — resetting again replaces it. It expires in 48 hours and must be changed on first login.
                </p>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-36">Temporary password</dt><dd class="font-mono text-slate-900 dark:text-slate-100">{{ $resetPasswordValue }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-button type="button" variant="primary" wire:click="$set('resetPasswordValue', null)">Done</x-button>
                </div>
            </div>
        @elseif ($generatedPassword)
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
            <div class="mx-6 border-b border-slate-divider py-5">
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
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Identity</p>

                            <div class="mt-3 divide-y divide-slate-divider">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_full_name" value="Full name" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-text-input id="emp_full_name" type="text" wire:model="full_name" autofocus />
                                        <x-input-error :messages="$errors->get('full_name')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_employee_code" value="Employee code" class="!mb-0" />
                                        @if ($editing)
                                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Can't be changed after creation.</p>
                                        @endif
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        @if ($editing)
                                            <p id="emp_employee_code" class="text-sm text-slate-500 dark:text-slate-400">{{ $employee_code }}</p>
                                        @else
                                            <x-text-input id="emp_employee_code" type="text" wire:model="employee_code" />
                                            <x-input-error :messages="$errors->get('employee_code')" class="mt-1" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Work --}}
                        <div>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Work</p>

                            <div class="mt-3 divide-y divide-slate-divider">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_department_id" value="Department" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_department_id" wire:model="department_id">
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
                                        <x-select id="emp_position_id" wire:model="position_id">
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
                                        <x-input-label for="emp_manager_id" value="Manager" class="!mb-0" />
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Leave blank for a top-level employee.</p>
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_manager_id" wire:model="manager_id">
                                            <option value="">No manager</option>
                                            @foreach ($managerOptions as $manager)
                                                <option value="{{ $manager->id }}">{{ $manager->full_name }} ({{ $manager->employee_code }})</option>
                                            @endforeach
                                        </x-select>
                                        <x-input-error :messages="$errors->get('manager_id')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_join_date" value="Join date" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-date-picker id="emp_join_date" model="join_date" label="Join date" />
                                        <x-input-error :messages="$errors->get('join_date')" class="mt-1" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- System --}}
                        <div>
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">System</p>

                            <div class="mt-3 divide-y divide-slate-divider">
                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_device_user_id" value="Device user ID" class="!mb-0" />
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Must match the user ID enrolled on the fingerprint device.</p>
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-text-input id="emp_device_user_id" type="text" wire:model="device_user_id" placeholder="ZKTeco device user ID" />
                                        <x-input-error :messages="$errors->get('device_user_id')" class="mt-1" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                    <div class="sm:max-w-[240px]">
                                        <x-input-label for="emp_status" value="Status" class="!mb-0" />
                                    </div>
                                    <div class="sm:w-[320px] sm:shrink-0">
                                        <x-select id="emp_status" wire:model="status">
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </x-select>
                                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                                    </div>
                                </div>

                                @if ($editing?->user_id)
                                    <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="flex items-start gap-2">
                                            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                                            <p class="text-sm text-slate-500 dark:text-slate-400">This employee already has a linked login account.</p>
                                        </div>
                                        {{-- Native <button>, not <x-button>: @js() doesn't compile when
                                        nested inside a Blade COMPONENT tag's attribute string — Blade's
                                        component-tag compiler captures the raw attribute text before the
                                        directive pass reaches inside it, so it was reaching the browser
                                        as literal, invalid JS ("@js('Reset the password for '....")
                                        rather than a compiled string, breaking Alpine's click handler.
                                        Every other confirm-dialog trigger in the app already uses a
                                        native button for exactly this reason; classes below are
                                        x-button's own secondary-variant output, kept in sync manually. --}}
                                        <button
                                            type="button"
                                            @click="$dispatch('confirm-dialog-form-modal', {
                                                title: 'Reset password',
                                                message: @js('Reset the password for '.$editing->full_name.'? Their current password stops working immediately.'),
                                                confirmText: 'Reset password',
                                                method: 'resetPassword',
                                                args: [],
                                            })"
                                            class="inline-flex shrink-0 items-center justify-center gap-x-1.5 rounded-lg bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-none ring-1 ring-inset ring-slate-border transition hover:bg-slate-50 active:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:bg-slate-750 dark:text-slate-200 dark:hover:bg-slate-600 dark:active:bg-slate-500 dark:focus-visible:ring-offset-slate-800"
                                        >
                                            Reset password
                                        </button>
                                    </div>
                                @else
                                    <div class="py-4">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" wire:model.live="create_user">
                                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Create a login account for this employee</span>
                                        </label>

                                        @if ($create_user)
                                            <div class="mt-4 space-y-4 border-t border-slate-divider pt-4">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_username" value="Username" class="!mb-0" />
                                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Login identifier. Defaults to the employee code.</p>
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-text-input id="emp_username" type="text" wire:model="username" />
                                                        <x-input-error :messages="$errors->get('username')" class="mt-1" />
                                                    </div>
                                                </div>

                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_email" value="Email" class="!mb-0" />
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-text-input id="emp_email" type="email" wire:model="email" />
                                                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                                                    </div>
                                                </div>

                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                                    <div class="sm:max-w-[240px]">
                                                        <x-input-label for="emp_role" value="Role" class="!mb-0" />
                                                    </div>
                                                    <div class="sm:w-[320px] sm:shrink-0">
                                                        <x-select id="emp_role" wire:model="role">
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
                    class="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-white to-transparent transition-opacity duration-150 dark:from-slate-750"
                    :class="atBottom ? 'opacity-0' : 'opacity-100'"
                ></div>
            </div>

            <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-divider py-4">
                <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                <x-button type="submit" form="employee-form" variant="primary">Save Employee</x-button>
            </div>
        @endif
    </x-modal>
@endif

{{-- Own event name, not the default 'confirm-dialog': this component is
embedded on both Employees\Index and Employees\Show, and Index already
mounts its own <x-confirm-dialog> for deactivate/reactivate — sharing the
default event would pop both dialogs at once for a single dispatch. --}}
<x-confirm-dialog event="confirm-dialog-form-modal" />
</div>
