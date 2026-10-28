<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * <x-icon>'s 20px default applies only when the caller sets no size: merged
 * together, Tailwind's h-5 (emitted after h-4/h-3.5) won and every smaller
 * icon rendered at 20px.
 */
class IconTest extends TestCase
{
    public function test_an_icon_without_a_size_gets_the_twenty_pixel_default(): void
    {
        $this->assertStringContainsString('class="w-5 h-5 text-slate-400"', Blade::render('<x-icon name="check" class="text-slate-400" />'));
    }

    public function test_a_callers_size_replaces_the_default_instead_of_joining_it(): void
    {
        foreach (['h-4 w-4', 'h-3.5 w-3.5 shrink-0', 'w-6 h-6', 'size-4'] as $size) {
            $html = Blade::render('<x-icon name="check" class="'.$size.'" />');

            $this->assertStringNotContainsString('w-5 h-5', $html, $size);
            $this->assertStringContainsString($size, $html);
        }
    }

    public function test_a_breakpoint_size_alone_keeps_the_default_below_it(): void
    {
        $this->assertStringContainsString('class="w-5 h-5 sm:h-4 sm:w-4"', Blade::render('<x-icon name="check" class="sm:h-4 sm:w-4" />'));
    }

    public function test_no_call_site_uses_a_size_under_twenty_pixels_outside_the_pill_exceptions(): void
    {
        // The only sub-20px icons (docs/DESIGN_SYSTEM.md, Icon size): 14px in a
        // status pill or filter chip. The calendar's sm:h-4 is a breakpoint
        // size, not a base one, so it isn't matched here.
        $allowed = [
            'resources/views/livewire/attendance/index.blade.php' => ['name="check" class="h-3.5 w-3.5"'],
            'resources/views/livewire/attendance/show.blade.php' => [':name="$modalStyle[\'icon\']" class="h-3.5 w-3.5"'],
        ];
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            preg_match_all('/<x-icon\b[^>]*?class="([^"]*)"/s', $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as [$tag, $class]) {
                if (! preg_match('/(?<![\w:-])(?:h|w|size)-(?:[0-4](?:\.5)?|px)(?![\w.])/', $class)) {
                    continue;
                }
                $isException = collect($allowed[$relative] ?? [])->contains(fn (string $marker) => str_contains($tag, $marker));
                if (! $isException) {
                    $offenders[] = $relative.': '.preg_replace('/\s+/', ' ', $tag);
                }
            }
        }

        $this->assertSame([], $offenders, 'Icons are 20px (h-5 w-5); 14px only inside a status pill or filter chip.');
    }
}

