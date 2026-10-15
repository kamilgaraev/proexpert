<?php

declare(strict_types=1);

$bundle = json_decode((string) file_get_contents(__DIR__.'/../resources/legal/2026-10-15.json'), true, 512, JSON_THROW_ON_ERROR);

return [
    'version' => $bundle['version'],
    'provider' => $bundle['provider'],
    'subprocessors' => json_decode((string) env('LEGAL_SUBPROCESSORS_JSON', '[]'), true, 512, JSON_THROW_ON_ERROR),
];
