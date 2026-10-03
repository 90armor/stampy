@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'block h-control w-full rounded-lg border-slate-border bg-white text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500 dark:bg-slate-750 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-600']) }}>
    {{ $slot }}
</select>
