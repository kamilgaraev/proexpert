<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payments Module Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация модуля платежей и взаиморасчётов
    |
    */

    /**
     * Автоматическая генерация счетов
     */
    'auto_create_invoices' => [
        'from_acts' => env('PAYMENTS_AUTO_INVOICE_FROM_ACTS', true),
        'from_warehouse' => env('PAYMENTS_AUTO_INVOICE_FROM_WAREHOUSE', false),
        'from_contracts' => env('PAYMENTS_AUTO_INVOICE_FROM_CONTRACTS', false),
    ],

    /**
     * Настройки просрочки
     */
    'overdue' => [
        'check_interval' => env('PAYMENTS_OVERDUE_CHECK_INTERVAL', 'hourly'), // hourly, daily
        'grace_period_days' => env('PAYMENTS_GRACE_PERIOD_DAYS', 0),
    ],

    /**
     * Напоминания о платежах
     */
    'reminders' => [
        'enabled' => env('PAYMENTS_REMINDERS_ENABLED', true),
        'days_before' => [3, 1], // За 3 дня и за 1 день до срока
    ],

    /**
     * Лимиты по умолчанию
     */
    'defaults' => [
        'payment_terms_days' => 30, // Срок оплаты по умолчанию
        'currency' => 'RUB',
        'vat_rate' => 20, // НДС 20%
    ],

    /**
     * Интеграции
     */
    'integrations' => [
        'bank_api_enabled' => env('PAYMENTS_BANK_API_ENABLED', false),
        'accounting_export' => env('PAYMENTS_ACCOUNTING_EXPORT', false),
    ],
];

