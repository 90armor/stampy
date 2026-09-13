@if ($showModal)
    <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="$set('showModal', false)"></div>

        <div class="relative mx-auto mt-10 max-w-2xl">
            <x-card class="max-h-[85vh] overflow-y-auto">
                @if ($generatedPassword)
                    <h3 class="text-base font-semibold text-green-700 dark:text-green-400">Employee saved — account created</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        Share these credentials with {{ $full_name }}. This password is shown once and cannot be retrieved later.
                    </p>
                    <dl class="mt-3 space-y-1 text-sm">
                        <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-20">Email</dt><dd class="text-slate-900 dark:text-slate-100">{{ $email }}</dd></div>
                        <div class="flex gap-2"><dt class="font-medium text-slate-500 dark:text-slate-400 w-20">Password</dt><dd class="font-mono text-slate-900 dark:text-slate-100">{{ $generatedPassword }}</dd></div>
                    </dl>
                    <div class="mt-4">
                        <x-button type="button" variant="primary" wire:click="$set('showModal', false)">Done</x-button>
                    </div>
                @else
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ $editing ? 'Edit Employee' : 'New Employee' }}
                    </h3>

                    <form wire:submit="save" class="mt-4 space-y-5">
                        <div>
                            <x-input-label for="emp_full_name" value="Full name" />
                            <x-text-input id="emp_full_name" type="text" class="w-full" wire:model="full_name" autofocus />
                            <x-input-error :messages="$errors->get('full_name')" class="mt-1" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <x-input-label for="emp_employee_code" value="Employee code" />
                                <x-text-input id="emp_employee_code" type="text" class="w-full" wire:model="employee_code" />
                                <x-input-error :messages="$errors->get('employee_code')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="emp_department_id" value="Department" />
                                <select id="emp_department_id" wire:model="department_id" class="block w-full rounded-lg border-slate-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
                                    <option value="">Select department</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('department_id')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <x-input-label for="emp_position_id" value="Position" />
                                <select id="emp_position_id" wire:model="position_id" class="block w-full rounded-lg border-slate-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
                                    <option value="">Select position</option>
                                    @foreach ($positions as $position)
                                        <option value="{{ $position->id }}">{{ $position->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('position_id')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="emp_join_date" value="Join date" />
                                <x-text-input id="emp_join_date" type="date" class="w-full" wire:model="join_date" />
                                <x-input-error :messages="$errors->get('join_date')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <x-input-label for="emp_device_user_id" value="Device user ID" />
                                <x-text-input id="emp_device_user_id" type="text" class="w-full" wire:model="device_user_id" placeholder="ZKTeco device user ID" />
                                <x-input-error :messages="$errors->get('device_user_id')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label for="emp_status" value="Status" />
                                <select id="emp_status" wire:model="status" class="block w-full rounded-lg border-slate-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <x-input-error :messages="$errors->get('status')" class="mt-1" />
                            </div>
                        </div>

                        @if ($editing?->user_id)
                            <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                This employee already has a linked login account.
                            </div>
                        @else
                            <div class="border-t border-slate-100 pt-5 dark:border-slate-800">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model.live="create_user" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Create a login account for this employee</span>
                                </label>

                                @if ($create_user)
                                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <div>
                                            <x-input-label for="emp_email" value="Email" />
                                            <x-text-input id="emp_email" type="email" class="w-full" wire:model="email" />
                                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                                        </div>

                                        <div>
                                            <x-input-label for="emp_role" value="Role" />
                                            <select id="emp_role" wire:model="role" class="block w-full rounded-lg border-slate-300 shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
                                                <option value="admin">Admin</option>
                                                <option value="manager">Manager</option>
                                                <option value="employee">Employee</option>
                                            </select>
                                            <x-input-error :messages="$errors->get('role')" class="mt-1" />
                                        </div>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">A random password will be generated and shown once after saving.</p>
                                @endif
                            </div>
                        @endif

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                            <x-button type="submit" variant="primary">Save Employee</x-button>
                        </div>
                    </form>
                @endif
            </x-card>
        </div>
    </div>
@endif
