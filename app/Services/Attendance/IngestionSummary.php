<?php

namespace App\Services\Attendance;

use Carbon\CarbonInterface;

final readonly class IngestionSummary
{
    /**
     * @param  string[]  $unknownDeviceIds
     * @param  ?CarbonInterface  $earliestImported  punched_at of the earliest newly imported punch, null if none
     * @param  ?CarbonInterface  $latestImported  punched_at of the latest newly imported punch, null if none
     * @param  int  $outsideEmployment  newly imported punches dated outside their employee's employment
     *                                  period (Employee::isActiveOn()) — kept, since raw hardware facts are
     *                                  never dropped, but they build no attendance
     */
    public function __construct(
        public int $imported,
        public int $skippedDuplicate,
        public int $skippedUnknown,
        public array $unknownDeviceIds,
        public ?CarbonInterface $earliestImported = null,
        public ?CarbonInterface $latestImported = null,
        public int $outsideEmployment = 0,
    ) {}
}
