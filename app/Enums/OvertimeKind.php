<?php

namespace App\Enums;

/**
 * How an overtime request came in (CLAUDE.md, Phase 4, rule 1): planned —
 * submitted before the overtime starts, the normal path — or a claim,
 * submitted after the fact for unplanned overtime. Both go through the same
 * approval.
 */
enum OvertimeKind: string
{
    case Planned = 'planned';
    case Claim = 'claim';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Claim => 'Claim',
        };
    }
}
