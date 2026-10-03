{{-- Themed override of Livewire's default Tailwind pagination view
(vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php).
Behaviour and markup structure are unchanged; only the classes move from
Tailwind's stock cool gray/blue scales (which this app doesn't use and which
read as stray blue borders in dark mode) onto the design system's warm
slate neutrals, primary focus ring and control radius. The current page uses
the shared selected state (primary tint, primary text and border, semibold
— the same treatment as the filter chips and the Calendar/Table toggle), not
a solid fill, and carries aria-current="page" itself. --}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

// Surface colours live on each state, not on $item: a shared bg-white /
// border-slate-border out-ranked the current page's tint in Tailwind's CSS order,
// so the current page silently rendered as an ordinary white item.
$item = 'relative inline-flex h-9 items-center justify-center text-sm border';
$neutral = 'border-slate-border bg-white dark:bg-slate-750';
$enabled = $neutral.' font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus:z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 active:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-600 dark:hover:text-slate-100 dark:active:bg-slate-500';
$disabled = $neutral.' cursor-default font-medium text-slate-300 dark:text-slate-600';
$current = 'z-10 cursor-default font-semibold border-primary-600 bg-primary-50 text-primary-700 dark:border-primary-500 dark:bg-primary-600/35 dark:text-primary-200';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="flex items-center justify-between">
            <div class="flex justify-between flex-1 sm:hidden">
                <span>
                    @if ($paginator->onFirstPage())
                        <span class="{{ $item }} {{ $disabled }} rounded-lg px-4">
                            {!! __('pagination.previous') !!}
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.before" class="{{ $item }} {{ $enabled }} rounded-lg px-4">
                            {!! __('pagination.previous') !!}
                        </button>
                    @endif
                </span>

                <span>
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.before" class="{{ $item }} {{ $enabled }} ml-3 rounded-lg px-4">
                            {!! __('pagination.next') !!}
                        </button>
                    @else
                        <span class="{{ $item }} {{ $disabled }} ml-3 rounded-lg px-4">
                            {!! __('pagination.next') !!}
                        </span>
                    @endif
                </span>
            </div>

            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm leading-5 text-slate-600 dark:text-slate-400">
                        <span>{!! __('Showing') !!}</span>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->firstItem() }}</span>
                        <span>{!! __('to') !!}</span>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->lastItem() }}</span>
                        <span>{!! __('of') !!}</span>
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->total() }}</span>
                        <span>{!! __('results') !!}</span>
                    </p>
                </div>

                <div>
                    <span class="relative z-0 inline-flex rtl:flex-row-reverse rounded-lg shadow-sm">
                        <span>
                            {{-- Previous Page Link --}}
                            @if ($paginator->onFirstPage())
                                <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                                    <span class="{{ $item }} {{ $disabled }} w-9 rounded-l-lg" aria-hidden="true">
                                        <x-icon name="chevron-left" class="h-5 w-5" />
                                    </span>
                                </span>
                            @else
                                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $item }} {{ $enabled }} w-9 rounded-l-lg" aria-label="{{ __('pagination.previous') }}">
                                    <x-icon name="chevron-left" class="h-5 w-5" />
                                </button>
                            @endif
                        </span>

                        {{-- Pagination Elements --}}
                        @foreach ($elements as $element)
                            {{-- "Three Dots" Separator --}}
                            @if (is_string($element))
                                <span aria-disabled="true">
                                    <span class="{{ $item }} {{ $neutral }} -ml-px cursor-default px-3 font-medium text-slate-500 dark:text-slate-400">{{ $element }}</span>
                                </span>
                            @endif

                            {{-- Array Of Links --}}
                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                        @if ($page == $paginator->currentPage())
                                            <span aria-current="page" class="{{ $item }} {{ $current }} -ml-px min-w-9 px-3 tabular-nums">{{ $page }}</span>
                                        @else
                                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="{{ $item }} {{ $enabled }} -ml-px min-w-9 px-3 tabular-nums" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                                {{ $page }}
                                            </button>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        @endforeach

                        <span>
                            {{-- Next Page Link --}}
                            @if ($paginator->hasMorePages())
                                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $item }} {{ $enabled }} -ml-px w-9 rounded-r-lg" aria-label="{{ __('pagination.next') }}">
                                    <x-icon name="chevron-right" class="h-5 w-5" />
                                </button>
                            @else
                                <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                                    <span class="{{ $item }} {{ $disabled }} -ml-px w-9 rounded-r-lg" aria-hidden="true">
                                        <x-icon name="chevron-right" class="h-5 w-5" />
                                    </span>
                                </span>
                            @endif
                        </span>
                    </span>
                </div>
            </div>
        </nav>
    @endif
</div>
