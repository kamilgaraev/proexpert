<?php

declare(strict_types=1);

return [
    'scenarios' => [
        ['code' => 'BASE', 'name_key' => 'base', 'scenario_type' => 'base'],
        ['code' => 'OPTIMISTIC', 'name_key' => 'optimistic', 'scenario_type' => 'optimistic'],
        ['code' => 'STRESS', 'name_key' => 'stress', 'scenario_type' => 'stress'],
    ],
    'responsibility_centers' => [
        ['code' => 'PRODUCTION', 'name_key' => 'production', 'center_type' => 'department'],
        ['code' => 'SUPPLY', 'name_key' => 'supply', 'center_type' => 'department'],
        ['code' => 'MACHINERY', 'name_key' => 'machinery', 'center_type' => 'department'],
        ['code' => 'PTO', 'name_key' => 'pto', 'center_type' => 'department'],
        ['code' => 'ADMINISTRATION', 'name_key' => 'administration', 'center_type' => 'department'],
        ['code' => 'FINANCE', 'name_key' => 'finance', 'center_type' => 'department'],
    ],
    'expenses' => [
        ['code' => 'MATERIALS', 'name_key' => 'materials', 'management_cost_class' => 'non_labor'],
        ['code' => 'SUBCONTRACT', 'name_key' => 'subcontract', 'management_cost_class' => 'non_labor'],
        ['code' => 'SALARY', 'name_key' => 'salary', 'management_cost_class' => 'labor'],
        ['code' => 'SOCIAL', 'name_key' => 'social', 'management_cost_class' => 'labor'],
        ['code' => 'MACHINERY', 'name_key' => 'machinery', 'management_cost_class' => 'non_labor'],
        ['code' => 'FUEL', 'name_key' => 'fuel', 'management_cost_class' => 'non_labor'],
        ['code' => 'TRANSPORT', 'name_key' => 'transport', 'management_cost_class' => 'non_labor'],
        ['code' => 'RENT', 'name_key' => 'rent', 'management_cost_class' => 'non_labor'],
        ['code' => 'UTILITIES', 'name_key' => 'utilities', 'management_cost_class' => 'non_labor'],
        ['code' => 'ADMINISTRATION', 'name_key' => 'administration', 'management_cost_class' => 'non_labor'],
        ['code' => 'TAXES', 'name_key' => 'taxes', 'management_cost_class' => 'non_labor'],
        ['code' => 'OTHER', 'name_key' => 'other', 'management_cost_class' => 'non_labor'],
    ],
];
