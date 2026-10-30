{{-- A form or action sent with a token that no longer matches the session
(Phase 6b). Reload goes back to the page it came from, fetched fresh — never
a re-post of what was refused. --}}
@include('errors.partials.page', [
    'icon' => 'clock',
    'header' => 'Page expired',
    'title' => 'This page expired',
    'description' => 'Reload and try again.',
    'action' => ['label' => 'Reload', 'href' => url()->previous()],
])
