<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | A document stays in the 'pending' status until it is verified, and the
    | status then changes.
    |
    */

    'types' => [
        'invoice' => [
            'versions_kept' => 5,
        ],
    ],
    'multipart' => [
        'session_ttl_hours' => 24,
    ],
];
