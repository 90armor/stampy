<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Neutrals go through the `slate` scale, which is CSS variables: warm stone in
 * light mode, cool zinc in dark mode (docs/DESIGN_SYSTEM.md, Neutral scale). A
 * stone/zinc/neutral class bypasses the variables and shows the wrong hue in
 * one of the two themes.
 */
class NeutralScaleTest extends TestCase
{
    // Laravel's unused starter page: no route renders it, and it carries its
    // own inlined Tailwind v4 build, not this app's classes.
    private const IGNORED = ['resources/views/welcome.blade.php'];

    public function test_no_view_script_or_style_uses_a_neutral_palette_other_than_slate(): void
    {
        $offenders = [];

        foreach (['resources/views', 'resources/js', 'resources/css'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (in_array($relative, self::IGNORED, true)) {
                    continue;
                }

                if (preg_match_all('/\b(?:stone|zinc|neutral)-\d{2,3}\b/', $file->getContents(), $matches)) {
                    $offenders[$relative] = array_values(array_unique($matches[0]));
                }
            }
        }

        $this->assertSame([], $offenders, 'Use the slate scale for neutrals, never stone/zinc/neutral classes.');
    }

    public function test_neutral_lines_use_only_the_divider_and_border_tokens(): void
    {
        // Two documented exceptions (docs/DESIGN_SYSTEM.md, Lines): the slate
        // badge's ring belongs to the badge colour system, like every status
        // badge's ring (the day modal's Off pill mirrors that badge); a
        // disabled checked checkbox's border is its fill.
        $allowed = [
            'resources/views/components/badge.blade.php' => ['ring-slate-500/10', 'ring-slate-500/20'],
            'resources/views/livewire/attendance/show.blade.php' => ['ring-slate-500/10', 'ring-slate-500/20'],
            'resources/css/app.css' => ['border-slate-500'],
        ];
        $offenders = [];

        foreach (['resources/views', 'resources/css'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (in_array($relative, self::IGNORED, true)) {
                    continue;
                }

                preg_match_all('/\b(?:border|ring|divide)(?:-[trblxy])?-slate-\d{2,3}(?:\/\d+)?\b/', $file->getContents(), $lines);
                preg_match_all('/\bh-px\b[^"\']*\bbg-slate-\d{2,3}(?:\/\d+)?/', $file->getContents(), $rules);
                $found = array_diff(array_unique([...$lines[0], ...$rules[0]]), $allowed[$relative] ?? []);
                if ($found) {
                    $offenders[$relative] = array_values($found);
                }
            }
        }

        $this->assertSame([], $offenders, 'Lines use border-slate-divider / border-slate-border (and their ring-, divide-, bg- forms), never a numbered slate step.');
    }

    public function test_the_slate_scale_is_css_variables_stone_in_light_and_zinc_in_dark(): void
    {
        $css = File::get(resource_path('css/app.css'));
        $config = File::get(base_path('tailwind.config.js'));

        $this->assertStringContainsString('rgb(var(--slate-${step}) / <alpha-value>)', $config);
        // Light mode: stone (800 = #292524). Dark mode: zinc (800 = #27272a).
        $this->assertMatchesRegularExpression('/:root \{[^}]*--slate-800: 41 37 36;/s', $css);
        $this->assertMatchesRegularExpression('/\.dark \{[^}]*--slate-800: 39 39 42;/s', $css);
    }

    public function test_readable_text_never_uses_light_slate_400_which_is_for_decorative_icons(): void
    {
        // Light slate-400 is 2.52:1 on white (docs/DESIGN_SYSTEM.md, Muted text
        // rule): icons and icon-only containers may use it, text may not. The
        // sidebar's disabled "Soon" items are a recorded Phase 5 item.
        $iconOnly = [
            'resources/views/components/empty-state.blade.php' => 'rounded-full bg-slate-100 text-slate-400',
            'resources/views/livewire/attendance/index.blade.php' => 'rounded-full bg-slate-100 text-slate-400',
            'resources/views/livewire/employees/index.blade.php' => 'rounded-full bg-slate-100 text-slate-400',
            'resources/views/components/time-input.blade.php' => 'rounded-md text-slate-400 transition',
            'resources/views/layouts/partials/sidebar.blade.php' => 'text-slate-400 dark:',
        ];
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (in_array($relative, self::IGNORED, true)) {
                continue;
            }
            preg_match_all('/<([\w.:-]+)\b([^>]*?)>/s', $file->getContents(), $tags, PREG_SET_ORDER);
            foreach ($tags as [$tag, $name, $attributes]) {
                if (in_array($name, ['x-icon', 'svg', 'path'], true) || ! preg_match('/(?<![:\w-])text-slate-400\b/', $attributes)) {
                    continue;
                }
                if (isset($iconOnly[$relative]) && str_contains($attributes, $iconOnly[$relative])) {
                    continue;
                }
                $offenders[] = $relative.': '.preg_replace('/\s+/', ' ', substr($tag, 0, 100));
            }
            // Text colours kept in PHP style arrays (the calendar's variants).
            preg_match_all("/'text' => '([^']*)'/", $file->getContents(), $styles);
            foreach ($styles[1] as $classes) {
                if (preg_match('/(?<![:\w-])text-slate-400\b/', $classes)) {
                    $offenders[] = $relative.": 'text' => '{$classes}'";
                }
            }
        }

        $this->assertSame([], $offenders, 'Readable muted text is text-slate-500 dark:text-slate-400.');
    }
}

