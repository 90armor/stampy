@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'bg-white/70 backdrop-blur-xl rounded-lg shadow-sm ring-1 ring-slate-200/60 transition hover:ring-primary-200/60 dark:bg-slate-900/60 dark:ring-slate-800/70 dark:hover:ring-primary-800/50 '.($padding ? 'p-6' : '')]) }}>
    {{ $slot }}
</div>
