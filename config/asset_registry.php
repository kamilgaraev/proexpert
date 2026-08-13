<?php

declare(strict_types=1);

return [
    // Phase B is an immutable application cutover. Tests keep compatibility mode
    // for legacy fixtures and opt into strict behavior explicitly where required.
    'strict_canonical_reads' => env('APP_ENV') !== 'testing',

    'observation_hours' => max(24, (int) env('ASSET_REGISTRY_OBSERVATION_HOURS', 24)),

    // Embedded into every verifier record so an observation window can prove
    // that all hourly checks came from the expected immutable release.
    'release_sha' => env('MOST_RELEASE_SHA'),
];
