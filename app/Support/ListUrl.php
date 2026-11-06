<?php

namespace App\Support;

/**
 * The last URL of a list page the user had open — its filters, page and date
 * range — kept per list in the session (Phase 6i). A detail page's breadcrumb
 * links its parent crumb here, so going back returns to the list as it was
 * left, not to its defaults.
 *
 * The list component records itself on every render (remember()), which is
 * every request it answers, Livewire updates included, so the URL follows a
 * filter change without waiting for the address bar. Only the lists that have
 * detail pages record themselves: Attendance and Employees.
 */
class ListUrl
{
    private const SESSION_KEY = 'list_urls';

    /**
     * @param  array<string, string|int|bool|array<int, string>>  $query  the list's
     *                                                                    non-default query parameters
     */
    public static function remember(string $route, array $query): void
    {
        $query = array_filter($query, fn ($value) => $value !== '' && $value !== [] && $value !== false && $value !== null);

        session()->put(self::SESSION_KEY.'.'.$route, route($route, $query));
    }

    /**
     * The remembered URL of a list, else its plain URL.
     */
    public static function for(string $route): string
    {
        $url = session(self::SESSION_KEY.'.'.$route);

        return is_string($url) && $url !== '' ? $url : route($route);
    }
}
