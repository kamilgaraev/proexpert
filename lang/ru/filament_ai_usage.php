<?php

declare(strict_types=1);

return [
    'title' => 'Расходы ИИ',
    'subtitle' => 'Фактическое потребление моделей, токены и стоимость по организациям.',
    'filters' => 'Фильтры',
    'period_from' => 'С',
    'period_to' => 'По',
    'provider' => 'Провайдер',
    'model' => 'Модель',
    'operation' => 'Сценарий',
    'all_values' => 'Все',
    'apply' => 'Применить',
    'reset' => 'Сбросить',
    'summary' => [
        'requests' => 'Запросы',
        'input_tokens' => 'Входные токены',
        'output_tokens' => 'Выходные токены',
        'total_tokens' => 'Всего токенов',
        'total_cost' => 'Стоимость',
    ],
    'tables' => [
        'organizations' => 'По организациям',
        'models' => 'По моделям',
        'operations' => 'По сценариям',
        'daily' => 'По дням',
    ],
    'columns' => [
        'organization' => 'Организация',
        'provider' => 'Провайдер',
        'model' => 'Модель',
        'operation' => 'Сценарий',
        'date' => 'Дата',
        'requests' => 'Запросы',
        'input_tokens' => 'Вход',
        'output_tokens' => 'Выход',
        'total_tokens' => 'Токены',
        'cost' => 'Стоимость',
    ],
    'empty' => 'За выбранный период расходов не найдено.',
    'operations' => [
        'assistant_chat' => 'Ассистент',
        'rag_index' => 'Индексация RAG',
        'rag_query' => 'Поиск RAG',
        'project_pulse' => 'Пульс проекта',
    ],
];
