# Stampy Design System v1

Attendance-specific presentation and responsive-table guidance lives in [Attendance UI](ATTENDANCE_UI.md).

This document is the source of truth for Stampy's visual interface. It records conventions supported by the current Laravel, Blade, Livewire, Alpine.js, Tailwind CSS, and Heroicons implementation. It is a foundation for incremental page modernization, not a mandate for a whole-application redesign.

## Principles

1. Clarity over decoration. Attendance and HR data must remain easy to scan.
2. Consistency over novelty. Extend shared Blade components before creating page-specific patterns.
3. Readability over visual effects. Content surfaces are opaque; glass is a shell treatment only.
4. Semantic meaning over arbitrary color. Choose a color because of its role or status meaning.
5. Accessible at rest. Controls and links cannot depend on hover to appear interactive.
6. Incremental change. Modernize representative pages against this system before expanding it.

## Color system

`tailwind.config.js` retains the existing palettes. Design System v1 does not add semantic Tailwind utility names.

- `primary-*`: deep evergreen. Primary actions, active navigation, links, and focus emphasis.
- `accent-*`: mint. Brand marks, restrained highlights, and the Leave status; not a primary-action substitute.
- `slate-*`: Stampy's warm neutral scale (mapped to stone-like values). Page, surface, border, and text hierarchy.
- Status colors: green, amber, violet, red, slate, accent, blue, and fuchsia retain the meanings defined below.

### Semantic roles

| Semantic role | Light implementation | Dark implementation | Usage |
|---|---|---|---|
| Text / Primary | `text-slate-900` | `dark:text-slate-100` | Headings and primary readable content |
| Text / Secondary | `text-slate-700` | `dark:text-slate-300` | Supporting body content and controls |
| Text / Muted | `text-slate-500` | `dark:text-slate-400` | Metadata, hints, and secondary labels |
| Text / Subtle | `text-slate-400` | `dark:text-slate-500` | Low-emphasis decoration; never critical copy |
| Surface / Page | `bg-slate-100` | `dark:bg-slate-950` | Application and authentication background |
| Surface / Card | `bg-white` | `dark:bg-slate-900` | Cards, table containers, information panels |
| Surface / Control | `bg-white` | `dark:bg-slate-800` | Inputs, selects, textareas, compact controls |
| Surface / Overlay panel | `bg-white` | `dark:bg-slate-900` | Dropdowns, popovers, modal panels |
| Border / Default | `ring-slate-200/60` or `border-slate-300` | `dark:ring-slate-800` or `dark:border-slate-700` | Surface and control boundaries |
| Border / Divider | `bg-slate-200/60` or `border-slate-200/60` | `dark:bg-slate-800/60` or `dark:border-slate-800/60` | Row and section separation |
| Action / Primary | `bg-primary-600 text-white` | same | Main action on a page or flow |
| Action / Secondary | `bg-white text-slate-700` | `dark:bg-slate-800 dark:text-slate-200` | Supporting actions |
| Action / Danger | `text-red-600` with red boundary/tint | `dark:text-red-400` | Destructive actions |
| Focus / Interactive | `ring-primary-500` | `dark:ring-primary-500` | Keyboard focus and focused controls |

Attendance color is model-driven. `DailyAttendance::displayVariant()` is the source of truth and is status-only: present green, incomplete violet, absent red, off slate, leave accent, in progress blue, and holiday fuchsia. Views must not independently derive these buckets. Status is reinforced with text or shape, never color alone.

**Color = status only; co-occurring attributes are annotations.** A cell or badge color encodes one value per record — its status. Attributes that can co-occur with that status on the same record (attendance timing exceptions now, partial leave later) are annotations inside the cell, never the cell or badge color. For attendance timing the annotation is amber text on the specific value; see [Attendance UI](ATTENDANCE_UI.md).

## Typography

- Inter is the primary UI font through `font-sans`, with system sans-serif fallbacks.
- DM Serif Display is limited to the auth hero's editorial accent through `font-serif`.
- Default body and control copy is `text-sm`; supporting metadata is `text-xs` or `text-sm`.
- Page titles use `text-2xl font-semibold tracking-tight`. Section headings use sentence case unless a compact eyebrow label is appropriate.
- Numeric attendance values use `tabular-nums` when alignment helps comparison.
- Avoid introducing arbitrary font families, tiny critical copy, or long uppercase labels.

## Spacing and layout

Use Tailwind's spacing scale and favor the established rhythm:

- Page container: `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8`.
- Standard card padding: `p-6`; compact/stat card padding: `p-4`.
- Form groups: `space-y-5` or `space-y-6`; label-to-control spacing: `mt-1`; error spacing: `mt-2`.
- Common inline gaps: `gap-2` or `gap-3`; section gaps: `gap-4` to `gap-6`.
- Table cells: `px-6 py-4`; table headings: `px-6 py-3`.

Do not add arbitrary pixel spacing until the standard scale demonstrably cannot express the requirement.

## Radius and elevation

- `rounded-md`: badges and compact status elements.
- `rounded-lg`: inputs, selects, textareas, buttons, and other controls.
- `rounded-xl`: cards, dropdown menus, and popovers.
- `rounded-2xl`: modal and large overlay panels.
- `rounded-full`: geometry that is intentionally circular, including avatars, dots, progress tracks, and circular icon containers; not status badges or control styling.
- Cards use `shadow-sm` plus a ring. Separation comes from the surface step first: white cards on a `slate-100` page in light mode. In dark mode a shadow is invisible on near-black, so the card edge is a full-opacity `ring-slate-800` around the `slate-900` card on the `slate-950` page; do not weaken it back to a translucent ring.
- Dropdowns and modal panels use `shadow-lg` or `shadow-xl` because they float above content.
- Elevation communicates hierarchy, not decoration. Normal cards do not gain elevation or a colored ring on hover unless the entire card is interactive.

## Surface architecture

Surface role determines treatment; pages do not choose between glass and solid variants.

### Shell surfaces

The sidebar, topbar, and authentication shell may use translucency and `backdrop-blur`. `.bg-shell` supplies the restrained decorative background that makes this treatment legible. Keep the effect subtle and retain clear borders. In dark mode the `.bg-shell` glow is confined to the top-left corner behind the sidebar; it must not tint the page behind content cards, where it erodes the already small dark-mode surface step.

### Content surfaces

Cards, stat cards, table containers, information panels, controls, dropdown menus, popovers, and modal panels are solid. Use `bg-white`/`dark:bg-slate-900` for container surfaces and `bg-white`/`dark:bg-slate-800` for controls. Do not add `backdrop-blur` to content components.

### Overlay treatment

The modal backdrop may use transparency and `backdrop-blur-sm`. This is an overlay treatment, not a glass content surface. The panel above it remains solid.

## Components

- `<x-card>` is the standard solid `rounded-xl` content container. Use `:padding="false"` when a table or custom edge-to-edge layout owns its inner spacing.
- `<x-button>` owns primary, secondary, and danger action styling. Breeze primary and secondary button wrappers delegate to it.
- `<x-badge>` owns compact `rounded-md` status labels. Add variants only when a stable semantic state requires a distinct meaning.
- `<x-text-input>`, `<x-select>`, and `<x-textarea>` own form-control visuals and are always solid.
- `<x-dropdown>` owns menu positioning, transitions, and its solid `rounded-xl` menu surface.
- `<x-modal>` owns the backdrop, focus trap, focus restoration, transitions, and solid `rounded-2xl` panel. It remains teleported to `<body>`. The panel deliberately does not use `overflow-hidden`: modal bodies that need scrolling own it locally, and panel-level clipping can cut focus rings or overlay content.
- `<x-empty-state>` provides an icon, title, optional description, and optional action.
- `<x-icon>` is the only Heroicons entry point. Add icons there rather than embedding a second icon system.

Prefer composition over adding props that expose implementation choices. Props should express genuine behavior or content, not optional design-system rules.

### Entity detail pages

Entity detail pages are operational records, not dashboards or profile heroes. Lead with a compact identity header, then place structured metadata and primary operational state in the main reading area while lower-density account or related-workflow sections may use a narrower supporting column on desktop. Preserve a single logical mobile reading order, use description lists for labeled metadata, and keep management, contextual, and navigation actions visually distinct and permission-aware.

## Interaction states

- Every interactive element has a visible rest state and a keyboard focus indicator. Equivalent primitives use the existing primary/evergreen palette for a clear, no-layout-shift `focus-visible` ring; the treatment respects the component type rather than forcing the same border construction onto buttons, links, fields, and navigation.
- Hover may strengthen an existing affordance; it must not reveal the only action or link cue.
- Pressed (`:active`) feedback is temporary and distinct from focus and selected/current state. Pointer activation must not create a decorative persistent ring.
- Disabled controls use reduced contrast and `cursor-not-allowed` where appropriate, while remaining readable. Loading actions remain disabled against repeat submission and retain meaningful copy or an accessible loading indicator.
- Text-entry and selection controls may retain a visible `focus` border/ring while being edited; action controls and links prefer `focus-visible` so keyboard focus is prominent without adding unnecessary pointer-click persistence.
- Destructive actions use red semantics and require confirmation when the effect is material.
- Row navigation must be keyboard reachable. A cell link uses visible primary-colored underlined text; a fully clickable row uses cursor, hover, and `focus-visible` treatment.
- Underline is reserved for links. Never underline a value to mark it (a timing exception, an error, an emphasis); use color plus weight or an explicit label instead.
- Preserve user-entered state during Livewire updates and restore focus after modal dismissal.

## Forms

Labels sit above controls and are programmatically associated with them. Controls use a solid surface, `rounded-lg`, a default border, `text-sm`, and primary focus rings. Validation errors appear directly below the field in red and fields expose `aria-invalid`/`aria-describedby` when applicable.

### Selection controls

Native checkboxes and radio buttons form one selection-control family. Enabled unchecked controls use neutral solid surfaces and borders; enabled checked controls use the primary evergreen fill and retain their native checkmark or dot in both light and dark mode. Disabled controls stay in the neutral palette so evergreen consistently signals an active, editable selection: disabled unchecked controls use a subdued neutral surface, while disabled checked controls use a stronger neutral fill plus the native checkmark or dot to preserve the selected state without suggesting interactivity. Enabled controls may strengthen their border or fill on hover, disabled controls do not receive hover feedback, and keyboard focus uses the primary `focus-visible` ring. Validation may replace the boundary and focus emphasis with the established red error treatment. Preserve the native checkbox/radio semantics and distinct square/circular shapes; do not rely on color alone to communicate selection.

Keep touch targets at least 44px high for primary auth controls and small-screen-critical actions. Use correct input types and autocomplete attributes. Do not rely on placeholder text as a label. Loading submissions should disable repeat action and retain a textual state such as “Saving…”.

## Tables and data lists

- Use uppercase `text-xs` headers, `px-6 py-3` headings, and `px-6 py-4` body cells.
- Use the established inset `slate-200/60` divider (dark: `slate-800/60`), omitting a trailing divider after the last row.
- Row hover is `hover:bg-slate-50 dark:hover:bg-slate-800/60`.
- Preserve selectable data when choosing between a cell link and a whole-row target.
- Wrap wide tables in `overflow-x-auto`; do not compress data until it becomes unreadable.
- Place pagination within the same solid data surface, separated by a standard divider.

## Navigation

The desktop sidebar is fixed-width at `242px`; the topbar is sticky. Both are shell surfaces and may remain glass. Active navigation uses a primary tint, primary text/icon, and a persistent leading marker. Mobile navigation is a modal drawer with a dismissible backdrop. Breadcrumbs collapse nonessential ancestors at small widths.

Navigation visibility must match destination authorization. UI hiding is presentation only and never replaces route middleware or policy checks.

## Overlays

Dropdowns and popovers are opaque, elevated content surfaces. Modals use `<x-modal>` and remain teleported to `<body>`; do not nest a hand-built fixed overlay in page content. Escape and backdrop click close dismissible overlays. Modal focus is trapped while open and restored to the trigger when closed. Confirmation dialogs follow the same visual surface rules.

## Loading states

- Disable an action while its request is running to prevent duplicate submission.
- Keep the control's width stable and use explicit copy such as “Signing in…” or “Saving…”.
- A spinner supplements text and carries `aria-hidden="true"`; status copy carries the meaning.
- For Livewire regions, prefer a local loading state near the affected content over blocking the full application shell.
- Avoid skeletons unless latency and layout stability on a representative page justify them.

## Empty states

Use `<x-empty-state>` inside the owning solid content surface. State what is missing, optionally explain why, and offer one authorized next action when one exists. “No results” after filtering should help users reset filters; it is distinct from “no records exist.” Users without an employee link use `<x-no-employee-record>` rather than a misleading empty table.

## Accessibility

- Meet WCAG AA contrast for text, controls, focus, and status treatments in both themes.
- Do not communicate status, errors, timing exceptions, or interactivity by color alone.
- All controls require an accessible name; icon-only buttons need an `aria-label` or equivalent visible/tooltip label.
- Maintain logical heading order, landmarks, table semantics, and form associations.
- Focus indicators use `focus-visible` where mouse focus would be noisy; never remove focus without a replacement.
- Respect reduced-motion preferences for any nonessential future animation. Existing transitions must be short and functional.
- Ensure actions are visible and reachable on touch devices without hover.

## Responsive behavior

Design from the smallest supported width outward. The app switches from the sidebar to a drawer below `lg`; auth switches from split-screen to form-only below `lg`. Stack form and toolbar controls before they become cramped. Use wrapping and horizontal table scrolling intentionally. Test at narrow mobile, tablet, desktop, and zoomed desktop widths in both themes.

## Dark mode

Dark mode is class-based and applied before paint from `localStorage.theme`, falling back to system preference. Every component must define text, surface, boundary, hover, focus, disabled, and status treatments—not only a dark background. Reapply the stored theme after `livewire:navigated`, as the layouts currently do.

## Audit baseline and migration scope

The v1 audit covered the app and guest layouts; shared Blade/Breeze components; Alpine theme, dropdown, drawer, and modal behavior; representative dashboard, attendance, employee, organization, auth, and profile screens; tables, forms, empty states, badges, overlays, responsive structures, and relevant feature tests.

The principal inconsistency was content glass: cards, form controls, dropdowns, and some modal panels used translucent surfaces while other dense overlays already used solid ones for readability. Pass B resolves this at the shared-component level, removes the obsolete `surface` prop, updates its callers, and normalizes representative page-level controls. It deliberately does not redesign individual pages.

## Future tokenization

The semantic roles in this document are guidance mapped to existing Tailwind classes, not new utilities. Do not add utilities such as `text-muted`, `bg-surface`, or `border-default`, and do not add a semantic palette to `tailwind.config.js` in v1.

After multiple representative screens have been redesigned and these roles have proven stable, implementation-level semantic tokens may be evaluated. Any proposal should demonstrate that it reduces drift without obscuring Tailwind behavior, preserves status semantics, and works in both themes. Until then, use the mappings above.
