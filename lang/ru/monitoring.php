<?php

declare(strict_types=1);

return [
    'common' => [
        'empty_value' => 'Не указано',
        'unknown' => 'Неизвестно',
    ],
    'application_errors' => [
        'navigation_label' => 'Ошибки приложения',
        'model_label' => 'Ошибка приложения',
        'plural_model_label' => 'Ошибки приложения',
        'sections' => [
            'summary' => 'Сводка ошибки',
            'context' => 'Контекст без чувствительных деталей',
        ],
        'fields' => [
            'group' => 'Группа',
            'severity' => 'Важность',
            'status' => 'Статус',
            'occurrences' => 'Повторов',
            'first_seen_at' => 'Впервые замечена',
            'last_seen_at' => 'Последний раз',
            'exception_class' => 'Класс ошибки',
            'module' => 'Модуль',
            'message' => 'Сообщение',
            'url' => 'Адрес',
            'method' => 'Метод',
            'file' => 'Файл',
            'organization' => 'Организация',
        ],
        'statuses' => [
            'unresolved' => 'Нерешена',
            'resolved' => 'Решена',
            'ignored' => 'Игнорируется',
        ],
        'severities' => [
            'warning' => 'Предупреждение',
            'error' => 'Ошибка',
            'critical' => 'Критичная',
        ],
        'filters' => [
            'last_seen_period' => 'Период последнего события',
            'from' => 'С даты',
            'until' => 'По дату',
        ],
        'actions' => [
            'status_changed' => 'Статус ошибки обновлен',
            'mark_resolved' => [
                'label' => 'Отметить решенной',
                'icon' => 'heroicon-o-check-circle',
                'heading' => 'Отметить ошибку решенной',
                'description' => 'Статус изменится на решенный. История ошибки и счетчики сохранятся.',
                'confirm' => 'Отметить решенной',
            ],
            'mark_ignored' => [
                'label' => 'Игнорировать',
                'icon' => 'heroicon-o-eye-slash',
                'heading' => 'Игнорировать ошибку',
                'description' => 'Ошибка останется в истории, но будет помечена как не требующая реакции.',
                'confirm' => 'Игнорировать',
            ],
            'mark_unresolved' => [
                'label' => 'Вернуть в работу',
                'icon' => 'heroicon-o-arrow-path',
                'heading' => 'Вернуть ошибку в работу',
                'description' => 'Статус изменится на нерешенный, чтобы команда снова видела ошибку как активную.',
                'confirm' => 'Вернуть в работу',
            ],
        ],
        'audit' => [
            'status_changed_title' => 'Статус ошибки приложения изменен',
            'status_changed_description' => 'Ошибка приложения получила статус: :status.',
        ],
    ],
];
