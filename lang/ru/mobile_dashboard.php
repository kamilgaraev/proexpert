<?php

return [
    'errors' => [
        'unauthorized' => 'Сессия недействительна. Выполните вход заново.',
        'no_organization' => 'Не выбрана организация для работы в мобильном приложении.',
        'load_failed' => 'Не удалось загрузить дашборд.',
    ],
    'widgets' => [
        'project_overview' => [
            'title' => 'Обзор объекта',
            'description' => 'Текущий объект и ваша роль на нем.',
        ],
        'site_requests' => [
            'title' => 'Заявки с объекта',
            'description' => 'Активных заявок: :active. Просрочено: :overdue.',
        ],
        'site_request_approvals' => [
            'title' => 'Согласования',
            'description' => 'Ожидают решения: :pending. На рассмотрении: :review.',
        ],
        'warehouse' => [
            'title' => 'Склад',
            'description' => 'Складов: :warehouses. Низкий остаток: :low_stock.',
        ],
        'schedule' => [
            'title' => 'График работ',
            'description' => 'Событий на 7 дней: :upcoming. Блокирующих: :blocking.',
        ],
    ],
];
