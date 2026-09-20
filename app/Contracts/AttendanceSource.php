<?php

namespace App\Contracts;

use App\Data\PunchRecord;
use Carbon\CarbonInterface;

interface AttendanceSource
{
    /**
     * @return iterable<PunchRecord>
     */
    public function punches(CarbonInterface $from, CarbonInterface $to): iterable;
}
