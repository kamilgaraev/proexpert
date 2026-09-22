<?php

declare(strict_types=1);

return [
    'quote_version' => 2,
    'currency' => 'RUB',
    'billing_period_days' => 30,
    'renewal_processing_window_minutes' => 5,
    'trial_hours' => 72,
    'entry_package' => 'working-entry',
    'retired_entry_packages' => [
        'projects-processes',
        'planning-schedules',
        'estimates-norms',
    ],
    'full_suite_price' => 79900,
    'full_suite_recommendation_threshold' => 64000,
];
