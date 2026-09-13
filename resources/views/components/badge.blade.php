@props(['color' => 'slate'])

@php
$colors = [
    'green' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-400 dark:ring-green-500/30',
    'red' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400 dark:ring-red-500/30',
    'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-400 dark:ring-amber-500/30',
    'primary' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-900/40 dark:text-primary-300 dark:ring-primary-500/30',
    'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/10 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-500/20',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.$colors[$color]]) }}>
    {{ $slot }}
</span>
