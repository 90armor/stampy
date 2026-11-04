<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The layout every report CSV shares (Phase 6g, F35): the header row is the
 * first line, so Excel's Filter and Sort and Power Query's header detection
 * work on the file as it is; the rows follow; the notes — title, open-period
 * notice, explanation — come last, after one blank row. A UTF-8 byte-order
 * mark comes first of all (each report writes it), or Excel reads the file as
 * ANSI and breaks "—" and Khmer or Myanmar names.
 */
final class ReportCsv
{
    /**
     * `attendance-2026-10-exported-2026-11-01-0910.csv`, and while the period
     * isn't closed `…-0910-OPEN.csv`, so the name says the figures can still
     * change before the file is opened.
     */
    public static function filename(string $base, CarbonInterface $exportedAt, bool $open): string
    {
        return $base.'-exported-'.$exportedAt->format('Y-m-d-Hi').($open ? '-OPEN' : '').'.csv';
    }

    /**
     * After the rows: one blank row, then each note on its own line.
     *
     * @param  resource  $out
     * @param  list<string>  $notes
     */
    public static function notes($out, array $notes): void
    {
        fwrite($out, "\n");

        foreach ($notes as $note) {
            fputcsv($out, [$note]);
        }
    }
}
