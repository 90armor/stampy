<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The holidays of a date range, loaded in one query (WorkdayCalendar::
 * preload()), for a caller that asks about many dates — a walk over working
 * days, or many leaves at once — and shouldn't query once per date. A date
 * outside the range is looked up as WorkdayCalendar::isHoliday() does, so a
 * caller that guessed its range too small is slower, never wrong.
 */
final class HolidayRange
{
    /**
     * @param  array<string, true>  $dates  keyed 'Y-m-d'
     */
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly array $dates,
    ) {}

    public function has(CarbonInterface $date): bool
    {
        $key = $date->format('Y-m-d');

        return $key >= $this->from && $key <= $this->to
            ? isset($this->dates[$key])
            : WorkdayCalendar::isHoliday($date);
    }
}
