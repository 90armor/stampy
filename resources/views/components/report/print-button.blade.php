{{-- Beside a report's CSV export (Phase 6g, F04): prints the open tab only, on
light paper whatever the theme (docs/DESIGN_SYSTEM.md, Printing). --}}
<x-button type="button" variant="secondary" x-data="{}" x-on:click="window.print()">
    <x-icon name="printer" class="h-5 w-5" />
    Print
</x-button>
