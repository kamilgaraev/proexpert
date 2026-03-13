<?php

return [
    'errors' => [
        'unauthorized' => 'Сессия недействительна. Выполните вход заново.',
        'no_organization' => 'Не выбрана организация для загрузки графика работ.',
        'project_not_found' => 'Выбранный объект не найден в текущей организации.',
        'load_failed' => 'Не удалось загрузить график работ.',
    ],
    'statuses' => [
        'scheduled' => 'Запланировано',
        'in_progress' => 'В работе',
        'completed' => 'Завершено',
        'cancelled' => 'Отменено',
    ],
    'priorities' => [
        'low' => 'Низкий',
        'normal' => 'Обычный',
        'high' => 'Высокий',
        'critical' => 'Критичный',
    ],
    'event_types' => [
        'inspection' => 'Проверка',
        'delivery' => 'Поставка',
        'meeting' => 'Совещание',
        'maintenance' => 'Обслуживание',
        'weather' => 'Погодное событие',
        'other' => 'Событие',
    ],
];
