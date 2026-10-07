<?php

declare(strict_types=1);

return [
    'version' => '2026-10-06.1',
    'provider' => [
        'name' => env('LEGAL_PROVIDER_NAME', ''),
        'status' => env('LEGAL_PROVIDER_STATUS', ''),
        'inn' => env('LEGAL_PROVIDER_INN', ''),
        'registration_number' => env('LEGAL_PROVIDER_REGISTRATION_NUMBER', ''),
        'address' => env('LEGAL_PROVIDER_ADDRESS', ''),
        'email' => env('LEGAL_PROVIDER_EMAIL', ''),
        'bank_details' => env('LEGAL_PROVIDER_BANK_DETAILS', ''),
        'tax_status' => env('LEGAL_PROVIDER_TAX_STATUS', ''),
    ],
    'subprocessors' => json_decode((string) env('LEGAL_SUBPROCESSORS_JSON', '[]'), true, 512, JSON_THROW_ON_ERROR),
];
