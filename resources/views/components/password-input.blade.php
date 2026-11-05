{{-- A password field with a show/hide toggle (Phase 6i, the profile's Change
password modal). The toggle is a pressed/not-pressed button with a fixed name,
44px wide; below 640px the field is 44px tall too, so the toggle is a full
touch target. Pass wire:model and the rest as attributes. --}}
@props(['id', 'autocomplete' => 'new-password'])

<div x-data="{ visible: false }" class="relative">
    <input {{ $attributes->class('block h-control max-sm:min-h-11 w-full rounded-lg border-slate-border bg-white pr-12 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:bg-slate-750 dark:text-slate-100 dark:placeholder-slate-400') }} id="{{ $id }}" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="{{ $autocomplete }}">
    <button
        type="button"
        @click="visible = ! visible"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-slate-500 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:text-slate-200"
        aria-label="Show password"
        aria-controls="{{ $id }}"
        aria-pressed="false"
        x-bind:aria-pressed="visible.toString()"
    >
        <x-icon name="eye" class="h-5 w-5" x-show="! visible" />
        <x-icon name="eye-off" class="h-5 w-5" x-show="visible" x-cloak />
    </button>
</div>
