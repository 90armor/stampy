<?php

namespace App\Support;

/**
 * The initial on an avatar (Phase 6h, F10): the name's first character as a
 * reader sees it — a whole grapheme, so a Khmer or Myanmar-script name shows
 * its first letter with its marks rather than half a byte sequence ("�"),
 * which is what `substr($name, 0, 1)` gave. Leading spaces are skipped; an
 * empty name has no initial. `grapheme_substr()` needs the intl extension;
 * without it, `mb_substr()` still never splits a character.
 */
class Avatar
{
    public static function initial(?string $name): string
    {
        $name = preg_replace('/^[\s\p{Z}]+/u', '', (string) $name) ?? '';

        if ($name === '') {
            return '';
        }

        $first = function_exists('grapheme_substr') ? grapheme_substr($name, 0, 1) : false;

        return mb_strtoupper($first === false || $first === '' ? mb_substr($name, 0, 1) : $first);
    }
}
