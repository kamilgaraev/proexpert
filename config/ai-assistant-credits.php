<?php

declare(strict_types=1);

return [
    'price_version' => 2,
    'release_sha' => env('MOST_RELEASE_SHA'),
    'enforce' => (bool) env('AI_ASSISTANT_CREDITS_ENFORCE', false),
    'quote_ttl_seconds' => 300,
    'unit_minor' => 100,
    'rub_per_unit' => 0.18,
    'minimum_units_minor' => 50,
    'charge_step_minor' => 50,
    'profiles' => [
        'short' => ['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2],
        'normal' => ['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4],
        'detailed' => ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6],
        'ocr' => ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6],
    ],
    'pricing' => ['input_micro_rub_per_million' => 13_500_000, 'output_micro_rub_per_million' => 67_500_000],
    'packs' => [
        'ai-credits-1000' => ['units_minor' => 100_000, 'amount_minor' => 100_000],
        'ai-credits-5000' => ['units_minor' => 500_000, 'amount_minor' => 450_000],
        'ai-credits-10000' => ['units_minor' => 1_000_000, 'amount_minor' => 800_000],
    ],
];
