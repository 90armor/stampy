@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm shadow-sm text-sm text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-600']) }}>
