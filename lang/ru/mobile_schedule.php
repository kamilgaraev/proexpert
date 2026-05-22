<?php

return [
    'errors' => [
        'unauthorized' => 'Сессия недействительна. Выполните вход заново.',
        'no_organization' => 'Не выбрана организация для загрузки графика работ.',
        'project_not_found' => 'Выбранный объект не найден в текущей организации.',
        'load_failed' => 'Не удалось загрузить график работ.',
        'assignment_not_found' => 'Задание дневного плана не найдено.',
        'daily_plan_not_found' => 'Дневной план работ не найден.',
        'constraint_not_found' => 'Ограничение работ не найдено.',
        'constraint_not_open' => 'По этому ограничению уже нельзя создать связанную задачу.',
        'constraint_type_not_supported' => 'Для этого типа ограничения пока нет связанного рабочего процесса.',
        'constraint_material_data_required' => 'Для заявки на материал укажите материал, количество и единицу измерения в ограничении.',
        'constraint_personnel_data_required' => 'Для заявки на персонал укажите тип и количество исполнителей в ограничении.',
        'constraint_equipment_data_required' => 'Для заявки на технику укажите тип и количество техники в ограничении.',
        'constraint_equipment_due_date_required' => 'Для заявки на технику укажите срок устранения ограничения.',
        'fact_failed' => 'Не удалось зафиксировать факт дневного задания.',
        'submit_failed' => 'Не удалось передать дневной план на приемку.',
        'constraint_linked_action_failed' => 'Не удалось создать связанную задачу по ограничению.',
    ],
    'messages' => [
        'constraint_linked_action_created' => 'Связанная задача по ограничению создана.',
        'constraint_safety_action_due' => 'Проверить и оформить допуск до :date.',
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
