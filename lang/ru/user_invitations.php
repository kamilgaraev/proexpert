<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Приглашение в ProHelper',
        'title' => 'Добро пожаловать в ProHelper',
        'greeting' => 'Здравствуйте!',
        'body' => 'Вас пригласили присоединиться к организации в ProHelper.',
        'legacy_body' => 'Вас пригласили присоединиться к системе управления проектами ProHelper.',
        'organization_label' => 'Организация:',
        'email_label' => 'E-mail:',
        'password_label' => 'Пароль:',
        'accept_button' => 'Принять приглашение',
        'login_button' => 'Войти в систему',
        'download_button' => 'Скачать приложение',
        'change_password_hint' => 'Рекомендуем изменить пароль после первого входа.',
        'expires_at' => 'Приглашение действительно до :date.',
        'footer' => ':year ProHelper. Все права защищены.',
    ],
    'roles' => [
        'organization_admin' => 'Администратор организации',
        'foreman' => 'Прораб',
        'web_admin' => 'Веб-администратор',
        'accountant' => 'Бухгалтер',
        'worker' => 'Рабочий',
        'admin' => 'Администратор',
    ],
    'statuses' => [
        'pending' => 'Ожидает принятия',
        'accepted' => 'Принято',
        'expired' => 'Истекло',
        'cancelled' => 'Отменено',
    ],
    'messages' => [
        'created' => 'Приглашение отправлено',
        'resent' => 'Приглашение отправлено повторно',
        'cancelled' => 'Приглашение отменено',
        'accepted' => 'Приглашение принято',
    ],
    'errors' => [
        'organization_required' => 'Не выбран контекст организации',
        'not_found' => 'Приглашение не найдено',
        'create_failed' => 'Не удалось отправить приглашение.',
        'cancel_failed' => 'Не удалось отменить приглашение.',
        'resend_failed' => 'Не удалось отправить приглашение повторно.',
        'accept_failed' => 'Не удалось принять приглашение.',
    ],
];
