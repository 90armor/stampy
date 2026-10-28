<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * <x-modal> always moves focus into the dialog when it opens and returns it
 * to the trigger when it closes (docs/DESIGN_SYSTEM.md, Overlays). It used to
 * do so only for a call that passed `focusable`, which none did, so the
 * calendar's day modal left focus on the page behind it.
 */
class ModalFocusTest extends TestCase
{
    public function test_opening_moves_focus_in_by_default_with_no_opt_in(): void
    {
        $html = Blade::render('<x-modal name="probe"><button type="button">Ok</button></x-modal>');

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        // Both ways a modal can open run the same opened(): a show that flips
        // to true, and an instance Livewire renders already open.
        $this->assertStringContainsString('if (show) { opened(); }', $html);
        $this->assertMatchesRegularExpression('/\$watch\(\'show\', value => \{\s*if \(value\) \{\s*opened\(\);/', $html);
        $this->assertStringContainsString('focusInitial()', $html);
        $this->assertStringNotContainsString('firstFocusable().focus(), 100', $html);
    }

    public function test_closing_returns_focus_to_the_trigger_or_the_last_focus_outside_a_modal(): void
    {
        $html = Blade::render('<x-modal name="probe"><button type="button">Ok</button></x-modal>');

        $this->assertStringContainsString('triggerEl.focus()', $html);
        $this->assertStringContainsString('window.stampyLastFocusOutsideModal', $html);
        // The page-wide tracker lives in app.js, outside any modal instance.
        $this->assertStringContainsString('window.stampyLastFocusOutsideModal = event.target', File::get(resource_path('js/app.js')));
    }

    public function test_no_call_site_relies_on_the_removed_focusable_opt_in(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-modal\b[^>]*>/s', $file->getContents(), $tags);
            foreach ($tags[0] as $tag) {
                $this->assertStringNotContainsString('focusable', $tag, $file->getRelativePathname());
            }
        }
    }
}
