<?php

namespace App\Data;

use App\Enums\PunchType;
use Carbon\CarbonInterface;

/**
 * A single raw punch as reported by a source, before it's resolved to an
 * Employee. Identified by deviceUserId (what a real device gives us) —
 * resolving that to an employee is PunchIngestor's job, not the source's.
 */
final readonly class PunchRecord
{
    public function __construct(
        public string $deviceUserId,
        public CarbonInterface $punchedAt,
        public ?PunchType $punchType = null,
        public array $raw = [],
    ) {}
}
