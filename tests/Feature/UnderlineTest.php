<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Underline is reserved for links (docs/DESIGN_SYSTEM.md, Interaction
 * states): a button, a value or a label is never underlined, however
 * link-like it looks.
 */
class UnderlineTest extends TestCase
{
    public function test_only_links_are_underlined(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if ($relative === 'resources/views/welcome.blade.php') {
                continue;
            }
            preg_match_all('/<([\w.:-]+)\b([^>]*?)>/s', $file->getContents(), $tags, PREG_SET_ORDER);
            foreach ($tags as [$tag, $name, $attributes]) {
                if ($name !== 'a' && preg_match('/(?<![\w:-])underline(?![\w-])/', $attributes)) {
                    $offenders[] = $relative.': <'.$name.'> '.preg_replace('/\s+/', ' ', substr($attributes, 0, 80));
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
