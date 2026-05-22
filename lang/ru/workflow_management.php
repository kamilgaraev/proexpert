<?php

declare(strict_types=1);

return [
    'errors' => [
        'organization_missing' => 'Организация для работы не выбрана.',
        'module_not_active' => 'Раздел согласований недоступен для организации.',
        'permission_denied' => 'Недостаточно прав для действия с выполненной работой.',
        'task_not_found' => 'Выполненная работа не найдена.',
        'status_transition_forbidden' => 'Для текущего статуса это действие недоступно.',
        'validation_failed' => 'Проверьте заполнение полей.',
        'action_failed' => 'Не удалось выполнить действие с выполненной работой.',
    ],
    'messages' => [
        'approve' => 'Выполненная работа согласована.',
        'reject' => 'Выполненная работа отклонена.',
        'request_changes' => 'Запрошены изменения по выполненной работе.',
        'comment_added' => 'Комментарий добавлен.',
    ],
    'statuses' => [
        'draft' => 'Черновик',
        'pending' => 'Ожидает согласования',
        'in_review' => 'На доработке',
        'confirmed' => 'Согласовано',
        'cancelled' => 'Отменено',
        'rejected' => 'Отклонено',
    ],
    'origins' => [
        'manual' => 'Ручной ввод',
        'schedule' => 'График работ',
        'journal' => 'Журнал работ',
    ],
    'planning_statuses' => [
        'planned' => 'Запланировано',
        'requires_schedule' => 'Нужна привязка к графику',
    ],
    'actions' => [
        'approve' => 'Согласовать',
        'reject' => 'Отклонить',
        'request_changes' => 'Запросить изменения',
        'comment' => 'Добавить комментарий',
    ],
    'validation' => [
        'status_invalid' => 'Выбран неизвестный статус.',
        'project_invalid' => 'Выбран неизвестный объект.',
        'comment_required' => 'Укажите комментарий.',
        'comment_too_long' => 'Комментарий слишком длинный.',
        'reason_required' => 'Укажите причину отклонения.',
        'reason_too_long' => 'Причина слишком длинная.',
        'per_page_max' => 'За один запрос можно загрузить не больше 50 записей.',
    ],
];
