<?php

declare(strict_types=1);

use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;

return [
    'driver' => env('LEGAL_DOCUMENT_SIGNATURE_DRIVER', 'disabled'),
    'drivers' => [
        'disabled' => DisabledElectronicSignatureProvider::class,
    ],
    'callback_url' => env('LEGAL_DOCUMENT_SIGNATURE_CALLBACK_URL'),
    'redirect_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGAL_DOCUMENT_SIGNATURE_REDIRECT_HOSTS', ''))))),
    'start_lease_seconds' => (int) env('LEGAL_DOCUMENT_SIGNATURE_START_LEASE_SECONDS', 90),
    'max_session_seconds' => (int) env('LEGAL_DOCUMENT_SIGNATURE_MAX_SESSION_SECONDS', 900),
    'external_original' => [
        'max_bytes' => (int) env('LEGAL_DOCUMENT_SIGNATURE_MAX_CONTAINER_BYTES', 20 * 1024 * 1024),
        'extensions' => ['sig', 'p7s', 'p7m', 'xml'],
    ],
];
