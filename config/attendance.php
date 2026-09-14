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

];
