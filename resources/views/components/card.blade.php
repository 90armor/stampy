@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'rounded-xl bg-white shadow-sm ring-1 ring-slate-border dark:bg-slate-800 '.($padding ? 'p-6' : '')]) }}>
    {{ $slot }}
</div>
