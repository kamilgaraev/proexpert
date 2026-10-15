<?php

declare(strict_types=1);

return [
    'default_provider' => env('BILLING_DEFAULT_PROVIDER', 'tochka'),
    'enabled' => (bool) env('TOCHKA_ENABLED', false),
    'jwt_path' => env('TOCHKA_JWT_PATH', ''),
    'customer_code' => env('TOCHKA_CUSTOMER_CODE', ''),
    'account_id' => env('TOCHKA_ACCOUNT_ID', ''),
    'start_date' => env('TOCHKA_START_DATE', '2026-10-15'),
    'seller_account' => env('TOCHKA_SETTLEMENT_ACCOUNT', ''),
    'seller_bik' => env('TOCHKA_BANK_BIK', ''),
    'seller_bank' => env('TOCHKA_BANK_NAME', ''),
    'seller_correspondent_account' => env('TOCHKA_CORRESPONDENT_ACCOUNT', ''),
    'invoice_days' => 7,
    'renewal_notice_days' => 7,
    'grace_days' => 7,
    'lookback_days' => 3,
    'timeout_seconds' => 15,
    'maximum_statement_bytes' => 10_000_000,
    'maximum_document_bytes' => 5_000_000,
];
