<?php

declare(strict_types=1);

return [
    'version' => '2026-10-06.1',
    'reviewed' => (bool) env('LEGAL_REVIEWED', false),
    'commercial_enabled' => (bool) env('LEGAL_COMMERCIAL_ENABLED', false),
    'analytics_reviewed' => (bool) env('LEGAL_ANALYTICS_REVIEWED', false),
    'telegram_contact_notifications' => (bool) env('LEGAL_TELEGRAM_CONTACT_NOTIFICATIONS', false),
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
