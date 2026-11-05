<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 6h, F37 — every loop over records in a Livewire view gives its row
 * root a wire:key, so a list that changes on interaction (a row added,
 * voided, filtered or reordered) never morphs one row's DOM into another's.
 * A loop over a fixed literal list (weekday names, a few known fields) is
 * exempt: it can't change; so is an <option> list. A loop whose body is an @include is the
 * included view's to key (Approvals' items).
 */
class WireKeyTest extends TestCase
{
    public function test_every_loop_over_records_keys_its_row_root(): void
    {
        $files = [
            ...File::allFiles(resource_path('views/livewire')),
            // Rendered inside Attendance\Show, and its punch list changes on add and void.
            resource_path('views/components/attendance/day-detail-panel.blade.php'),
        ];
        $offenders = [];

        foreach ($files as $file) {
            $path = is_string($file) ? $file : $file->getPathname();
            $lines = explode("\n", File::get($path));

            foreach ($lines as $i => $line) {
                if (! preg_match('/@(?:foreach|forelse)\s*\((.*)\)\s*$/', trim($line), $loop) || $this->isFixedList($loop[1])) {
                    continue;
                }

                if (! $this->rowRootIsKeyed(array_slice($lines, $i + 1, 80))) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $path).':'.($i + 1).' @foreach ('.$loop[1].')';
                }
            }
        }

        $this->assertSame([], $offenders, "Loops whose row root has no wire:key:\n".implode("\n", $offenders));
    }

    /** A literal array, an enum's cases or the keys of a literal: it can't change. */
    private function isFixedList(string $iterable): bool
    {
        return (str_starts_with($iterable, '[') && ! str_starts_with($iterable, '[...')) || str_contains($iterable, '::cases()') || str_starts_with($iterable, 'array_keys($categories)');
    }

    /** @param  list<string>  $body */
    private function rowRootIsKeyed(array $body): bool
    {
        $inPhp = false;

        foreach ($body as $j => $line) {
            $line = trim($line);

            if ($inPhp) {
                $inPhp = ! str_contains($line, '@endphp');

                continue;
            }

            if (str_starts_with($line, '@include')) {
                return true;
            }

            if (str_starts_with($line, '@php') && ! str_contains($line, '@endphp')) {
                $inPhp = true;

                continue;
            }

            if (str_starts_with($line, '<') && ! str_starts_with($line, '</') && ! str_starts_with($line, '{{--')) {
                // The tag may run over several lines.
                $tag = '';
                foreach (array_slice($body, $j) as $part) {
                    $tag .= ' '.trim($part);
                    if (str_contains($part, '>')) {
                        break;
                    }
                }

                // An <option> has no state or children to mismatch: reused by
                // position, it only takes the new value and text.
                return str_contains($tag, 'wire:key=') || str_starts_with($line, '<option');
            }
        }

        return false;
    }
}
