@props(['color' => 'slate'])

@php
$colors = [
    'green' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-400 dark:ring-green-500/30',
    'red' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400 dark:ring-red-500/30',
    'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-400 dark:ring-amber-500/30',
    'primary' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-900/40 dark:text-primary-300 dark:ring-primary-500/30',
    'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/10 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-500/20',
    // Added for Attendance's Incomplete status, so its table badge matches
    // the calendar/day-modal's violet instead of sharing Late's amber (see
    // CLAUDE.md's "Status colors" note). dark:text-violet-300 (not -400):
    // 9.06:1 measured against this badge's own composited background,
    // matching the other entries' actual-not-assumed contrast.
    'violet' => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-900/30 dark:text-violet-300 dark:ring-violet-500/30',
    // Added for Attendance's InProgress status. dark:text-blue-300 (not
    // -400): 9.07:1 measured against this badge's own composited
    // background, matching the other entries' actual-not-assumed contrast.
    'blue' => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-500/30',
    // Added for Attendance's Holiday status. dark:text-fuchsia-300 (not
    // -400): 9.33:1 measured the same way.
    'fuchsia' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20 dark:bg-fuchsia-900/30 dark:text-fuchsia-300 dark:ring-fuchsia-500/30',
    // Leave (Phase 3) — must not read as off/a weekend. Measured: 5.05:1 light (700 on 50), 10.30:1 dark (300 on 900/30 over the card).
    'accent' => 'bg-accent-50 text-accent-700 ring-accent-600/20 dark:bg-accent-900/30 dark:text-accent-300 dark:ring-accent-500/30',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.$colors[$color]]) }}>
    {{ $slot }}
</span>
