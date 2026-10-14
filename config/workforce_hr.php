<?php

declare(strict_types=1);

// Commercial employers: Rosarchive Order 236 article 435; Order 237 section 4.
// 50/75 years depend on completion of recordkeeping, not on the date a contract was signed.
// https://archives.gov.ru/documents/2019-perechen-typdocs-organization.shtml
// https://archives.gov.ru/documents/2019-instrukciya-perechen-typdocs-organization.shtml
return [
    'document_retention_rules' => [
        'employment_contract' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 435; Order 237, sections 4.1 and 4.8',
        ],
        'employment_amendment' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 435; Order 237, sections 4.1 and 4.8',
        ],
        'employment_termination_agreement' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 435; Order 237, sections 4.1 and 4.8',
        ],
        'employment_order' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 434(a); Order 237, sections 4.1 and 4.8',
        ],
        'dismissal_order' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 434(a); Order 237, sections 4.1 and 4.8',
        ],
        'personnel_card' => [
            'class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'Rosarchive Order 236, article 444; Order 237, sections 4.1 and 4.8',
        ],
    ],
];
