<?php

namespace Tests\Support;

use App\Contracts\AttendanceSource;
use App\Data\PunchRecord;
use Carbon\CarbonInterface;

class FakeAttendanceSource implements AttendanceSource
{
    /**
     * @param  PunchRecord[]  $records
     */
    public function __construct(private readonly array $records) {}

    public function punches(CarbonInterface $from, CarbonInterface $to): iterable
    {
        foreach ($this->records as $record) {
            if ($record->punchedAt->between($from, $to)) {
                yield $record;
            }
        }
    }
}
