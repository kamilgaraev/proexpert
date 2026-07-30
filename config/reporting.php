<?php

declare(strict_types=1);

return [
    'snapshot_signing' => [
        'active_key_id' => env('REPORT_SNAPSHOT_SIGNING_KEY_ID'),
        'active_private_key' => env('REPORT_SNAPSHOT_SIGNING_PRIVATE_KEY'),
        'trusted_public_keys_json' => env('REPORT_SNAPSHOT_TRUSTED_PUBLIC_KEYS', '{}'),
    ],
];
