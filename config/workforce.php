<?php

declare(strict_types=1);

return [
    'timekeeping' => [
        'version' => 'RU-2026-09-01-v1',
        'verified_at' => '2026-10-14',
        // References are entered only after the employer actually approves its form and policy.
        'form' => [
            'version' => 'MOST-TIME-1',
            'approved_basis_reference' => env('WORKFORCE_TIMEKEEPING_FORM_BASIS'),
            'approved_at' => env('WORKFORCE_TIMEKEEPING_FORM_APPROVED_AT'),
        ],
        'retention' => [
            'ordinary_years' => 5,
            'hazardous_years' => 50,
            'pre2003_hazardous_years' => 75,
            'approved_basis_reference' => env('WORKFORCE_TIMEKEEPING_RETENTION_BASIS'),
        ],
        // Primary wording of the 2026 overtime amendment still needs employer legal review.
        'legal_review_required' => true,
    ],
];
