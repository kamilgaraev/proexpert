<?php

declare(strict_types=1);

return [
    'admin_created' => 'Администратор создан.',
    'admin_updated' => 'Администратор обновлен.',
    'admin_deleted' => 'Администратор удален.',
    'admin_not_found' => 'Администратор не найден.',
    'admin_list_error' => 'Не удалось загрузить список администраторов.',
    'admin_show_error' => 'Не удалось загрузить данные администратора.',
    'admin_create_error' => 'Не удалось создать администратора.',
    'admin_update_error' => 'Не удалось обновить администратора.',
    'admin_delete_error' => 'Не удалось удалить администратора.',

    'admin_panel_created' => 'Пользователь админ-панели создан.',
    'admin_panel_updated' => 'Пользователь админ-панели обновлен.',
    'admin_panel_deleted' => 'Пользователь админ-панели удален.',
    'admin_panel_not_found' => 'Пользователь админ-панели не найден.',
    'admin_panel_list_error' => 'Не удалось загрузить пользователей админ-панели.',
    'admin_panel_show_error' => 'Не удалось загрузить пользователя админ-панели.',
    'admin_panel_create_error' => 'Не удалось создать пользователя админ-панели.',
    'admin_panel_update_error' => 'Не удалось обновить пользователя админ-панели.',
    'admin_panel_delete_error' => 'Не удалось удалить пользователя админ-панели.',
    'admin_panel_email_exists' => 'Пользователь с таким email уже существует. Используйте приглашение или укажите другой email.',
    'admin_panel_context_missing' => 'Организация не определена. Обновите страницу и попробуйте снова.',

    'email_already_verified' => 'Email уже подтвержден.',
    'verification_email_sent' => 'Письмо с подтверждением email отправлено повторно.',
    'verification_email_error' => 'Не удалось отправить письмо с подтверждением email.',
    'owner_only' => 'Действие доступно только владельцу организации.',
    'owner_granted' => 'Пользователь назначен владельцем организации.',
    'owner_grant_error' => 'Не удалось назначить владельца организации.',
    'owner_generic_assignment_forbidden' => 'Роль владельца назначается только через отдельное подтверждение.',
    'owner_target_not_found' => 'Пользователь не найден.',
    'owner_target_not_in_organization' => 'Пользователь не найден в этой организации.',
    'owner_target_inactive' => 'Сначала активируйте пользователя в организации.',
    'self_deactivate_forbidden' => 'Вы не можете отключить самого себя.',
    'validation' => [
        'email_required' => 'Укажите email.',
        'email_invalid' => 'Укажите корректный email.',
        'password_min' => 'Пароль должен содержать не менее 8 символов.',
        'password_confirmed' => 'Пароли не совпадают.',
        'role_required' => 'Выберите роль пользователя.',
        'role_invalid' => 'Выберите доступную роль пользователя.',
    ],
];
