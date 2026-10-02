@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'rounded-xl bg-white shadow-sm ring-1 ring-slate-200/60 dark:bg-slate-800 dark:ring-slate-750 '.($padding ? 'p-6' : '')]) }}>
    {{ $slot }}
</div>
