<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Schedules and Holidays live on Policies, not Organization (owner decision,
 * after Phase 4). routes/web.php redirects the old tab links so bookmarks keep
 * working, but nothing of ours may still point there: no view, script, class
 * or doc links to an Organization schedules/holidays tab, by URL, by route
 * helper, or as an "Organization → Schedules" path in prose.
 */
class OrganizationTabLinksTest extends TestCase
{
    private const PATTERNS = [
        'URL' => '/organization[^\n]{0,40}?tab=(?:schedules|holidays)\b/i',
        'route helper' => '/organization\.index[\'"]\s*,\s*\[[^\]]*[\'"](?:schedules|holidays)[\'"]/i',
        'path in prose' => '/Organization\s*(?:→|->|&rarr;)\s*(?:Schedules|Holidays)\b/i',
    ];

    public function test_nothing_links_to_an_organization_schedules_or_holidays_tab(): void
    {
        $files = [];
        foreach (['resources/views', 'resources/js', 'app', 'docs'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $files[] = $file->getPathname();
            }
        }
        $files[] = base_path('CLAUDE.md');
        $files[] = base_path('README.md');

        $offenders = [];
        foreach ($files as $path) {
            $relative = str_replace(base_path().'/', '', $path);
            // Dated historical records: they describe the app as it was.
            if (str_starts_with($relative, 'docs/audits/')) {
                continue;
            }

            foreach (explode("\n", File::get($path)) as $index => $line) {
                foreach (self::PATTERNS as $kind => $pattern) {
                    if (preg_match($pattern, $line)) {
                        $offenders[] = "{$relative}:".($index + 1)." ({$kind})";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Schedules and Holidays are on Policies: link to policies.index with tab=schedules or tab=holidays.');
    }
}
