@props(['icon', 'label', 'value', 'iconClass' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'])

<x-card class="flex items-center gap-x-3 p-4">
    <div @class(['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', $iconClass])>
        <x-icon :name="$icon" class="h-4 w-4" />
    </div>
    <div class="min-w-0 flex-1">
        <p class="truncate text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
        <p class="text-xl font-semibold leading-tight tracking-tight tabular-nums text-slate-900 dark:text-slate-100">{{ $value }}</p>
        @isset($subtext)
            <div class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">{{ $subtext }}</div>
        @endisset
    </div>
</x-card>
