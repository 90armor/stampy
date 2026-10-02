@props(['variant' => 'primary', 'type' => 'button', 'href' => null])

@php
$base = 'inline-flex items-center justify-center gap-x-1.5 rounded-lg px-3.5 py-2 text-sm font-medium shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-800 disabled:cursor-not-allowed disabled:opacity-50';

$variants = [
    'primary' => 'bg-primary-600 text-white hover:bg-primary-700 active:bg-primary-800',
    'secondary' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-border hover:bg-slate-50 active:bg-slate-100 shadow-none dark:bg-slate-750 dark:text-slate-200 dark:hover:bg-slate-600 dark:active:bg-slate-500',
    'danger' => 'bg-white text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50 active:bg-red-100 shadow-none dark:bg-slate-750 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950 dark:active:bg-red-900/50',
];
@endphp

@if ($href)
    {{-- A navigational action styled identically to the button variants
    above (e.g. an error page's "Go to dashboard") — plain <a>, since <button>
    has no href of its own and this is the one place so far that needs to
    navigate rather than trigger wire:click/type="submit". --}}
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.$variants[$variant]]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $base.' '.$variants[$variant]]) }}>
        {{ $slot }}
    </button>
@endif
