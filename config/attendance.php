<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSV import column mapping
    |--------------------------------------------------------------------------
    |
    | The real device's export format isn't known yet, so this mapping is
    | kept here rather than hardcoded, to be tuned once a ZKTeco export is
    | available. `columns` maps our field names to the CSV's header names.
    | `punch_type` is optional — omit it (or leave a row's value unmapped)
    | to let PunchIngestor infer in/out from position instead.
    |
    */

    'csv' => [
        'columns' => [
            'device_user_id' => 'user_id',
            'punched_at' => 'timestamp',
            'punch_type' => 'state',
        ],

        'punch_type_map' => [
            '0' => 'in',
            '1' => 'out',
        ],

        'datetime_format' => 'Y-m-d H:i:s',

        'has_header' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Double-tap window
    |--------------------------------------------------------------------------
    |
    | When PunchIngestor has to infer a punch's type (source didn't report
    | one), punches this close to the previous one in that day's chronological
    | order are treated as the same physical event — a double-tap on the
    | sensor — and get the SAME inferred type, so alternation advances per
    | group rather than per punch. This is inference-only: it never drops a
    | row, and never re-types a punch whose source already reported a type.
    |
    */

    'duplicate_window_seconds' => 90,

    /*
    |--------------------------------------------------------------------------
    | Minute rounding
    |--------------------------------------------------------------------------
    |
    | Not a config value — recorded here because it's a rule of the same
    | calculation this file configures. DailySummaryBuilder computes
    | worked_minutes, late_minutes, and early_leave_minutes from raw
    | timestamp differences using intdiv(seconds, 60), which truncates
    | toward zero: a partial minute is always dropped, never rounded up.
    | E.g. late by 10 minutes 45 seconds records as 10 late_minutes, not 11.
    |
    */

];
