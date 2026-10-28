<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * <x-time-input>: typed segments plus a popover, with a native time input
 * below 640px (docs/DESIGN_SYSTEM.md, Time input).
 */
class TimeInputTest extends TestCase
{
    public function test_a_time_field_is_the_segmented_input_with_a_native_one_below_sm(): void
    {
        $this->travelTo('2026-03-04 10:15:00');

        $html = (string) $this->blade('<x-time-input id="new_punch_time" model="newPunchTime" label="Time" cap-at-now-when="newPunchDate" />');

        // Bound to one property, capped at now while that date property is today.
        $this->assertStringContainsString("timeInput({ model: 'newPunchTime', min: null, max: null, after: null, capDate: 'newPunchDate', nowCap: '10:15', today: '2026-03-04', disabled: false })", $html);
        // Three spinbutton segments, labelled by the field's label.
        $this->assertSame(3, substr_count($html, 'role="spinbutton"'));
        $this->assertStringContainsString('aria-labelledby="new_punch_time-label"', $html);
        foreach (['Hour', 'Minutes', 'AM/PM'] as $segment) {
            $this->assertStringContainsString('aria-label="'.$segment.'"', $html);
        }
        // The popover button keeps the field's id, so <x-input-label for> opens the picker.
        $this->assertMatchesRegularExpression('/<button\s+type="button"\s+id="new_punch_time"\s+x-ref="toggle"/', $html);
        // Below 640px: the native input on the same property.
        $this->assertMatchesRegularExpression('/<input\s+type="time"\s+id="new_punch_time-native"\s+aria-label="Time"\s+wire:model="newPunchTime"[^>]*sm:hidden/s', $html);
    }

    public function test_static_bounds_and_a_locked_field_reach_both_inputs(): void
    {
        $html = (string) $this->blade('<x-time-input id="t" model="end_time" label="End time" min="06:00" max="22:00" after="start_time" disabled />');

        $this->assertStringContainsString("min: '06:00', max: '22:00', after: 'start_time'", $html);
        $this->assertStringContainsString('disabled: true })', $html);
        $this->assertMatchesRegularExpression('/<input\s+type="time"[^>]*min="06:00"[^>]*max="22:00"[^>]*disabled/s', $html);
    }

    public function test_no_time_field_uses_a_bare_native_time_input(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if ($relative !== 'resources/views/components/time-input.blade.php' && str_contains($file->getContents(), 'type="time"')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }
}
