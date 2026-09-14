<?php

namespace App\Services\Attendance;

final readonly class IngestionSummary
{
    /**
     * @param  string[]  $unknownDeviceIds
     */
    public function __construct(
        public int $imported,
        public int $skippedDuplicate,
        public int $skippedUnknown,
        public array $unknownDeviceIds,
    ) {}
}
