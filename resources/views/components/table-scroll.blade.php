@props([
    // Names the table for a screen reader: "Overtime report".
    'label',
])

{{-- Every table that can scroll sideways goes in this (docs/DESIGN_SYSTEM.md,
Tables): a keyboard user has to be able to focus the scroller to scroll it,
since a table may have no link or button in it to land on. Not inset: a ring
inside the box is painted under the opaque pinned cells. Other attributes —
pinnedColumns, scrollEdges, a margin — go on the same element. --}}
<div tabindex="0" role="region" aria-label="{{ $label }}" {{ $attributes->class('overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500') }}>
    {{ $slot }}
</div>
