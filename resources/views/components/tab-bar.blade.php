@props([
    // key => label, in display order.
    'tabs',
    // The nav's aria-label, e.g. "Policy sections".
    'label',
])

{{-- A page's section tabs (Organization, Policies). The page owns the state:
put this inside an x-data that has `tab`, and show each panel with
x-show="tab === '…'". On a phone the bar runs to the screen edges and scrolls
sideways: the active tab is scrolled into view on load and on every change,
and an edge fades while more tabs lie beyond it (tabBar in resources/js/app.js,
.tab-bar-scroller in resources/css/app.css). --}}
<div class="-mx-4 sm:mx-0">
    <div
        x-data="tabBar"
        @scroll.passive="measure()"
        class="tab-bar-scroller relative overflow-x-auto px-4 scroll-px-10 sm:px-0"
    >
        <nav aria-label="{{ $label }}" class="flex min-w-max border-b border-slate-divider">
            @foreach ($tabs as $section => $text)
                <button
                    type="button"
                    @click="tab = '{{ $section }}'"
                    :aria-current="tab === '{{ $section }}' ? 'page' : null"
                    :class="tab === '{{ $section }}'
                        ? 'border-primary-600 font-semibold text-primary-700 dark:border-primary-400 dark:text-primary-300'
                        : 'border-transparent font-medium text-slate-600 hover:border-slate-border hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm transition focus:outline-none focus-visible:relative focus-visible:z-10 focus-visible:rounded-t-lg focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500"
                >{{ $text }}</button>
            @endforeach
        </nav>
    </div>
</div>
