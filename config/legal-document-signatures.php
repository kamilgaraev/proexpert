<?php

declare(strict_types=1);

use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;

return [
    'driver' => env('LEGAL_DOCUMENT_SIGNATURE_DRIVER', 'disabled'),
    'drivers' => [
        'disabled' => DisabledElectronicSignatureProvider::class,
    ],
    'callback_url' => env('LEGAL_DOCUMENT_SIGNATURE_CALLBACK_URL'),
    'external_original' => [
        'max_bytes' => (int) env('LEGAL_DOCUMENT_SIGNATURE_MAX_CONTAINER_BYTES', 20 * 1024 * 1024),
        'extensions' => ['sig', 'p7s', 'p7m', 'xml'],
    ],
];
