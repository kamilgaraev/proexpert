<?php

declare(strict_types=1);

return [
    'invalid_transition' => 'Это изменение статуса договора недоступно.',
    'status_transition_only' => 'Изменяйте статус договора только через доступные действия.',
    'transitioned' => 'Статус договора изменен.',
    'archived' => 'Договор перенесен в архив.',
    'archive_instead_of_delete' => 'Удаление договора недоступно. Перенесите его в архив.',
    'transition_error' => 'Не удалось изменить статус договора.',
    'route_project_required' => 'Список проектов договора должен включать текущий проект.',
    'lifecycle' => [
        'description' => ':action договора: :from → :to.:reason',
        'reason' => ' Основание: :reason.',
        'actions' => [
            'activate' => 'Активация',
            'suspend' => 'Приостановка',
            'resume' => 'Возобновление',
            'complete' => 'Завершение',
            'terminate' => 'Расторжение',
            'archive' => 'Перенос в архив',
            'change' => 'Изменение статуса',
        ],
    ],
];
