<?php

namespace Tests\Support;

/**
 * A stream wrapper that looks like an existing regular file (so is_file() passes)
 * but refuses to be opened, like an unreadable file would.
 *
 * Real permissions can't be used here: the test container runs as root, and root
 * ignores file modes, so chmod 000 would still open. Registering this under a
 * scheme (e.g. refuse://file.csv) is the only way to make fopen() fail reliably.
 */
class RefusingStreamWrapper
{
    public $context;

    public static function register(string $scheme = 'refuse'): void
    {
        if (! in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_register($scheme, self::class);
        }
    }

    public static function unregister(string $scheme = 'refuse'): void
    {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return ['mode' => 0100644, 'size' => 10];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($options & STREAM_REPORT_ERRORS) {
            trigger_error("fopen({$path}): Failed to open stream: Permission denied", E_USER_WARNING);
        }

        return false;
    }
}
