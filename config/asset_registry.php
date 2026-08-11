<?php

declare(strict_types=1);

return [
    // Canonical data is already preferred for linked records. Strict mode removes
    // the temporary fallback to unlinked machinery_assets rows after observation.
    'strict_canonical_reads' => (bool) env('ASSET_REGISTRY_STRICT_CANONICAL_READS', false),

    // This guards the legacy physical-asset create endpoint. Warehouse receipts
    // remain canonical and project a compatibility row until legacy storage is retired.
    'legacy_asset_writes_enabled' => (bool) env('ASSET_REGISTRY_LEGACY_WRITES_ENABLED', true),

    'observation_hours' => max(24, (int) env('ASSET_REGISTRY_OBSERVATION_HOURS', 24)),

    // Embedded into every verifier record so an observation window can prove
    // that all hourly checks came from the expected immutable release.
    'release_sha' => env('MOST_RELEASE_SHA'),
];
