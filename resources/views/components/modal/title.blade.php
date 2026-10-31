{{-- A modal's visible title: its accessible name (<x-modal> points aria-labelledby
at this id), its heading for assistive technology (an h2 under the page's h1), and
where focus goes on a step change (docs/DESIGN_SYSTEM.md, Modals and focus). The
tabindex is rendered here rather than set by script, or the next Livewire render
would strip it from the focused element. look: the title's classes, for one that
doesn't use the standard ones. --}}
@aware(['name'])
@props(['look' => 'text-base font-semibold text-slate-900 dark:text-slate-100'])

<h2 id="{{ $name }}-title" tabindex="-1" data-modal-title {{ $attributes->class([$look, 'focus:outline-none']) }}>{{ $slot }}</h2>
