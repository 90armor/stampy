{{-- Maintenance mode (php artisan down) — Phase 6b. The guest shell: the
session hasn't started when maintenance answers. --}}
@include('errors.partials.page', [
    'icon' => 'clock',
    'header' => 'Down for maintenance',
    'title' => 'Stampy is down for maintenance',
    'description' => "It'll be back shortly. Try again in a few minutes.",
    'action' => ['label' => 'Reload', 'href' => url()->current()],
])
