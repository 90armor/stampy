<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * The browser tab's title, from what the topbar already shows (docs/DESIGN_SYSTEM.md,
 * Navigation): `{Page} – Stampy`, `{Name} · {Page} – Stampy` for a page about one
 * person (the breadcrumb trail, last crumb first), and `{Page} · {Tab} – Stampy`
 * on a page with section tabs. Views never write a title of their own.
 */
class PageTitle
{
    /**
     * @param  string|Htmlable|null  $header  The layout's `header`.
     * @param  array<int, array{label: string, route?: string}>|null  $breadcrumbs
     * @param  string|null  $section  The open section tab's label.
     */
    public static function for(string|Htmlable|null $header, ?array $breadcrumbs = null, ?string $section = null): string
    {
        $page = $breadcrumbs
            ? collect($breadcrumbs)->pluck('label')->reverse()->implode(' · ')
            : self::text($header);

        if ($section !== null) {
            $page .= ' · '.$section;
        }

        return ($page !== '' ? $page.' – ' : '').config('app.name', 'Laravel');
    }

    /**
     * A header slot arrives as compiled HTML: back to plain text, so Blade's
     * own escaping of the title doesn't escape it twice.
     */
    private static function text(string|Htmlable|null $header): string
    {
        if ($header instanceof Htmlable) {
            return trim(html_entity_decode(strip_tags($header->toHtml()), ENT_QUOTES));
        }

        return trim((string) $header);
    }
}
