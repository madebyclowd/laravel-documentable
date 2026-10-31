<?php

declare(strict_types=1);

return [
    'types' => [
        'invoice' => [
            'max_versions_to_keep' => 3,
        ],
    ],
    'lifecycle' => [
        'pending_ttl_hours' => 24,
    ],
    'security' => [
        'allow_guest_user_id' => false,
        'enforce_path_prefix' => false,
    ],
];
