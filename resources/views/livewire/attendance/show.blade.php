@php
    // Colour comes from DailyAttendance::displayVariant() everywhere on this
    // page — "did they attend" (status) and "was the timing off" (late/early
    // minutes) are independent facts (see AttendanceStatus's doc comment),
    // so no view here keys colour off late_minutes/early_leave_minutes/
    // status directly; every lookup below is by variant key. badge: table
    // view/day-modal pill (x-badge's palette). icon/bg/text: the calendar
    // view's cells — status is never colour-only there (see the icon
    // choices below, each verified to keep a distinct silhouette in
    // greyscale, not just a different colour) EXCEPT 'timing', which
    // deliberately reuses 'present's check icon: the icon reflects
    // attendance (they showed up), not timing, so a late-arrival/early-leave
    // day still gets a check — the amber colour and the marked time (see
    // the "Marked times" note) are what carry the timing exception, not the
    // icon shape. bg/text stay within the app's existing green/amber/red/
    // slate/primary/accent palette rather than introducing new one-off
    // colours for holiday/leave.
    $variantStyles = [
        'present' => ['badge' => 'green', 'icon' => 'check', 'bg' => 'bg-green-50 dark:bg-green-900/20', 'text' => 'text-green-700 dark:text-green-400', 'ring' => 'ring-green-600/20 dark:ring-green-500/30'],
        'timing' => ['badge' => 'amber', 'icon' => 'check', 'bg' => 'bg-amber-50 dark:bg-amber-900/20', 'text' => 'text-amber-700 dark:text-amber-400', 'ring' => 'ring-amber-600/20 dark:ring-amber-500/30'],
        // A deliberate one-time addition to the palette — see CLAUDE.md's
        // "Status colors" note. A timing exception (employee behavior,
        // correct data) and Incomplete (a device defect — the person
        // worked, nothing recorded it) used to share amber and read as the
        // same thing in light mode. text-violet-700/violet-300 measured
        // 6.48:1 (light, on violet-50) and 9.43:1 (dark, on violet-900/20
        // over the card background) — -300, not -400, for the same reason
        // 'absent' uses red-300: picked against the actual measured ratio,
        // not assumed from the number.
        'incomplete' => ['badge' => 'violet', 'icon' => 'exclamation-triangle', 'bg' => 'bg-violet-50 dark:bg-violet-900/20', 'text' => 'text-violet-700 dark:text-violet-300', 'ring' => 'ring-violet-600/20 dark:ring-violet-500/40'],
        // dark:text-red-300, not -400: computed against this cell's actual
        // composited background (red-900/20 over the card's slate-900/60
        // over the page's slate-950), red-400 measured 6.23:1 — technically
        // AA-passing but well below green-400's 9.62:1 and amber-400's
        // 10.03:1 in the exact same recipe, which is why red alone read as
        // harder to see. red-300 measures 9.07:1 in the same computation,
        // back in line with its siblings.
        'absent' => ['badge' => 'red', 'icon' => 'x-mark', 'bg' => 'bg-red-50 dark:bg-red-900/20', 'text' => 'text-red-700 dark:text-red-300', 'ring' => 'ring-red-600/20 dark:ring-red-500/40'],
        'off' => ['badge' => 'slate', 'icon' => 'calendar-days', 'bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'ring' => 'ring-slate-500/10 dark:ring-slate-500/20'],
        // blue, not primary/evergreen: primary is still a green-family hue
        // (a different shade of the same "present" story present's own
        // stock-green already tells), which would repeat the exact
        // amber/violet confusability problem this app has already fixed
        // twice. Must not read as red or amber either — it means "not yet",
        // not a failure. 'clock' is free to reuse here since 'timing' (see
        // above) moved off it onto 'present's check. text-blue-700/blue-300
        // measured 6.16:1 (light, on blue-50) and 9.51:1 (dark, on
        // blue-900/20 over the card background).
        'in_progress' => ['badge' => 'blue', 'icon' => 'clock', 'bg' => 'bg-blue-50 dark:bg-blue-900/20', 'text' => 'text-blue-700 dark:text-blue-300', 'ring' => 'ring-blue-600/20 dark:ring-blue-500/30'],
        // fuchsia: doesn't collide with any hue already in use (green/amber/
        // violet/red/slate/blue, plus primary/accent's own green family).
        // text-fuchsia-700/fuchsia-300 measured 5.89:1 (light, on
        // fuchsia-50) and 9.79:1 (dark, on fuchsia-900/20 over the card
        // background).
        'holiday' => ['badge' => 'fuchsia', 'icon' => 'flag', 'bg' => 'bg-fuchsia-50 dark:bg-fuchsia-900/20', 'text' => 'text-fuchsia-700 dark:text-fuchsia-300', 'ring' => 'ring-fuchsia-600/20 dark:ring-fuchsia-500/30'],
        // Doesn't occur yet — nothing assigns Leave until Phase 3 — defined
        // now so the palette/icon exists, no lookup built beyond that.
        'leave' => ['badge' => 'accent', 'icon' => 'briefcase', 'bg' => 'bg-accent-50 dark:bg-accent-900/20', 'text' => 'text-accent-700 dark:text-accent-300', 'ring' => 'ring-accent-600/20 dark:ring-accent-500/30'],
    ];

    // Not a real AttendanceStatus (no daily_attendances row exists at all)
    // — deliberately not sharing 'off's calendar-days icon or 'absent's
    // x-mark: a flat dash has no shape overlap with either, so it can't be
    // mistaken for "did not work" or "day off" at a glance.
    $notCalculatedStyle = ['icon' => 'minus', 'bg' => 'bg-slate-50 dark:bg-slate-800/40', 'text' => 'text-slate-400 dark:text-slate-600', 'ring' => 'ring-slate-200 dark:ring-slate-700/60'];

    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // The variants that actually appear in the grid (holiday/leave are
    // defined but never rendered yet, so they're left out of the legend —
    // nothing to explain), MINUS 'timing': it deliberately reuses 'present's
    // check icon in the calendar (see the 'timing' variant style's comment
    // above), so an icon-based legend row for it would be a second entry
    // with the same icon shape as 'present', differing only in colour —
    // indistinguishable in greyscale, defeating the reason icons exist at
    // all. It gets its own non-icon entry below instead, combining the
    // amber colour with the underlined marked-time sample — the one visual
    // that actually IS distinct. Labels come from AttendanceStatus::label()
    // where a real status exists, so the legend can never drift from what a
    // cell actually says.
    $legendItems = [
        ['icon' => $variantStyles['present']['icon'], 'text' => $variantStyles['present']['text'], 'label' => \App\Enums\AttendanceStatus::Present->label()],
        ['icon' => $variantStyles['incomplete']['icon'], 'text' => $variantStyles['incomplete']['text'], 'label' => \App\Enums\AttendanceStatus::Incomplete->label()],
        ['icon' => $variantStyles['absent']['icon'], 'text' => $variantStyles['absent']['text'], 'label' => \App\Enums\AttendanceStatus::Absent->label()],
        ['icon' => $variantStyles['off']['icon'], 'text' => $variantStyles['off']['text'], 'label' => \App\Enums\AttendanceStatus::Off->label()],
        ['icon' => $variantStyles['in_progress']['icon'], 'text' => $variantStyles['in_progress']['text'], 'label' => \App\Enums\AttendanceStatus::InProgress->label()],
        ['icon' => $variantStyles['holiday']['icon'], 'text' => $variantStyles['holiday']['text'], 'label' => \App\Enums\AttendanceStatus::Holiday->label()],
        ['icon' => $notCalculatedStyle['icon'], 'text' => $notCalculatedStyle['text'], 'label' => 'Not calculated'],
    ];
@endphp

<div class="space-y-6">
    @if ($noEmployeeRecord)
        <x-card>
            <x-empty-state
                icon="user-x"
                title="Your account isn't linked to an employee record"
                description="There's nothing to show here yet — contact an admin to get this login linked to an employee profile."
            />
        </x-card>
    @else
        <div>
            <a href="{{ route('attendance.index') }}" wire:navigate class="inline-flex items-center gap-x-1 text-sm font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
                <x-icon name="chevron-left" class="h-4 w-4" />
                Back to attendance
            </a>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-x-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700 dark:bg-primary-800 dark:text-primary-100">
                    {{ strtoupper(substr($employee->full_name, 0, 1)) }}
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $employee->employee_code }} &middot; {{ $employee->department->name }} &middot; {{ $employee->position->name }}</p>
                </div>
            </div>
        </div>

        <x-card>
            {{-- Counts only — no derived/payroll-adjacent figures. Total
            worked is a plain sum of worked_minutes, not anything
            interpreted (e.g. no "deductible days" or hours owed). --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
                {{-- "Calculated workdays", not "Workdays" — this only counts
                days the builder has already processed (present+absent+
                incomplete rows that exist — a late/early day is already a
                present row, not a fourth category), not every scheduled
                workday in the month. A month that's only partly built would
                otherwise read as having far fewer workdays than it actually
                has. --}}
                <span class="text-slate-500 dark:text-slate-400">Calculated workdays <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['workdays'] }}</strong></span>
                {{-- Late/Early leave are a breakdown of Present, not peer
                figures — a late or early day IS a present day (see
                CLAUDE.md's "Status vs. timing" note), so showing them as
                separate side-by-side spans implied they were disjoint and
                summed to a total, which stopped being true once Late was
                removed as its own status. Same "of which" sub-line as the
                list page's Present stat card, for the same reason. --}}
                <span class="text-slate-500 dark:text-slate-400">
                    Present <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['present'] }}</strong>
                    @if ($summary['late'] > 0 || $summary['early_leave_days'] > 0)
                        <span class="text-xs text-slate-400 dark:text-slate-500">(of which
                            @if ($summary['late'] > 0)
                                {{ $summary['late'] }} late
                            @endif
                            @if ($summary['late'] > 0 && $summary['early_leave_days'] > 0)
                                &middot;
                            @endif
                            @if ($summary['early_leave_days'] > 0)
                                {{ $summary['early_leave_days'] }} left early
                            @endif
                        )</span>
                    @endif
                </span>
                <span class="text-slate-500 dark:text-slate-400">Absent <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['absent'] }}</strong></span>
                <span class="text-slate-500 dark:text-slate-400">Incomplete <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['incomplete'] }}</strong></span>
            </div>

            @if ($summary['total_worked_minutes'] > 0)
                <p class="mt-3 border-t border-slate-200/60 pt-3 text-xs text-slate-400 dark:border-slate-800/60 dark:text-slate-500">
                    Total worked this month: {{ sprintf('%dh %02dm', intdiv($summary['total_worked_minutes'], 60), $summary['total_worked_minutes'] % 60) }}
                </p>
            @endif

            @unless ($monthFullyBuilt)
                <div class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-500/30">
                    <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                    @if ($lastBuiltInMonth)
                        <span>
                            Attendance has only been calculated up to <strong>{{ \Illuminate\Support\Carbon::parse($lastBuiltInMonth)->format('M j, Y') }}</strong>.
                            The empty cells after that aren't missing punches — they simply haven't been processed yet.
                        </span>
                    @else
                        <span>Attendance hasn't been calculated for this month yet.</span>
                    @endif
                </div>
            @endunless
        </x-card>

        {{-- One card for the month filter (nav) and its content — matching
        Employees/Attendance\Index's "header row + content" pattern instead
        of a floating toggle above two separate cards. The view toggle lives
        in this same header row, top-right, same spot Employees puts "Add
        Employee" and Attendance\Index puts "Reset filters". --}}
        <x-card :padding="false">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        class="rounded-lg border border-slate-300 bg-white/80 p-1.5 text-slate-500 shadow-sm hover:bg-white hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Previous month"
                    >
                        <x-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <span class="min-w-[9rem] text-center text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $monthLabel }}</span>
                    <button
                        type="button"
                        wire:click="nextMonth"
                        class="rounded-lg border border-slate-300 bg-white/80 p-1.5 text-slate-500 shadow-sm hover:bg-white hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Next month"
                    >
                        <x-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @unless ($isCurrentMonth)
                        <button type="button" wire:click="$set('month', '{{ today()->format('Y-m') }}')" class="ml-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                            Jump to this month
                        </button>
                    @endunless
                </div>

                <div class="inline-flex rounded-lg border border-slate-300 bg-white/80 p-0.5 dark:border-slate-700 dark:bg-slate-800/70" role="group" aria-label="View">
                    <button
                        type="button"
                        wire:click="$set('view', 'calendar')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $view === 'calendar' ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' }}"
                        aria-pressed="{{ $view === 'calendar' ? 'true' : 'false' }}"
                    >
                        Calendar
                    </button>
                    <button
                        type="button"
                        wire:click="$set('view', 'table')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $view === 'table' ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' }}"
                        aria-pressed="{{ $view === 'table' ? 'true' : 'false' }}"
                    >
                        Table
                    </button>
                </div>
            </div>

        @if ($view === 'calendar')
            <div class="px-6 pb-6">
                <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                    @foreach ($weekdayLabels as $label)
                        <div class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            {{ $label }}
                        </div>
                    @endforeach

                    @foreach ($gridDays as $cell)
                        @php
                            $record = $cell['record'];
                            $cellDateKey = $cell['date']->format('Y-m-d');
                            // Read straight from the `holidays` table, not from
                            // $record — see holidaysByDate()'s doc comment for why
                            // (a future holiday has no daily_attendances row yet).
                            $holiday = $cell['inMonth'] ? $holidaysByDate->get($cellDateKey) : null;

                            // Marked times (see CLAUDE.md's "Marked times" note) point at
                            // the specific value that's out of range instead of just
                            // flagging the day. isLate()/leftEarly() are independent —
                            // both can be true the same day (see AttendanceStatus's doc
                            // comment on why timing isn't a status).
                            $lateArrival = $record && $record->isLate();
                            $earlyDeparture = $record && $record->leftEarly();

                            // Colour comes from displayVariant() — the one place that
                            // decides whether a Present day with a timing exception reads
                            // as 'present' (green) or 'timing' (amber). No re-deriving that
                            // here from late/early minutes directly.
                            if (! $cell['inMonth']) {
                                $style = null;
                            } elseif ($record) {
                                $style = $variantStyles[$record->displayVariant()];
                            } else {
                                $style = $notCalculatedStyle;
                            }

                            $statusLabel = $record ? $record->status->label() : 'Not calculated';
                            $lateMinutesLabel = $lateArrival ? $record->late_minutes.' minute'.($record->late_minutes === 1 ? '' : 's') : null;
                            $earlyMinutesLabel = $earlyDeparture ? $record->early_leave_minutes.' minute'.($record->early_leave_minutes === 1 ? '' : 's') : null;
                            $cellAriaLabel = $cell['date']->format('F j, Y').', '.$statusLabel
                                .($lateArrival ? ', arrived '.$lateMinutesLabel.' late' : '')
                                .($earlyDeparture ? ', left '.$earlyMinutesLabel.' early' : '')
                                .($holiday ? ', Holiday: '.$holiday->name : '');
                        @endphp

                        @if ($cell['inMonth'])
                            <button
                                type="button"
                                wire:click="openDay('{{ $cellDateKey }}')"
                                aria-label="{{ $cellAriaLabel }}"
                                {{-- hover/focus ring: unlike the app's other focus rings (inputs,
                                buttons), this one is ring-inset against the cell's OWN tinted
                                background, which is a flat pastel in light mode but a dark
                                translucent composite in dark mode — two very different
                                luminances. A single ring-primary-500 measured 4.2-4.4:1 against
                                the light cells but only 3.3-3.8:1 against the dark ones (checked
                                against every status cell's actual composited color, not assumed):
                                same hex, visibly weaker in dark mode. dark:*-primary-400 restores
                                4.9-5.7:1, back in the light mode's range — the same "one shade
                                lighter for dark" pattern already used for every status ring/text
                                pair in this file, not a one-off. --}}
                                class="group relative flex h-16 flex-col items-start gap-1 rounded-lg p-1.5 text-left ring-1 ring-inset transition hover:ring-2 hover:ring-primary-500 dark:hover:ring-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:focus-visible:ring-primary-400 sm:h-24 sm:p-2 {{ $style['bg'] }} {{ $style['ring'] }} {{ $cell['date']->isToday() ? 'ring-2 ring-primary-500' : '' }}"
                            >
                                <div class="flex w-full items-center justify-between">
                                    {{-- Day number is the largest, boldest thing in the
                                    cell; times below (when shown) are deliberately
                                    smaller and muted so the status icon — not the
                                    times — stays the primary signal. --}}
                                    <span class="text-sm font-bold sm:text-base {{ $style['text'] }}">{{ $cell['date']->day }}</span>
                                    <x-icon :name="$style['icon']" class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4 {{ $style['text'] }}" />
                                </div>
                                {{-- A holiday cell shows its name whatever the attendance
                                status is (or isn't, yet) — read from $holiday, not from
                                $record, so an upcoming holiday with no daily_attendances
                                row yet still renders. Deliberately not tinted with the
                                'holiday' variant's fuchsia here: that colour belongs to
                                $style (the cell background), which already reflects the
                                REAL attendance outcome (holiday/present/off/not
                                calculated) — this label is just the name, independent of
                                which of those the cell turned out to be. --}}
                                @if ($holiday)
                                    <span class="w-full truncate text-[10px] font-medium leading-tight text-fuchsia-700 dark:text-fuchsia-300" title="{{ $holiday->name }}">
                                        {{ $holiday->name }}
                                    </span>
                                @endif
                                {{-- Off and an unworked Holiday both show nothing below the
                                day number — no punches on a non-working day is expected, not
                                information worth a "—/—" placeholder (unlike Absent, where
                                the gap is meaningful). A worked holiday is 'present', not
                                'holiday' (see the builder's precedence order), so this never
                                hides real in/out times — only the no-punches holiday case,
                                which structurally has none to show anyway. --}}
                                @if ($record && $record->status->value !== 'off' && $record->status->value !== 'holiday')
                                    {{-- A plain "→" character, not an icon — the status icon is
                                    the one signal that matters; a marked time (see CLAUDE.md's
                                    "Marked times" note) points at the specific in/out value
                                    that's out of range instead of adding a second glyph. --}}
                                    <div class="hidden items-center gap-1 whitespace-nowrap text-[10px] leading-tight text-slate-500 dark:text-slate-400 sm:flex">
                                        @if ($record->first_in)
                                            @if ($lateArrival)
                                                <x-time :time="$record->first_in" class="text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400" aria-label="Arrived {{ $lateMinutesLabel }} late" />
                                            @else
                                                <x-time :time="$record->first_in" />
                                            @endif
                                        @else
                                            <span>—</span>
                                        @endif
                                        <span aria-hidden="true" class="opacity-60">&rarr;</span>
                                        @if ($record->last_out)
                                            @if ($earlyDeparture)
                                                <x-time :time="$record->last_out" class="text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400" aria-label="Left {{ $earlyMinutesLabel }} early" />
                                            @else
                                                <x-time :time="$record->last_out" />
                                            @endif
                                            @if ($record->isOvernightOut())<span class="opacity-70">(+1)</span>@endif
                                        @else
                                            <span>—</span>
                                        @endif
                                    </div>
                                @endif
                            </button>
                        @else
                            {{-- Leading/trailing days from adjacent months — muted,
                            not clickable, excluded from the month summary. They
                            exist only to keep the grid rectangular. --}}
                            <div
                                class="flex h-16 flex-col items-start gap-1 rounded-lg p-1.5 text-slate-300 dark:text-slate-700 sm:h-24 sm:p-2"
                                aria-hidden="true"
                            >
                                <span class="text-xs sm:text-sm">{{ $cell['date']->day }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Compact, wrapping legend — an icon whose meaning is unknown
                is worse than a colour, so this is a required companion to the
                grid, not decoration. flex-wrap keeps it from overflowing on
                narrow (mobile) widths; it just breaks onto more lines. --}}
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-slate-200/60 pt-3 text-xs text-slate-500 dark:border-slate-800/60 dark:text-slate-400">
                    @foreach ($legendItems as $item)
                        <span class="inline-flex items-center gap-1.5">
                            <x-icon :name="$item['icon']" class="h-3.5 w-3.5 shrink-0 {{ $item['text'] }}" />
                            {{ $item['label'] }}
                        </span>
                    @endforeach
                    {{-- The one entry for a timing exception, combining both of its
                    visual signals rather than splitting them across two legend rows
                    (which used to duplicate each other — "Late / Early leave" next
                    to a separate "Late arrival / early leave" sample). The swatch is
                    the same amber the cell background uses; the sample time carries
                    the actual underline — the shape cue that survives greyscale on
                    its own, independent of whether the colour reads at all. One
                    label covers both directions the marker appears (late arrival,
                    early leave). Goes through the real <x-time> component (not a
                    hardcoded "7:55 AM" string) so the sample honours
                    config('attendance.time_format') the same as every other time on
                    the page. --}}
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-3 w-3 shrink-0 rounded-sm {{ $variantStyles['timing']['bg'] }} ring-1 ring-inset {{ $variantStyles['timing']['ring'] }}" aria-hidden="true"></span>
                        <x-time :time="\Illuminate\Support\Carbon::createFromTime(7, 55)" class="text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400" />
                        Late / Early leave
                    </span>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right">Early leave</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">
                                Note
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                            </th>
                        </tr>
                    </thead>
                        @foreach ($days as $day)
                            @php
                                $record = $day['record'];
                                $dayKey = $day['date']->format('Y-m-d');
                                // Marked times (see CLAUDE.md's "Marked times" note) — the
                                // Status badge shows the real attendance status ("Present"),
                                // so the In/Out cells are where the specific late-arrival/
                                // early-leave discrepancy is pointed out, matching the
                                // calendar's convention.
                                $markedLate = $record && $record->isLate();
                                $markedEarly = $record && $record->leftEarly();
                                $markedTimeClass = 'text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400';
                            @endphp
                            {{-- A per-day <tbody> (valid HTML — a <table> may contain several),
                            not a single <tbody> for the month: the expand toggle and its detail
                            row below need to share one Alpine x-data scope, and sibling <tr>s
                            don't share scope unless a common ancestor carries it. --}}
                            <tbody wire:key="attendance-day-tbody-{{ $dayKey }}" x-data="{ open: false }">
                            <tr
                                class="relative {{ $day['date']->isToday() ? 'bg-primary-50/40 dark:bg-primary-900/10' : '' }}"
                            >
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    <div class="flex items-center gap-2">
                                        @can('update', $employee)
                                            <button
                                                type="button"
                                                @click="open = !open"
                                                :aria-expanded="open.toString()"
                                                aria-label="Show raw punches"
                                                class="shrink-0 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
                                            >
                                                <x-icon name="chevron-right" class="h-3.5 w-3.5 transition" x-bind:class="open ? 'rotate-90' : ''" />
                                            </button>
                                        @endcan
                                        <span>{{ $day['date']->format('D j M') }}</span>
                                    </div>
                                </td>
                                @if ($record)
                                    <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                        @if ($record->first_in)
                                            @if ($markedLate)
                                                <x-time :time="$record->first_in" class="{{ $markedTimeClass }}" aria-label="Arrived {{ $record->late_minutes }} minute{{ $record->late_minutes === 1 ? '' : 's' }} late" />
                                            @else
                                                <x-time :time="$record->first_in" />
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                        @if ($record->last_out)
                                            @if ($markedEarly)
                                                <x-time :time="$record->last_out" class="{{ $markedTimeClass }}" aria-label="Left {{ $record->early_leave_minutes }} minute{{ $record->early_leave_minutes === 1 ? '' : 's' }} early" />
                                            @else
                                                <x-time :time="$record->last_out" />
                                            @endif
                                            @if ($record->isOvernightOut())
                                                <span class="text-slate-400 dark:text-slate-500">(+1)</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->formattedWorkedMinutes() ?? '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->isLate() ? $record->late_minutes.'m' : '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->leftEarly() ? $record->early_leave_minutes.'m' : '—' }}</td>
                                    <td class="px-6 py-4">
                                        {{-- Status only — the adjacent Late/Early leave columns
                                        already show the minutes (aligned, scannable), and the
                                        marked In/Out times already point at which one; a third
                                        "Late 21m" chip here repeated the same fact and bloated
                                        row height. Colour still comes from displayVariant(), so
                                        a timing exception still reads amber, not green. --}}
                                        <x-badge :color="$variantStyles[$record->displayVariant()]['badge']">{{ $record->status->label() }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-500 dark:text-slate-400">
                                        {{ $record->note ?? '—' }}
                                        @unless ($loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                        @endunless
                                    </td>
                                @else
                                    {{-- No row at all — the builder hasn't reached this date yet.
                                    Deliberately distinct from "absent": absent means the builder
                                    ran and found no punches on a scheduled workday; this means
                                    it hasn't run at all, so nothing here should read as a
                                    judgement about attendance. --}}
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4">
                                        <x-badge color="slate">Not calculated</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">
                                        —
                                        @unless ($loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                            {{-- Kept admin-only for the whole row here (list included), unlike
                            the calendar's day modal where the punches list is visible to
                            anyone who can view the page — the brief for this table view was
                            explicit: keep it exactly as it was in Phase 2.4c. --}}
                            @can('update', $employee)
                                <tr x-show="open" x-cloak>
                                    <td colspan="8" class="bg-slate-50/60 px-6 py-4 dark:bg-slate-800/30">
                                        <x-attendance.day-detail-panel
                                            :employee="$employee"
                                            :date="$day['date']"
                                            :punches="$punchesByDate->get($dayKey, collect())"
                                            :adding-punch-for="$addingPunchFor"
                                            :new-punch-date="$newPunchDate"
                                            :new-punch-time="$newPunchTime"
                                            :new-punch-type="$newPunchType"
                                        />
                                    </td>
                                </tr>
                            @endcan
                            </tbody>
                        @endforeach
                </table>
            </div>
        @endif
        </x-card>

        {{-- Day-detail modal: opened from the calendar (openDay()), reuses the
        same day-detail-panel component the table's expand row uses, so the
        add/void logic — and its authorization — exists in exactly one place.
        Unlike the table's panel, the punches list here isn't admin-gated at
        the call site; only the panel's own add/void controls are (see the
        component). --}}
        <x-modal name="attendance-day-modal" entangle="dayModalOpen" maxWidth="lg">
            @if ($viewingDay)
                @php
                    $modalDate = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $viewingDay);
                    $modalRecord = $recordsByDate->get($viewingDay);
                    $modalStyle = $modalRecord ? $variantStyles[$modalRecord->displayVariant()] : $notCalculatedStyle;
                    // The schedule actually used for this day's calculation
                    // when a row exists (an employee's schedule can change
                    // over time, so this is more correct than "whatever
                    // their CURRENT schedule is"); falls back to the current
                    // effective schedule as a "this is what would apply"
                    // hint when nothing's been calculated yet.
                    $modalSchedule = $modalRecord?->workSchedule ?? $employee->effectiveSchedule();
                    $modalMarkedLate = $modalRecord && $modalRecord->isLate();
                    $modalMarkedEarly = $modalRecord && $modalRecord->leftEarly();
                    $markedTimeClass = 'text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400';
                    // Same holiday-name source as the calendar cell (read
                    // straight from `holidays`, not $modalRecord) — the
                    // modal is opened from a cell, so it should never say
                    // less about the day than the cell it came from.
                    $modalHoliday = $holidaysByDate->get($viewingDay);
                @endphp
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">{{ $modalDate->format('l') }}</p>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $modalDate->format('F j, Y') }}</h3>
                            @if ($modalHoliday)
                                <p class="mt-0.5 flex items-center gap-1 text-sm font-medium text-fuchsia-700 dark:text-fuchsia-300">
                                    <x-icon name="flag" class="h-3.5 w-3.5 shrink-0" />
                                    {{ $modalHoliday->name }}
                                </p>
                            @endif
                        </div>
                        {{-- Status pill shows the real attendance status ("Present"); a
                        timing exception is a separate amber chip alongside it, same
                        reasoning as the table's Status column (status and timing are
                        independent facts — see AttendanceStatus's doc comment). --}}
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $modalStyle['bg'] }} {{ $modalStyle['text'] }} {{ $modalStyle['ring'] }}">
                                <x-icon :name="$modalStyle['icon']" class="h-3.5 w-3.5" />
                                {{ $modalRecord ? $modalRecord->status->label() : 'Not calculated' }}
                            </span>
                            @if ($modalMarkedLate)
                                <x-badge color="amber">Late {{ $modalRecord->late_minutes }}m</x-badge>
                            @endif
                            @if ($modalMarkedEarly)
                                <x-badge color="amber">Early {{ $modalRecord->early_leave_minutes }}m</x-badge>
                            @endif
                        </div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 border-t border-slate-200/60 pt-4 dark:border-slate-800/60 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Schedule</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalSchedule)
                                    <x-time :time="\Carbon\Carbon::parse($modalSchedule->start_time)" />&nbsp;&ndash;&nbsp;<x-time :time="\Carbon\Carbon::parse($modalSchedule->end_time)" />
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">In</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalRecord?->first_in)
                                    @if ($modalMarkedLate)
                                        <x-time :time="$modalRecord->first_in" class="{{ $markedTimeClass }}" aria-label="Arrived {{ $modalRecord->late_minutes }} minute{{ $modalRecord->late_minutes === 1 ? '' : 's' }} late" />
                                    @else
                                        <x-time :time="$modalRecord->first_in" />
                                    @endif
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Out</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalRecord?->last_out)
                                    @if ($modalMarkedEarly)
                                        <x-time :time="$modalRecord->last_out" class="{{ $markedTimeClass }}" aria-label="Left {{ $modalRecord->early_leave_minutes }} minute{{ $modalRecord->early_leave_minutes === 1 ? '' : 's' }} early" />
                                    @else
                                        <x-time :time="$modalRecord->last_out" />
                                    @endif
                                    @if ($modalRecord->isOvernightOut())
                                        <span class="text-slate-400 dark:text-slate-500">(+1)</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Worked</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{{ $modalRecord?->formattedWorkedMinutes() ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Late</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{{ $modalMarkedLate ? $modalRecord->late_minutes.'m' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Early leave</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{{ $modalMarkedEarly ? $modalRecord->early_leave_minutes.'m' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Note</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{{ $modalRecord?->note ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                        <x-attendance.day-detail-panel
                            :employee="$employee"
                            :date="$modalDate"
                            :punches="$punchesByDate->get($viewingDay, collect())"
                            :adding-punch-for="$addingPunchFor"
                            :new-punch-date="$newPunchDate"
                            :new-punch-time="$newPunchTime"
                            :new-punch-type="$newPunchType"
                        />
                    </div>

                    <div class="mt-4 flex justify-end">
                        <x-button type="button" variant="secondary" wire:click="closeDayModal">Close</x-button>
                    </div>
                </div>
            @endif
        </x-modal>

        <x-confirm-dialog event="confirm-dialog-attendance-show" />
    @endif
</div>
