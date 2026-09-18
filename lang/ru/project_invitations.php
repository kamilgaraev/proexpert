<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Приглашение в проект МОСТ',
        'title' => 'Вас пригласили в проект',
        'greeting' => 'Здравствуйте!',
        'body' => 'Вас пригласили присоединиться к проекту «:project» в роли «:role». Приглашает :organization.',
        'accept_button' => 'Принять приглашение',
        'expires_at' => 'Приглашение действительно до :date.',
        'message_label' => 'Сообщение:',
    ],
    'roles' => [
        'customer' => 'Заказчик',
        'general_contractor' => 'Генподрядчик',
        'contractor' => 'Подрядчик',
        'subcontractor' => 'Субподрядчик',
        'construction_supervision' => 'Стройконтроль',
        'designer' => 'Проектировщик',
        'observer' => 'Наблюдатель',
    ],
];
