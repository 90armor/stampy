<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-x-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white bg-red-600 shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition disabled:opacity-50']) }}>
    {{ $slot }}
</button>
