@props(['icon', 'label', 'value'])

{{-- One cell of the shared stat strip (docs/DESIGN_SYSTEM.md → Stat strip):
one solid <x-card :padding="false"> holding a <dl> grid of these cells,
separated by dividers — never a card per figure. Every strip uses the same
treatment: a neutral 32px icon tile (hidden below sm so three figures fit a
phone width), a text-xs label and a text-xl value. The icon is a neutral
signifier, not a status colour — the label names the figure. Optional
$subtext slot for a short breakdown ("7 late · 10 early", "97.1%"). --}}
<div {{ $attributes->merge(['class' => 'min-w-0 px-3 py-3 sm:flex sm:items-center sm:gap-3 sm:px-4']) }}>
    <span class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 sm:flex dark:bg-slate-800 dark:text-slate-400" aria-hidden="true">
        <x-icon :name="$icon" class="h-4 w-4" />
    </span>
    <div class="min-w-0">
        <dt class="text-xs font-medium leading-4 text-slate-500 dark:text-slate-400">{{ $label }}</dt>
        <dd class="mt-0.5 text-xl font-semibold leading-7 tabular-nums text-slate-900 dark:text-slate-100">{{ $value }}</dd>
        @isset($subtext)
            <dd class="text-xs leading-4 text-slate-500 dark:text-slate-400">{{ $subtext }}</dd>
        @endisset
    </div>
</div>
