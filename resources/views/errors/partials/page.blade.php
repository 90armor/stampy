{{--
    Shared shell for 403/404/500: the app's own layout and design system
    (x-card/x-empty-state), a plain message, and a way back — never Laravel's
    bare, unstyled default (resources/views/errors/{code}.blade.php, published
    nowhere in this app), which had no navigation and surfaced the raw
    exception message (e.g. spatie/laravel-permission's own "User does not
    have the right roles" for a 403).

    Picks the layout by auth state, not by which status code this is: a 403
    can only happen to an already-authenticated user (role/policy checks run
    after the `auth` middleware), but a 404 can happen to a fully anonymous
    visitor hitting a URL that matches no route at all, before any middleware
    runs — and layouts.app's sidebar/topbar call auth()->user() directly with
    no null-guard, so using it unconditionally would turn an anonymous 404
    into a 500.
--}}
@if (auth()->check())
    <x-app-layout>
        <x-slot name="header">{{ $title }}</x-slot>

        <x-card>
            <x-empty-state :icon="$icon" :title="$title" :description="$description">
                <x-slot name="action">
                    <x-button variant="primary" href="{{ route('dashboard') }}">Go to dashboard</x-button>
                </x-slot>
            </x-empty-state>
        </x-card>
    </x-app-layout>
@else
    <x-guest-layout>
        <x-card>
            <x-empty-state :icon="$icon" :title="$title" :description="$description">
                <x-slot name="action">
                    <x-button variant="primary" href="{{ route('login') }}">Go to login</x-button>
                </x-slot>
            </x-empty-state>
        </x-card>
    </x-guest-layout>
@endif
