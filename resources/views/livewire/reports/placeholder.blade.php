{{-- A report tab that hasn't been opened yet (Phase 6c): the Reports page
computes only the tab it opens on, and each other one the first time it's
shown (the component's $opened, set by report-open). A local status in the
report's own card, not a skeleton (docs/DESIGN_SYSTEM.md, Loading states):
the copy carries the meaning, the spinner only supplements it. --}}
<div role="status" class="flex min-h-[16rem] items-center justify-center gap-3 rounded-xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-border dark:bg-slate-800 dark:text-slate-400">
    <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
        <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z" />
    </svg>
    <span>Loading the {{ $report }}…</span>
</div>
