<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Table row dividers (CLAUDE.md, Design system, Tables): the inset divider
 * span is positioned against the `relative` row, so it runs the row's whole
 * width. A `relative` cell becomes its containing block instead and the line
 * shrinks to that one cell — Time off's balances first shipped that way, with
 * dividers under Available only.
 */
class TableDividerTest extends TestCase
{
    public function test_no_cell_holding_a_row_divider_is_relative(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // A relative cell whose content (up to its closing tag) holds the divider line.
            if (preg_match_all('/<t[hd]\b[^>]*\bclass="[^"]*\brelative\b[^"]*"[^>]*>(?:(?!<\/t[hd]>).)*?bg-slate-divider/s', $file->getContents(), $matches)) {
                $offenders[] = $file->getRelativePathname().': '.count($matches[0]);
            }
        }

        $this->assertSame([], $offenders);
    }
}
