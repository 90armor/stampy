@props(['name'])

{{-- A person's initial in the one neutral avatar (Phase 6h, F10): the size
comes from the caller (h-/w-/text- classes), the initial from
App\Support\Avatar — a whole grapheme, never half a character. Decorative:
the name is always next to it. --}}
<span {{ $attributes->class('flex shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-600 dark:bg-slate-750 dark:text-slate-300') }} aria-hidden="true">{{ \App\Support\Avatar::initial($name) }}</span>
