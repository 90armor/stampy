<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A link on a modal panel is dark:text-primary-300: the panel is slate-750 in
 * dark mode, where primary-400 measures 4.08:1, under 4.5:1 for text, and
 * primary-300 6.14:1 (Phase 6i follow-up — the day modal's leave and overtime
 * links, and the profile's Edit modal). On the slate-800 card primary-400 is
 * 4.84:1, so the rule is for modals only.
 */
class ModalLinkContrastTest extends TestCase
{
    public function test_no_link_inside_a_modal_uses_primary_400_in_dark_mode(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();
            preg_match_all('/<x-modal\b.*?<\/x-modal>/s', $source, $modals, PREG_OFFSET_CAPTURE);

            foreach ($modals[0] as [$modal, $offset]) {
                preg_match_all('/<a\b[^>]*>/s', $modal, $links, PREG_OFFSET_CAPTURE);

                foreach ($links[0] as [$link, $at]) {
                    if (preg_match('/(?<![\w:-])dark:text-primary-400\b/', $link)) {
                        $line = substr_count(substr($source, 0, $offset + $at), "\n") + 1;
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$line;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'A link on a modal panel is dark:text-primary-300 (docs/DESIGN_SYSTEM.md, Links).');
    }
}
