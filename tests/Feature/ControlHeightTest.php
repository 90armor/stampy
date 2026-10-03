<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One control height (docs/DESIGN_SYSTEM.md, Control height): every text
 * input, select, date/time field and button is `h-control`, so a button never
 * sits 2px short of the input beside it and a field never grows a second line.
 */
class ControlHeightTest extends TestCase
{
    // The starter page (unrouted, its own Tailwind build) and the two
    // components the first test renders: their `->merge` cuts the tag scan short.
    private const IGNORED = [
        'resources/views/welcome.blade.php',
        'resources/views/components/select.blade.php',
        'resources/views/components/text-input.blade.php',
    ];

    public function test_every_control_component_uses_the_control_height(): void
    {
        $rendered = [
            'text-input' => Blade::render('<x-text-input id="a" />'),
            'select' => Blade::render('<x-select id="a"><option>1</option></x-select>'),
            'button' => Blade::render('<x-button>Save</x-button>'),
            'button link' => Blade::render('<x-button href="/">Go</x-button>'),
            'date-picker' => Blade::render('<x-date-picker id="a" model="d" label="Date" />'),
            'time-input' => Blade::render('<x-time-input id="a" model="t" label="Time" />'),
        ];

        foreach ($rendered as $name => $html) {
            $this->assertStringContainsString('h-control', $html, "{$name} has no h-control");
        }

        // The date and time components each carry it twice: the custom field and the native one below 640px.
        $this->assertSame(2, substr_count($rendered['date-picker'], 'h-control'));
        $this->assertSame(2, substr_count($rendered['time-input'], 'h-control'));
    }

    public function test_the_time_input_segments_never_wrap(): void
    {
        $html = Blade::render('<x-time-input id="a" model="t" label="Time" />');

        $this->assertMatchesRegularExpression('/x-ref="segments"[^>]*class="[^"]*whitespace-nowrap/s', $html);
    }

    public function test_no_raw_text_field_or_select_in_a_view_skips_the_control_height(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('resources/views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (in_array($relative, self::IGNORED, true)) {
                continue;
            }

            preg_match_all('/<(?:input|select)\b[^>]*>/s', $file->getContents(), $matches);
            foreach ($matches[0] as $tag) {
                if (preg_match('/type="(?:checkbox|radio|hidden|file)"/', $tag)) {
                    continue;
                }
                if (! str_contains($tag, 'h-control')) {
                    $offenders[] = $relative.': '.preg_replace('/\s+/', ' ', substr($tag, 0, 80));
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
