<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
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
}
