@php
    // Colour comes from DailyAttendance::displayVariant() everywhere on this
    // page, and that variant is the attendance STATUS only — one value per
    // day. Timing (late arrival / early leave) can co-occur with Present, so
    // it is an annotation inside the cell (an amber marked time via
    // <x-time marked>), never the cell or badge colour: a late Present day is
    // a green cell with an amber time. See docs/ATTENDANCE_UI.md. badge:
    // table view/day-modal pill (x-badge's palette). icon/bg/text: the
    // calendar view's cells — status is never colour-only there (each icon
    // keeps a distinct silhouette in greyscale, not just a different colour).
    $variantStyles = [
        // Fill emphasizes exceptions: a Present cell is the neutral card
        // surface; the green day number and check carry the status. 'pill' is
        // the day modal's status pill, which stays a green badge.
        'present' => ['badge' => 'green', 'icon' => 'check', 'bg' => 'bg-white dark:bg-slate-800', 'text' => 'text-green-700 dark:text-green-400', 'ring' => 'ring-slate-divider', 'pill' => 'bg-green-50 ring-green-600/20 dark:bg-green-900/20 dark:ring-green-500/30'],
        // A deliberate one-time addition to the palette — see CLAUDE.md's
        // displayVariant() colour table. Incomplete (a device defect — the
        // person worked, nothing recorded it) must never read as amber, which
        // is reserved for the timing annotation. text-violet-700/violet-300 measured
        // 6.48:1 (light, on violet-50) and 7.83:1 (dark, on violet-900/20
        // over the card background) — -300, not -400, for the same reason
        // 'absent' uses red-300: picked against the actual measured ratio,
        // not assumed from the number.
        'incomplete' => ['badge' => 'violet', 'icon' => 'exclamation-triangle', 'bg' => 'bg-violet-50 dark:bg-violet-900/20', 'text' => 'text-violet-700 dark:text-violet-300', 'ring' => 'ring-violet-600/20 dark:ring-violet-500/40'],
        // dark:text-red-300, not -400: computed against this cell's actual
        // composited background (red-900/20 over the slate-800 card), red-400
        // measures 5.17:1 — AA-passing but well below green-400's 7.88:1 and
        // amber-400's 8.27:1 in the same recipe, which is why red alone read
        // as harder to see. red-300 measures 7.54:1, back in line with its
        // siblings.
        'absent' => ['badge' => 'red', 'icon' => 'x-mark', 'bg' => 'bg-red-50 dark:bg-red-900/20', 'text' => 'text-red-700 dark:text-red-300', 'ring' => 'ring-red-600/20 dark:ring-red-500/40'],
        // Off is the quietest cell in the grid, quieter than Present: no fill
        // (a grey fill was the heaviest surface in dark mode), a muted number
        // and a fainter icon, and a dashed boundary so the grid still reads.
        // The number stays slate-500 (4.8:1, AA) and drops to medium weight
        // rather than going paler.
        // 'pill' keeps the day modal's Off pill a normal slate badge.
        'off' => ['badge' => 'slate', 'icon' => 'calendar-days', 'bg' => 'bg-transparent', 'text' => 'text-slate-500 dark:text-slate-400', 'iconText' => 'text-slate-400 dark:text-slate-500', 'weight' => 'font-medium', 'ring' => 'ring-transparent border border-dashed border-slate-divider', 'pill' => 'bg-slate-100 ring-slate-500/10 dark:bg-slate-750 dark:ring-slate-500/20'],
        // blue, not primary/evergreen: primary is still a green-family hue
        // (a different shade of the same "present" story present's own
        // stock-green already tells), which would repeat the exact
        // amber/violet confusability problem this app has already fixed
        // twice. Must not read as red or amber either — it means "not yet",
        // not a failure. text-blue-700/blue-300
        // measured 6.16:1 (light, on blue-50) and 7.81:1 (dark, on
        // blue-900/20 over the card background).
        'in_progress' => ['badge' => 'blue', 'icon' => 'clock', 'bg' => 'bg-blue-50 dark:bg-blue-900/20', 'text' => 'text-blue-700 dark:text-blue-300', 'ring' => 'ring-blue-600/20 dark:ring-blue-500/30'],
        // fuchsia: doesn't collide with any hue already in use (green/amber/
        // violet/red/slate/blue, plus primary/accent's own green family).
        // text-fuchsia-700/fuchsia-300 measured 5.89:1 (light, on
        // fuchsia-50) and 8.13:1 (dark, on fuchsia-900/20 over the card
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
    $notCalculatedStyle = ['icon' => 'minus', 'bg' => 'bg-slate-50 dark:bg-slate-750/40', 'text' => 'text-slate-500 dark:text-slate-400', 'ring' => 'ring-slate-divider'];

    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // The variants that actually appear in the grid (holiday/leave are
    // defined but never rendered yet, so they're left out of the legend —
    // nothing to explain). Labels come from AttendanceStatus::label() where a
    // real status exists, so the legend can never drift from what a cell
    // actually says. Timing isn't a variant, so it has no icon row; its one
    // legend entry below is a sample amber marked time.
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
        @unless ($viaSelfView)
            <div>
                <a href="{{ route('attendance.index') }}" wire:navigate class="inline-flex items-center gap-x-1 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
                    <x-icon name="chevron-left" class="h-5 w-5" />
                    Back to attendance
                </a>
            </div>
        @endunless

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-x-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-lg font-semibold bg-slate-100 text-slate-600 dark:bg-slate-750 dark:text-slate-300">
                    {{ strtoupper(substr($employee->full_name, 0, 1)) }}
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</h1>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $employee->employee_code }} &middot; {{ $employee->department->name }} &middot; {{ $employee->position->name }}</p>
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
                incomplete rows that exist, plus leave days since Phase 3d —
                a late/early day is already a present row, not a fourth
                category), not every scheduled
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
                    {{-- Built as one expression rather than interleaved @if/@endif
                    directives around static "(...)" text: Blade passes the raw
                    HTML between directives through untouched, including the
                    newlines/indentation this partial's source has around each
                    @if — the browser then collapses that whitespace to a single
                    space, landing right before the closing ")" ("...left early
                    )"). One {{ }} expression, built here, sandwiched directly
                    between literal "(of which " and ")" with no raw HTML in
                    between, has no such gap to collapse. --}}
                    @if ($summary['late'] > 0 || $summary['early_leave_days'] > 0)
                        @php
                            $timingParts = array_filter([
                                $summary['late'] > 0 ? $summary['late'].' late' : null,
                                $summary['early_leave_days'] > 0 ? $summary['early_leave_days'].' left early' : null,
                            ]);
                        @endphp
                        <span class="text-xs text-slate-500 dark:text-slate-400">(of which {{ implode(' · ', $timingParts) }})</span>
                    @endif
                </span>
                <span class="text-slate-500 dark:text-slate-400">Absent <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['absent'] }}</strong></span>
                <span class="text-slate-500 dark:text-slate-400">
                    Incomplete <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['incomplete'] }}</strong>
                    {{-- Late annotates its own status group, the same way as
                    Present's "(of which …)" (docs/ATTENDANCE_UI.md). --}}
                    @if ($summary['incomplete_late'] > 0)
                        <span class="text-xs text-slate-500 dark:text-slate-400">({{ $summary['incomplete_late'] }} late)</span>
                    @endif
                </span>
                {{-- Status counts only, so Present + Absent + Incomplete + On leave
                is always Calculated workdays (Phase 3e). How much leave was
                taken is a different measure — half days, and days worked on
                leave — so it gets its own line below, never this sum. --}}
                @if ($summary['leave'] > 0)
                    <span class="text-slate-500 dark:text-slate-400">On leave <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['leave'] }}</strong></span>
                @endif
            </div>

            @if ($summary['leave_tenths'] > 0)
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    Leave taken: <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ \App\Support\LeaveDays::format($summary['leave_tenths']) }} {{ $summary['leave_tenths'] === \App\Support\LeaveDays::DAY ? 'day' : 'days' }}</strong>
                    <span class="text-xs">· counted separately from the workday figures above (half days count 0.5)</span>
                </p>
            @endif

            @if ($summary['total_worked_minutes'] > 0)
                <p class="mt-3 border-t border-slate-divider pt-3 text-xs text-slate-500 dark:text-slate-400">
                    Total worked this month: {{ \App\Support\Duration::format($summary['total_worked_minutes']) }}
                </p>
            @endif

            @unless ($monthFullyBuilt)
                <div class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-500/30">
                    <x-icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                    @if ($lastBuiltInMonth)
                        <span>
                            Attendance has only been calculated up to <strong>{{ \App\Support\DisplayDate::compact(\Illuminate\Support\Carbon::parse($lastBuiltInMonth)) }}</strong>.
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
                        class="inline-flex h-control w-control items-center justify-center rounded-lg border border-slate-border bg-white text-slate-500 shadow-sm transition hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-slate-750 dark:text-slate-400 dark:hover:bg-slate-600"
                        aria-label="Previous month"
                    >
                        <x-icon name="chevron-left" class="h-5 w-5" />
                    </button>
                    <span class="min-w-[9rem] text-center text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $monthLabel }}</span>
                    <button
                        type="button"
                        wire:click="nextMonth"
                        class="inline-flex h-control w-control items-center justify-center rounded-lg border border-slate-border bg-white text-slate-500 shadow-sm transition hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-slate-750 dark:text-slate-400 dark:hover:bg-slate-600"
                        aria-label="Next month"
                    >
                        <x-icon name="chevron-right" class="h-5 w-5" />
                    </button>
                    @unless ($isCurrentMonth)
                        <button type="button" wire:click="$set('month', '{{ today()->format('Y-m') }}')" class="ml-1 inline-flex min-h-7 items-center rounded-lg px-2 text-xs font-medium text-primary-600 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-300">
                            Jump to this month
                        </button>
                    @endunless
                </div>

                {{-- One control: the container is h-control and rounded-lg; its two segments
                are rounded-lg too, on the radius scale (docs/DESIGN_SYSTEM.md, Control height). --}}
                <div class="inline-flex h-control items-stretch rounded-lg border border-slate-border bg-white p-0.5 dark:bg-slate-750" role="group" aria-label="View">
                    <button
                        type="button"
                        wire:click="$set('view', 'calendar')"
                        class="inline-flex items-center rounded-lg px-3 text-sm ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $view === 'calendar' ? 'font-semibold bg-primary-50 text-primary-700 ring-primary-600 dark:bg-primary-600/35 dark:text-primary-200 dark:ring-primary-500' : 'font-medium ring-transparent text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' }}"
                        aria-pressed="{{ $view === 'calendar' ? 'true' : 'false' }}"
                    >
                        Calendar
                    </button>
                    <button
                        type="button"
                        wire:click="$set('view', 'table')"
                        class="inline-flex items-center rounded-lg px-3 text-sm ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $view === 'table' ? 'font-semibold bg-primary-50 text-primary-700 ring-primary-600 dark:bg-primary-600/35 dark:text-primary-200 dark:ring-primary-500' : 'font-medium ring-transparent text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' }}"
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
                        <div class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
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

                            // Colour comes from displayVariant(), which is status-only:
                            // a Present day with a timing exception is still a green
                            // 'present' cell; the marked time below carries the timing.
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
                            // Leave annotations (Phase 3e): the half on a half-day leave day,
                            // and punches in leave time — never the cell colour.
                            $cellHalf = $record && $record->leaveDay()->isHalfDay() ? $record->leaveDay()->half->label() : null;
                            $cellWorkedOnLeave = $record && $record->workedOnLeave();
                            $cellAriaLabel = \App\Support\DisplayDate::long($cell['date']).', '.$statusLabel
                                .($lateArrival ? ', arrived '.$lateMinutesLabel.' late' : '')
                                .($earlyDeparture ? ', left '.$earlyMinutesLabel.' early' : '')
                                .($cellHalf ? ', '.$cellHalf.' leave' : '')
                                .($cellWorkedOnLeave ? ', worked on leave' : '')
                                .($holiday ? ', Holiday: '.$holiday : '');
                        @endphp

                        @if ($cell['inMonth'])
                            <button
                                type="button"
                                wire:click="openDay('{{ $cellDateKey }}')"
                                aria-label="{{ $cellAriaLabel }}"
                                @if ($cell['date']->isToday()) aria-current="date" @endif
                                {{-- hover/focus ring: ring-inset against the cell's own
                                background, which is a flat surface in light mode but a dark
                                translucent composite in dark mode. ring-primary-500 measured
                                4.2-4.4:1 against the light cells but only 3.3-3.8:1 against the
                                dark ones, so dark mode uses primary-400 (4.9-5.7:1).

                                Today is marked by the filled circle behind the day number, not
                                a cell border: a border read as another status ring. The cell
                                height is a minimum, not fixed, so 12px times can wrap onto a
                                second line in narrow cells instead of clipping. --}}
                                class="group relative flex min-h-16 flex-col items-start gap-1 rounded-lg p-1.5 text-left ring-1 ring-inset transition hover:ring-2 hover:ring-primary-500 dark:hover:ring-primary-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:focus-visible:ring-primary-400 sm:min-h-24 sm:p-2 {{ $style['bg'] }} {{ $style['ring'] }}"
                            >
                                {{-- Below sm a cell is ~44px wide: the day number and the
                                status icon stack instead of colliding side by side. --}}
                                <div class="flex w-full flex-col items-start gap-0.5 sm:flex-row sm:items-center sm:justify-between">
                                    {{-- Day number is the largest, boldest thing in the
                                    cell; times below (when shown) are deliberately
                                    smaller and muted so the status icon — not the
                                    times — stays the primary signal. --}}
                                    @if ($cell['date']->isToday())
                                        {{-- The shared filled selected state: primary-600 + white
                                        (6.53:1) in light mode; dark primary-500 + white (4.57:1
                                        for the digits, 3.26:1 for the circle against the card). --}}
                                        <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-primary-600 px-1 text-sm font-bold text-white sm:h-7 sm:min-w-7 sm:text-base dark:bg-primary-500">{{ $cell['date']->day }}</span>
                                    @else
                                        <span class="text-sm sm:text-base {{ $style['weight'] ?? 'font-bold' }} {{ $style['text'] }}">{{ $cell['date']->day }}</span>
                                    @endif
                                    <x-icon :name="$style['icon']" class="h-5 w-5 shrink-0 sm:h-4 sm:w-4 {{ $style['iconText'] ?? $style['text'] }}" />
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
                                    {{-- line-clamp-2, not truncate (1 line): a name like "Company
                                    Anniversary (demo)" fits in two short lines at this width but
                                    not one, and a single-line ellipsis was cutting off names that
                                    didn't need to be cut at all. Still bounded — a genuinely long
                                    name clamps with an ellipsis on the 2nd line rather than
                                    growing the cell — and title= stays as a full-text fallback for
                                    whatever's still cut off; @title doesn't need a touch/at-rest
                                    equivalent the way an interactive control would (1.2's rule),
                                    since it's supplementary here, not the only way to read the
                                    name — line-clamp already shows as much as fits. --}}
                                    {{-- Below sm there is no room for the name without clipping it,
                                    so the cell shows a small flag instead (the name stays in the
                                    cell's accessible label and in the day modal). A cell whose
                                    status icon is already the holiday flag needs no second one. --}}
                                    <span class="hidden w-full line-clamp-2 text-[10px] font-medium leading-tight text-fuchsia-700 dark:text-fuchsia-300 sm:block" title="{{ $holiday }}">
                                        {{ $holiday }}
                                    </span>
                                    @unless ($style['icon'] === 'flag')
                                        <x-icon name="flag" class="h-5 w-5 shrink-0 text-fuchsia-700 dark:text-fuchsia-300 sm:hidden" />
                                    @endunless
                                @endif
                                {{-- Leave annotations: muted (slate-600 on the tinted fills), 10px
                                like the holiday name; below sm just the half ("AM"), where a
                                full phrase can't fit — the cell's label and the day modal
                                say the rest. --}}
                                @if ($cellHalf || $cellWorkedOnLeave)
                                    <span class="hidden w-full text-[10px] font-medium leading-tight text-slate-600 dark:text-slate-400 sm:block">{{ implode(' · ', array_filter([$cellHalf ? $cellHalf.' leave' : null, $cellWorkedOnLeave ? 'Worked on leave' : null])) }}</span>
                                    @if ($cellHalf)
                                        <span class="text-[10px] font-medium leading-tight text-slate-600 dark:text-slate-400 sm:hidden" aria-hidden="true">{{ $cellHalf }}</span>
                                    @endif
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
                                    the one signal that matters; a marked time (amber, see
                                    <x-time marked>) points at the specific in/out value that's
                                    out of range instead of adding a second glyph. --}}
                                    {{-- slate-600, not the card's slate-500: on the tinted exception fills
                                    slate-500 measures 4.37–4.47:1 (docs/DESIGN_SYSTEM.md, Muted text rule). --}}
                                    <div class="hidden flex-wrap items-center gap-x-1 text-xs leading-4 text-slate-600 dark:text-slate-400 sm:flex">
                                        @if ($record->first_in)
                                            @if ($lateArrival)
                                                <x-time :time="$record->first_in" marked aria-label="Arrived {{ $lateMinutesLabel }} late" />
                                            @else
                                                <x-time :time="$record->first_in" />
                                            @endif
                                        @else
                                            <span>—</span>
                                        @endif
                                        <span aria-hidden="true" class="opacity-60">&rarr;</span>
                                        @if ($record->last_out)
                                            @if ($earlyDeparture)
                                                <x-time :time="$record->last_out" marked aria-label="Left {{ $earlyMinutesLabel }} early" />
                                            @else
                                                <x-time :time="$record->last_out" />
                                            @endif
                                            @if ($record->isOvernightOut())<span>(+1)</span>@endif
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
                                class="flex min-h-16 flex-col items-start gap-1 rounded-lg p-1.5 text-slate-300 dark:text-slate-700 sm:min-h-24 sm:p-2"
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
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-slate-divider pt-3 text-xs text-slate-500 dark:text-slate-400">
                    @foreach ($legendItems as $item)
                        <span class="inline-flex items-center gap-1.5">
                            <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0 {{ $item['text'] }}" />
                            {{ $item['label'] }}
                        </span>
                    @endforeach
                    {{-- The one entry for a timing exception: a sample marked time,
                    the same amber annotation a real late In / early Out carries
                    inside an otherwise status-coloured cell. No swatch — timing
                    never colours a cell. Goes through the real <x-time> component
                    (not a hardcoded "7:55 AM" string) so the sample honours
                    config('attendance.time_format') like every other time on the
                    page. --}}
                    <span class="inline-flex items-center gap-1.5">
                        <x-time :time="\Illuminate\Support\Carbon::createFromTime(7, 55)" marked />
                        Late / Early leave
                    </span>
                </div>
            </div>
        @else
            @php
                // Same table rules as Daily Attendance (docs/ATTENDANCE_UI.md),
                // with its own column order (Date and Status first — there is no
                // Employee column and no pinning): nowrap headers, muted em dashes, and one
                // per-row affordance at the end of the row. Here that affordance
                // is the admin-only raw-punches toggle (a disclosure button, not a
                // link — this page already is the employee's attendance detail).
                $canManagePunches = auth()->user()->can('update', $employee);
                $emDash = '<span class="text-slate-300 dark:text-slate-600">—</span>';
            @endphp
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="relative whitespace-nowrap text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right"><abbr title="Early leave" class="no-underline">Early</abbr></th>
                            <th @class(['py-3', 'px-6' => ! $canManagePunches, 'pl-6 pr-2' => $canManagePunches])>
                                Note
                                @unless ($canManagePunches)
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
                                @endunless
                            </th>
                            @if ($canManagePunches)
                                <th class="py-3 pl-2 pr-6">
                                    <span class="sr-only">Raw punches</span>
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
                                </th>
                            @endif
                        </tr>
                    </thead>
                        @foreach ($days as $day)
                            @php
                                $record = $day['record'];
                                $dayKey = $day['date']->format('Y-m-d');
                                $dayLabel = \App\Support\DisplayDate::compact($day['date']);
                                // Same rule as the Daily Attendance table: In/Out stay
                                // neutral, and the timing fact is the amber Late/Early value.
                                $markedLate = $record && $record->isLate();
                                $markedEarly = $record && $record->leftEarly();
                            @endphp
                            {{-- A per-day <tbody> (valid HTML — a <table> may contain several),
                            not a single <tbody> for the month: the expand toggle and its detail
                            row below need to share one Alpine x-data scope, and sibling <tr>s
                            don't share scope unless a common ancestor carries it. --}}
                            <tbody wire:key="attendance-day-tbody-{{ $dayKey }}" x-data="{ open: false }">
                            <tr
                                class="relative hover:bg-slate-50 dark:hover:bg-slate-750/60 {{ $day['date']->isToday() ? 'bg-primary-50/40 dark:bg-primary-900/10' : '' }}"
                            >
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums text-slate-700 dark:text-slate-300">{{ $dayLabel }}</td>
                                @if ($record)
                                    <td class="px-6 py-2">
                                        {{-- Status only — the Late/Early columns already show the
                                        timing (amber); a "Late 21m" chip here repeated the same
                                        fact. Colour comes from the status-only displayVariant(). --}}
                                        <x-badge :color="$variantStyles[$record->displayVariant()]['badge']">{{ $record->status->label() }}</x-badge>
                                        <x-attendance.annotations :record="$record" :holiday="$holidaysByDate->get($dayKey)" />
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                        @if ($record->first_in)
                                            <x-time :time="$record->first_in" />
                                        @else
                                            {!! $emDash !!}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                        @if ($record->last_out)
                                            <x-time :time="$record->last_out" />
                                            @if ($record->isOvernightOut())
                                                <span class="text-slate-500 dark:text-slate-400">(+1)</span>
                                            @endif
                                        @else
                                            {!! $emDash !!}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums text-slate-700 dark:text-slate-300">{!! e($record->formattedWorkedMinutes()) ?: $emDash !!}</td>
                                    <td @class(['whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums', 'font-medium text-amber-700 dark:text-amber-300' => $markedLate])>{!! e($record->formattedLateMinutes()) ?: $emDash !!}</td>
                                    <td @class(['whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums', 'font-medium text-amber-700 dark:text-amber-300' => $markedEarly])>{!! e($record->formattedEarlyLeaveMinutes()) ?: $emDash !!}</td>
                                    <td @class(['py-2 text-sm text-slate-500 dark:text-slate-400', 'px-6' => ! $canManagePunches, 'pl-6 pr-2' => $canManagePunches])>
                                        {{-- The note, and the holiday's name on a holiday — why its late/early are zero. --}}
                                        @php $noteText = implode(' · ', array_filter([$holidaysByDate->get($dayKey), $record->note])); @endphp
                                        {!! $noteText !== '' ? e($noteText) : $emDash !!}
                                        @if (! $canManagePunches && ! $loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
                                        @endif
                                    </td>
                                @else
                                    {{-- No row at all — the builder hasn't reached this date yet.
                                    Deliberately distinct from "absent": absent means the builder
                                    ran and found no punches on a scheduled workday; this means
                                    it hasn't run at all, so nothing here should read as a
                                    judgement about attendance. --}}
                                    <td class="px-6 py-2">
                                        <x-badge color="slate">Not calculated</x-badge>
                                    </td>
                                    <td class="px-6 py-2 text-sm">{!! $emDash !!}</td>
                                    <td class="px-6 py-2 text-sm">{!! $emDash !!}</td>
                                    <td class="px-6 py-2 text-right text-sm">{!! $emDash !!}</td>
                                    <td class="px-6 py-2 text-right text-sm">{!! $emDash !!}</td>
                                    <td class="px-6 py-2 text-right text-sm">{!! $emDash !!}</td>
                                    <td @class(['py-2 text-sm', 'px-6' => ! $canManagePunches, 'pl-6 pr-2' => $canManagePunches])>
                                        {!! $emDash !!}
                                        @if (! $canManagePunches && ! $loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
                                        @endif
                                    </td>
                                @endif
                                @if ($canManagePunches)
                                    <td class="py-2 pl-2 pr-6 text-right">
                                        <button
                                            type="button"
                                            @click="open = !open"
                                            :aria-expanded="open.toString()"
                                            aria-label="Show raw punches for {{ $dayLabel }}"
                                            class="-my-1 ml-auto inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-300"
                                        >
                                            <x-icon name="chevron-right" class="h-5 w-5 transition" x-bind:class="open ? 'rotate-90' : ''" />
                                        </button>
                                        @unless ($loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
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
                                    <td colspan="9" class="bg-slate-50/60 px-6 py-4 dark:bg-slate-750/30">
                                        <x-attendance.day-detail-panel
                                            :employee="$employee"
                                            :date="$day['date']"
                                            :punches="$punchesByDate->get($dayKey, collect())"
                                            :overnight-out="$overnightPunches['outs']->get($dayKey)"
                                            :overnight-shifts="$overnightPunches['shifts']"
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
                    // when a row exists; falls back to scheduleOn() for this
                    // same date as a "this is what would apply" hint when
                    // nothing's been calculated yet — not necessarily the
                    // employee's CURRENT schedule, if they've since been
                    // reassigned effective some other date.
                    $modalSchedule = $modalRecord?->workSchedule ?? $employee->scheduleOn($modalDate);
                    $modalMarkedLate = $modalRecord && $modalRecord->isLate();
                    $modalMarkedEarly = $modalRecord && $modalRecord->leftEarly();
                    // Same holiday-name source as the calendar cell (read
                    // straight from `holidays`, not $modalRecord) — the
                    // modal is opened from a cell, so it should never say
                    // less about the day than the cell it came from.
                    $modalHoliday = $holidaysByDate->get($viewingDay);
                    // The table's empty-value dash: muted, not the value colour.
                    $modalDash = '<span class="text-slate-300 dark:text-slate-600">—</span>';
                @endphp
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ \App\Support\DisplayDate::long($modalDate) }}</h3>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $employee->full_name }}</p>
                            @if ($modalHoliday)
                                <p class="mt-0.5 flex items-center gap-1 text-sm font-medium text-fuchsia-700 dark:text-fuchsia-300">
                                    <x-icon name="flag" class="h-5 w-5 shrink-0" />
                                    {{ $modalHoliday }}
                                </p>
                            @endif
                        </div>
                        {{-- Status pill shows the real attendance status ("Present"), coloured by
                        the status-only displayVariant() — a late day is a green "Present". The
                        Late / Early leave fields below (and the amber marked In/Out times)
                        carry the timing; a chip here would just repeat them. --}}
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $modalStyle['pill'] ?? $modalStyle['bg'].' '.$modalStyle['ring'] }} {{ $modalStyle['text'] }}">
                                <x-icon :name="$modalStyle['icon']" class="h-3.5 w-3.5" />
                                {{ $modalRecord ? $modalRecord->status->label() : 'Not calculated' }}
                            </span>
                        </div>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-5 border-t border-slate-divider pt-5 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Schedule</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalSchedule)
                                    <x-time :time="\Carbon\Carbon::parse($modalSchedule->start_time)" />&nbsp;&ndash;&nbsp;<x-time :time="\Carbon\Carbon::parse($modalSchedule->end_time)" />
                                @else
                                    {!! $modalDash !!}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">In</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalRecord?->first_in)
                                    @if ($modalMarkedLate)
                                        <x-time :time="$modalRecord->first_in" marked aria-label="Arrived {{ $modalRecord->late_minutes }} minute{{ $modalRecord->late_minutes === 1 ? '' : 's' }} late" />
                                    @else
                                        <x-time :time="$modalRecord->first_in" />
                                    @endif
                                @else
                                    {!! $modalDash !!}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Out</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">
                                @if ($modalRecord?->last_out)
                                    @if ($modalMarkedEarly)
                                        <x-time :time="$modalRecord->last_out" marked aria-label="Left {{ $modalRecord->early_leave_minutes }} minute{{ $modalRecord->early_leave_minutes === 1 ? '' : 's' }} early" />
                                    @else
                                        <x-time :time="$modalRecord->last_out" />
                                    @endif
                                    @if ($modalRecord->isOvernightOut())
                                        <span class="text-slate-500 dark:text-slate-400">(+1)</span>
                                    @endif
                                @else
                                    {!! $modalDash !!}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Worked</dt>
                            <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{!! e($modalRecord?->formattedWorkedMinutes()) ?: $modalDash !!}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Late</dt>
                            <dd @class(['mt-0.5 text-sm', 'font-medium text-amber-700 dark:text-amber-300' => $modalMarkedLate, 'text-slate-900 dark:text-slate-100' => ! $modalMarkedLate])>{!! e($modalRecord?->formattedLateMinutes()) ?: $modalDash !!}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Early leave</dt>
                            <dd @class(['mt-0.5 text-sm', 'font-medium text-amber-700 dark:text-amber-300' => $modalMarkedEarly, 'text-slate-900 dark:text-slate-100' => ! $modalMarkedEarly])>{!! e($modalRecord?->formattedEarlyLeaveMinutes()) ?: $modalDash !!}</dd>
                        </div>
                        {{-- Only when there is one: an em-dash row says nothing. --}}
                        @if (filled($modalRecord?->note))
                            <div class="col-span-full">
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Note</dt>
                                <dd class="mt-0.5 text-sm text-slate-900 dark:text-slate-100">{{ $modalRecord->note }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- Every approved leave covering the day (an AM and a PM can be two
                    types), with what it charged that day — LeaveDayCounter's answer,
                    so a holiday or day off inside Annual says "Not charged" and
                    Maternity's calendar days say "Charged". --}}
                    @if ($dayLeaves !== [])
                        @php
                            $viewer = auth()->user();
                            // Gated by each destination's own ability: the profile's Leave
                            // card for whoever may open the profile, else the employee's own
                            // Time off.
                            [$leaveLinkUrl, $leaveLinkLabel] = match (true) {
                                $viewer->can('viewAny', \App\Models\Employee::class) && $viewer->can('view', $employee) => [route('employees.show', $employee), 'View on profile'],
                                $viewer->employee?->is($employee) && $viewer->can('timeOff', \App\Models\Leave::class) => [route('time-off.index'), 'View in Time off'],
                                default => [null, null],
                            };
                        @endphp
                        <div class="mt-5 border-t border-slate-divider pt-4">
                            <h4 class="text-xs font-medium text-slate-500 dark:text-slate-400">Leave</h4>
                            <ul class="mt-2 space-y-2">
                                @foreach ($dayLeaves as $item)
                                    @php
                                        $dayLeave = $item['leave'];
                                        $chargedText = $item['charged'] > 0
                                            ? 'Charged '.\App\Support\LeaveDays::label($item['charged'])
                                            : 'Not charged — '.($modalHoliday ? 'holiday' : 'day off');
                                    @endphp
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $dayLeave->leaveType->name.($dayLeave->half ? ' · '.$dayLeave->half->label() : '') }}</p>
                                            {{-- The half is in the title already; this line is the leave's dates. --}}
                                            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ ($dayLeave->half ? \App\Support\DisplayDate::compact($dayLeave->start_date) : $dayLeave->displayDates()).' · '.$chargedText }}</p>
                                        </div>
                                        @if ($leaveLinkUrl)
                                            <a href="{{ $leaveLinkUrl }}" wire:navigate class="rounded text-sm font-medium text-primary-700 underline decoration-primary-300 decoration-1 underline-offset-2 hover:decoration-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400">{{ $leaveLinkLabel }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if ($modalRecord?->workedOnLeave())
                                <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">Worked on leave: there are punches in leave time. The leave stands unless an admin cancels it.</p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-5 border-t border-slate-divider pt-4">
                        <x-attendance.day-detail-panel
                            :employee="$employee"
                            :date="$modalDate"
                            :punches="$punchesByDate->get($viewingDay, collect())"
                            :overnight-out="$overnightPunches['outs']->get($viewingDay)"
                            :overnight-shifts="$overnightPunches['shifts']"
                            :adding-punch-for="$addingPunchFor"
                            :new-punch-date="$newPunchDate"
                            :new-punch-time="$newPunchTime"
                            :new-punch-type="$newPunchType"
                        />
                    </div>

                    <div class="mt-5 flex justify-end border-t border-slate-divider pt-4">
                        <x-button type="button" variant="secondary" wire:click="closeDayModal">Close</x-button>
                    </div>
                </div>
            @endif
        </x-modal>

        <x-confirm-dialog event="confirm-dialog-attendance-show" />
    @endif
</div>
