@props(['disabled' => false, 'surface' => 'glass'])

@php
$surfaceClasses = match ($surface) {
    'solid' => 'bg-white dark:bg-slate-800/70',
    default => 'bg-white/80 backdrop-blur-sm shadow-sm dark:bg-slate-800/70',
};
@endphp

<input @disabled($disabled) {{ $attributes->merge(['class' => "block w-full rounded-lg border-slate-300 $surfaceClasses text-sm text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-600"]) }}>
