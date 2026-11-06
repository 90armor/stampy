<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * How long someone has worked here, in whole months and years (Phase 6i, the
 * profile's header): "5 months", "2 years", "2 years 3 months"; "under a
 * month" before the first month is out.
 */
class Tenure
{
    public static function label(CarbonInterface $from, CarbonInterface $to): string
    {
        if ($to->lt($from)) {
            return 'under a month';
        }

        $interval = $from->copy()->startOfDay()->diff($to->copy()->startOfDay());

        $parts = array_filter([
            $interval->y > 0 ? $interval->y.' '.($interval->y === 1 ? 'year' : 'years') : null,
            $interval->m > 0 ? $interval->m.' '.($interval->m === 1 ? 'month' : 'months') : null,
        ]);

        return $parts === [] ? 'under a month' : implode(' ', $parts);
    }
}
