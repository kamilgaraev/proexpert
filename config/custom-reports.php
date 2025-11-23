<?php

return [
    'data_sources' => [
        'projects' => [
            'table' => 'projects',
            'model' => \App\Models\Project::class,
            'label' => 'Проекты',
            'category' => 'core',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true, 'sortable' => true],
                'address' => ['type' => 'string', 'label' => 'Адрес', 'filterable' => true],
                'budget_amount' => ['type' => 'decimal', 'label' => 'Бюджет', 'aggregatable' => true, 'sortable' => true, 'format' => 'currency'],
                'status' => ['type' => 'string', 'label' => 'Статус', 'filterable' => true, 'sortable' => true],
                'start_date' => ['type' => 'date', 'label' => 'Дата начала', 'filterable' => true, 'sortable' => true],
                'end_date' => ['type' => 'date', 'label' => 'Дата окончания', 'filterable' => true, 'sortable' => true],
                'is_archived' => ['type' => 'boolean', 'label' => 'Архивный', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'customer' => ['type' => 'string', 'label' => 'Заказчик', 'filterable' => true],
                'site_area_m2' => ['type' => 'decimal', 'label' => 'Площадь участка', 'aggregatable' => true, 'format' => 'number'],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'organization' => ['type' => 'belongsTo', 'target' => 'organizations', 'foreign_key' => 'organization_id', 'owner_key' => 'id'],
                'contracts' => ['type' => 'hasMany', 'target' => 'contracts', 'foreign_key' => 'project_id', 'local_key' => 'id'],
                'materialReceipts' => ['type' => 'hasMany', 'target' => 'material_receipts', 'foreign_key' => 'project_id', 'local_key' => 'id'],
                'completedWorks' => ['type' => 'hasMany', 'target' => 'completed_works', 'foreign_key' => 'project_id', 'local_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
        
        'contracts' => [
            'table' => 'contracts',
            'model' => \App\Models\Contract::class,
            'label' => 'Контракты',
            'category' => 'finances',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'number' => ['type' => 'string', 'label' => 'Номер контракта', 'filterable' => true, 'sortable' => true],
                'date' => ['type' => 'date', 'label' => 'Дата контракта', 'filterable' => true, 'sortable' => true],
                'total_amount' => ['type' => 'decimal', 'label' => 'Сумма', 'aggregatable' => true, 'sortable' => true, 'format' => 'currency'],
                'status' => ['type' => 'string', 'label' => 'Статус', 'filterable' => true, 'sortable' => true],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'contractor_id' => ['type' => 'integer', 'label' => 'ID подрядчика', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'start_date' => ['type' => 'date', 'label' => 'Дата начала', 'filterable' => true, 'sortable' => true],
                'end_date' => ['type' => 'date', 'label' => 'Дата окончания', 'filterable' => true, 'sortable' => true],
                'gp_percentage' => ['type' => 'decimal', 'label' => 'ГП %', 'aggregatable' => true, 'format' => 'percent'],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects', 'foreign_key' => 'project_id', 'owner_key' => 'id'],
                'contractor' => ['type' => 'belongsTo', 'target' => 'contractors', 'foreign_key' => 'contractor_id', 'owner_key' => 'id'],
                'payments' => ['type' => 'hasMany', 'target' => 'contract_payments', 'foreign_key' => 'contract_id', 'local_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
        
        'materials' => [
            'table' => 'materials',
            'model' => \App\Models\Material::class,
            'label' => 'Материалы',
            'category' => 'materials',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true, 'sortable' => true],
                'code' => ['type' => 'string', 'label' => 'Код', 'filterable' => true, 'sortable' => true],
                'default_price' => ['type' => 'decimal', 'label' => 'Цена', 'aggregatable' => true, 'sortable' => true, 'format' => 'currency'],
                'category' => ['type' => 'string', 'label' => 'Категория', 'filterable' => true, 'sortable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'is_active' => ['type' => 'boolean', 'label' => 'Активен', 'filterable' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'organization' => ['type' => 'belongsTo', 'target' => 'organizations', 'foreign_key' => 'organization_id', 'owner_key' => 'id'],
                'measurementUnit' => ['type' => 'belongsTo', 'target' => 'measurement_units', 'foreign_key' => 'measurement_unit_id', 'owner_key' => 'id'],
                'receipts' => ['type' => 'hasMany', 'target' => 'material_receipts', 'foreign_key' => 'material_id', 'local_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
        
        'completed_works' => [
            'table' => 'completed_works',
            'model' => \App\Models\CompletedWork::class,
            'label' => 'Выполненные работы',
            'category' => 'works',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'work_date' => ['type' => 'date', 'label' => 'Дата работы', 'filterable' => true, 'sortable' => true],
                'quantity' => ['type' => 'decimal', 'label' => 'Объем', 'aggregatable' => true, 'sortable' => true, 'format' => 'number'],
                'total_cost' => ['type' => 'decimal', 'label' => 'Стоимость', 'aggregatable' => true, 'sortable' => true, 'format' => 'currency'],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'work_type_id' => ['type' => 'integer', 'label' => 'ID вида работ', 'filterable' => true],
                'user_id' => ['type' => 'integer', 'label' => 'ID исполнителя', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects', 'foreign_key' => 'project_id', 'owner_key' => 'id'],
                'workType' => ['type' => 'belongsTo', 'target' => 'work_types', 'foreign_key' => 'work_type_id', 'owner_key' => 'id'],
                'user' => ['type' => 'belongsTo', 'target' => 'users', 'foreign_key' => 'user_id', 'owner_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
        
        'users' => [
            'table' => 'users',
            'model' => \App\Models\User::class,
            'label' => 'Пользователи',
            'category' => 'staff',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'name' => ['type' => 'string', 'label' => 'Имя', 'filterable' => true, 'sortable' => true],
                'email' => ['type' => 'string', 'label' => 'Email', 'filterable' => true, 'sortable' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата регистрации', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'organizations' => ['type' => 'belongsToMany', 'target' => 'organizations'],
                'projects' => ['type' => 'belongsToMany', 'target' => 'projects'],
            ],
            'default_filters' => [],
        ],
        
        'contractors' => [
            'table' => 'contractors',
            'model' => \App\Models\Contractor::class,
            'label' => 'Подрядчики',
            'category' => 'finances',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true, 'sortable' => true],
                'inn' => ['type' => 'string', 'label' => 'ИНН', 'filterable' => true, 'sortable' => true],
                'contact_person' => ['type' => 'string', 'label' => 'Контактное лицо', 'filterable' => true],
                'phone' => ['type' => 'string', 'label' => 'Телефон', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'organization' => ['type' => 'belongsTo', 'target' => 'organizations', 'foreign_key' => 'organization_id', 'owner_key' => 'id'],
                'contracts' => ['type' => 'hasMany', 'target' => 'contracts', 'foreign_key' => 'contractor_id', 'local_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
        
        'material_receipts' => [
            'table' => 'material_usage_logs',
            'model' => \App\Models\Models\Log\MaterialUsageLog::class,
            'label' => 'Приемки материалов',
            'category' => 'materials',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'usage_date' => ['type' => 'date', 'label' => 'Дата приемки', 'filterable' => true, 'sortable' => true],
                'quantity' => ['type' => 'decimal', 'label' => 'Количество', 'aggregatable' => true, 'sortable' => true, 'format' => 'number'],
                'unit_price' => ['type' => 'decimal', 'label' => 'Цена за единицу', 'aggregatable' => true, 'format' => 'currency'],
                'total_price' => ['type' => 'decimal', 'label' => 'Общая стоимость', 'aggregatable' => true, 'sortable' => true, 'format' => 'currency'],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'material_id' => ['type' => 'integer', 'label' => 'ID материала', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects', 'foreign_key' => 'project_id', 'owner_key' => 'id'],
                'material' => ['type' => 'belongsTo', 'target' => 'materials', 'foreign_key' => 'material_id', 'owner_key' => 'id'],
                'supplier' => ['type' => 'belongsTo', 'target' => 'suppliers', 'foreign_key' => 'supplier_id', 'owner_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
                ['field' => 'operation_type', 'operator' => '=', 'value' => 'receipt'],
            ],
        ],
        
        'time_entries' => [
            'table' => 'time_entries',
            'model' => \App\Models\TimeEntry::class,
            'label' => 'Учет рабочего времени',
            'category' => 'staff',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'date' => ['type' => 'date', 'label' => 'Дата', 'filterable' => true, 'sortable' => true],
                'hours' => ['type' => 'decimal', 'label' => 'Часы', 'aggregatable' => true, 'sortable' => true, 'format' => 'number'],
                'user_id' => ['type' => 'integer', 'label' => 'ID пользователя', 'filterable' => true],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true, 'hidden' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата создания', 'filterable' => true, 'sortable' => true],
            ],
            'relations' => [
                'user' => ['type' => 'belongsTo', 'target' => 'users', 'foreign_key' => 'user_id', 'owner_key' => 'id'],
                'project' => ['type' => 'belongsTo', 'target' => 'projects', 'foreign_key' => 'project_id', 'owner_key' => 'id'],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
            ],
        ],
    ],
    
    'categories' => [
        'core' => 'Основные',
        'finances' => 'Финансы',
        'materials' => 'Материалы',
        'works' => 'Работы',
        'staff' => 'Персонал',
    ],
    
    'limits' => [
        'max_joins' => 7,
        'max_result_rows' => 10000,
        'query_timeout_seconds' => 30,
        'max_aggregations' => 10,
        'max_filters' => 20,
        'max_columns' => 50,
        'max_custom_reports_per_org' => 50,
        'max_schedules_per_org' => 10,
    ],
    
    'allowed_operators' => [
        '=' => 'Равно',
        '!=' => 'Не равно',
        '>' => 'Больше',
        '<' => 'Меньше',
        '>=' => 'Больше или равно',
        '<=' => 'Меньше или равно',
        'like' => 'Содержит',
        'not_like' => 'Не содержит',
        'in' => 'В списке',
        'not_in' => 'Не в списке',
        'between' => 'Между',
        'is_null' => 'Пусто',
        'is_not_null' => 'Не пусто',
    ],
    
    'aggregation_functions' => [
        'sum' => 'Сумма',
        'avg' => 'Среднее',
        'count' => 'Количество',
        'min' => 'Минимум',
        'max' => 'Максимум',
        'count_distinct' => 'Уникальные значения',
    ],
    
    'export_formats' => [
        'csv' => 'CSV',
        'excel' => 'Excel (XLSX)',
        'pdf' => 'PDF',
    ],
    
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
        'prefix' => 'custom_report',
    ],
];

