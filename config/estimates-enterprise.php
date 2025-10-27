<?php

return [
    'normative_bases' => [
        'cache_ttl' => env('ESTIMATES_NORMATIVE_CACHE_TTL', 3600),
        
        'search' => [
            'min_query_length' => 3,
            'max_results_per_page' => 100,
            'fuzzy_threshold' => 0.3,
        ],
        
        'import' => [
            'batch_size' => 500,
            'timeout' => 300,
            'allowed_formats' => ['xlsx', 'xls', 'dbf', 'csv', 'xml'],
            'max_file_size' => 50 * 1024 * 1024,
        ],
    ],

    'price_indices' => [
        'cache_ttl' => env('ESTIMATES_INDICES_CACHE_TTL', 7200),
        
        'types' => [
            'construction_general' => 'Общестроительные работы',
            'construction_special' => 'Специальные строительные работы',
            'equipment' => 'Оборудование',
            'design_work' => 'Проектные работы',
            'survey_work' => 'Изыскательские работы',
            'other' => 'Прочие',
        ],
        
        'default_region' => env('ESTIMATES_DEFAULT_REGION', null),
    ],

    'coefficients' => [
        'types' => [
            'climatic' => 'Климатический',
            'seismic' => 'Сейсмический',
            'altitude' => 'Высотный',
            'winter' => 'Зимнее удорожание',
            'difficult_conditions' => 'Стесненные условия',
            'regional' => 'Региональный',
            'other' => 'Прочие',
        ],
        
        'max_coefficients_per_item' => 10,
    ],

    'libraries' => [
        'cache_ttl' => env('ESTIMATES_LIBRARIES_CACHE_TTL', 1800),
        
        'max_items_per_library' => 1000,
        'max_positions_per_item' => 100,
        
        'access_levels' => [
            'private' => 'Приватная',
            'organization' => 'Для организации',
            'public' => 'Публичная',
        ],
    ],

    'audit' => [
        'enabled' => env('ESTIMATES_AUDIT_ENABLED', true),
        
        'snapshots' => [
            'auto_on_approval' => true,
            'auto_periodic_days' => 30,
            'retention_days' => [
                'manual' => 365,
                'auto_approval' => 180,
                'auto_periodic' => 90,
                'before_major_change' => 180,
            ],
        ],
        
        'comparison_cache' => [
            'enabled' => true,
            'ttl_hours' => 24,
            'cleanup_interval_hours' => 6,
        ],
        
        'change_log' => [
            'partition_by' => 'month',
            'retention_months' => 12,
        ],
    ],

    'calculations' => [
        'default_vat_rate' => 20.0,
        'default_overhead_rate' => 15.0,
        'default_profit_rate' => 12.0,
        
        'rounding' => [
            'prices' => 2,
            'quantities' => 4,
            'indices' => 4,
            'coefficients' => 4,
        ],
        
        'methods' => [
            'base_index' => 'Базисно-индексный',
            'resource' => 'Ресурсный',
            'resource_index' => 'Ресурсно-индексный',
            'analog' => 'По аналогам',
        ],
    ],

    'export' => [
        'ks2' => [
            'enabled' => env('ESTIMATES_EXPORT_KS2_ENABLED', true),
            'formats' => ['xlsx', 'pdf'],
            'template' => 'ks2_federal',
        ],
        
        'ks3' => [
            'enabled' => env('ESTIMATES_EXPORT_KS3_ENABLED', true),
            'formats' => ['xlsx', 'pdf'],
            'template' => 'ks3_federal',
        ],
    ],

    'performance' => [
        'materialized_views' => [
            'enabled' => env('ESTIMATES_MATERIALIZED_VIEWS_ENABLED', true),
            'refresh_interval_hours' => 6,
        ],
        
        'query_cache' => [
            'enabled' => env('ESTIMATES_QUERY_CACHE_ENABLED', true),
            'tags' => [
                'normative_rates',
                'price_indices',
                'estimate_libraries',
            ],
        ],
        
        'background_jobs' => [
            'queue' => env('ESTIMATES_QUEUE', 'default'),
            'timeout' => 300,
        ],
    ],

    'permissions' => [
        'normatives' => [
            'view' => 'estimates.normatives.view',
            'import' => 'estimates.normatives.import',
            'export' => 'estimates.normatives.export',
        ],
        
        'libraries' => [
            'manage' => 'estimates.libraries.manage',
            'share' => 'estimates.libraries.share',
            'use_public' => 'estimates.libraries.use_public',
        ],
        
        'coefficients' => [
            'view' => 'estimates.coefficients.view',
            'apply' => 'estimates.coefficients.apply',
            'manage' => 'estimates.coefficients.manage',
        ],
        
        'audit' => [
            'view' => 'estimates.audit.view',
            'create_snapshots' => 'estimates.audit.create_snapshots',
            'restore' => 'estimates.audit.restore',
        ],
        
        'export_official' => [
            'ks2' => 'estimates.export.ks2',
            'ks3' => 'estimates.export.ks3',
        ],
    ],
];
