@props(['variant' => 'primary', 'type' => 'button'])

@php
$base = 'inline-flex items-center justify-center gap-x-1.5 rounded-lg px-3.5 py-2 text-sm font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 disabled:cursor-not-allowed';

$variants = [
    'primary' => 'bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-500',
    'secondary' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:ring-primary-500 shadow-none dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
    'danger' => 'bg-white text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50 focus:ring-red-500 shadow-none dark:bg-slate-800 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950',
];
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => $base.' '.$variants[$variant]]) }}>
    {{ $slot }}
</button>
