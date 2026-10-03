@props(['icon', 'label', 'value'])

{{-- One cell of the shared stat strip (docs/DESIGN_SYSTEM.md → Stat strip):
one solid <x-card :padding="false"> holding a <dl> grid of these cells,
separated by dividers — never a card per figure. A strip has exactly three
cells and stays one row (`grid-cols-3 divide-x`) at every width, including a
390px phone, so no width can leave an orphaned cell or a partial divider. Cells use px-6, the standard card padding, so a strip's
figures line up with other cards' titles. Every strip uses the same
treatment: a neutral 32px icon tile (from lg, where every cell has room for
it), a text-xs label and a text-xl value. The icon is a neutral signifier,
not a status colour — the label names the figure. Optional $subtext slot
for a short breakdown ("7 late · 10 early", "3 early"). Content is
top-aligned (`lg:items-start`) so a sub-line in one cell never shifts the
label and value of its neighbours. --}}
<div {{ $attributes->merge(['class' => 'min-w-0 px-6 py-4 lg:flex lg:items-start lg:gap-3']) }}>
    <span class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 lg:flex dark:bg-slate-750 dark:text-slate-400" aria-hidden="true">
        <x-icon :name="$icon" class="h-5 w-5" />
    </span>
    <div class="min-w-0">
        <dt class="text-xs font-medium leading-4 text-slate-500 dark:text-slate-400">{{ $label }}</dt>
        <dd class="mt-0.5 text-xl font-semibold leading-7 tabular-nums text-slate-900 dark:text-slate-100">{{ $value }}</dd>
        @isset($subtext)
            <dd class="text-xs leading-4 text-slate-500 dark:text-slate-400">{{ $subtext }}</dd>
        @endisset
    </div>
</div>
