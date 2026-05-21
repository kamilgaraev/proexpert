<?php

declare(strict_types=1);

return [
    'loaded' => 'Данные получены.',
    'validation_error' => 'Проверьте корректность заполнения полей.',
    'organization_context_missing' => 'Контекст организации не определен.',
    'organization_not_found' => 'Организация не найдена.',
    'not_authenticated' => 'Пользователь не авторизован.',
    'access_denied' => 'Нет доступа к запрошенным данным.',

    'dashboard' => [
        'loaded' => 'Сводка получена.',
    ],

    'landing_admin' => [
        'loaded' => 'Администраторы получены.',
        'created' => 'Администратор создан.',
        'updated' => 'Администратор обновлен.',
        'deleted' => 'Администратор удален.',
    ],

    'admin_auth' => [
        'login_success' => 'Вход выполнен.',
        'login_failed' => 'Неверный email или пароль.',
        'profile_loaded' => 'Профиль получен.',
        'profile_not_found' => 'Профиль администратора не найден.',
        'token_refreshed' => 'Токен обновлен.',
        'token_error' => 'Не удалось обновить сессию.',
        'logged_out' => 'Выход выполнен.',
    ],

    'email_verification' => [
        'invalid_link' => 'Ссылка подтверждения email недействительна.',
        'already_verified' => 'Email уже подтвержден.',
        'verified' => 'Email успешно подтвержден.',
        'verify_error' => 'Не удалось подтвердить email.',
        'resent' => 'Письмо с подтверждением отправлено повторно.',
        'status_loaded' => 'Статус подтверждения email получен.',
    ],

    'holding_summary' => [
        'loaded' => 'Сводка холдинга получена.',
        'invalid_section' => 'Раздел для экспорта не найден.',
        'csv' => [
            'id' => 'ID',
            'organization' => 'Организация',
            'name' => 'Название',
            'status' => 'Статус',
            'start' => 'Начало',
            'end' => 'Окончание',
            'number' => 'Номер',
            'date' => 'Дата',
            'total_amount' => 'Сумма',
            'contract' => 'Договор',
            'amount' => 'Сумма',
            'approved' => 'Утвержден',
            'project' => 'Проект',
            'work_type' => 'Вид работ',
            'quantity' => 'Количество',
            'price' => 'Цена',
        ],
    ],

    'projects' => [
        'loaded' => 'Проекты получены.',
        'details_loaded' => 'Данные проекта получены.',
        'list_error' => 'Не удалось получить проекты.',
        'details_error' => 'Не удалось получить данные проекта.',
        'project_not_found' => 'Проект не найден.',
        'access_denied' => 'Нет доступа к этому проекту.',
    ],

    'organization_dashboard' => [
        'loaded' => 'Сводка организации получена.',
    ],

    'multi_organization' => [
        'module_inactive' => 'Модуль "Мультиорганизация" не активирован.',
        'holding_created' => 'Холдинг создан.',
        'child_add_forbidden' => 'Нет прав для добавления дочерней организации.',
        'child_added' => 'Дочерняя организация добавлена.',
        'target_access_denied' => 'Нет доступа к выбранной организации.',
        'context_changed' => 'Контекст организации изменен.',
        'child_updated' => 'Дочерняя организация обновлена.',
        'child_deleted' => 'Дочерняя организация удалена.',
        'child_user_added' => 'Пользователь добавлен в дочернюю организацию с персональной ролью.',
        'child_user_updated' => 'Данные пользователя обновлены.',
        'child_user_removed' => 'Пользователь исключен из дочерней организации.',
        'settings_updated' => 'Настройки холдинга обновлены.',
        'users_import_result' => 'Обработано пользователей: :total, успешно: :successful, ошибок: :failed.',
    ],

    'subscription_limits' => [
        'loaded' => 'Лимиты подписки получены.',
    ],

    'packages' => [
        'loaded' => 'Пакеты получены.',
        'subscribe_success' => 'Пакет подключен.',
        'unsubscribe_success' => 'Пакет отключен.',
        'load_error' => 'Не удалось получить список пакетов.',
        'subscribe_error' => 'Не удалось подключить пакет.',
        'unsubscribe_error' => 'Не удалось отключить пакет.',
    ],

    'custom_users' => [
        'created' => 'Пользователь создан и роли назначены.',
        'create_error' => 'Не удалось создать пользователя.',
        'roles_loaded' => 'Роли получены.',
        'roles_load_error' => 'Не удалось получить доступные роли.',
        'roles_updated' => 'Роли пользователя обновлены.',
        'roles_update_error' => 'Не удалось обновить роли пользователя.',
        'role_assigned' => 'Роль назначена пользователю.',
        'role_assign_error' => 'Не удалось назначить роль.',
        'role_unassigned' => 'Роль отозвана у пользователя.',
        'role_unassign_error' => 'Не удалось отозвать роль.',
        'limits_loaded' => 'Лимиты пользователя получены.',
        'limits_load_error' => 'Не удалось получить лимиты пользователя.',
        'user_not_found' => 'Пользователь не найден.',
        'user_not_in_organization' => 'Пользователь не принадлежит этой организации.',
    ],

    'organization_profile' => [
        'loaded' => 'Профиль организации получен.',
        'load_error' => 'Не удалось получить профиль организации.',
        'capabilities_updated' => 'Возможности организации обновлены.',
        'capabilities_update_error' => 'Не удалось обновить возможности организации.',
        'business_type_updated' => 'Основной тип деятельности обновлен.',
        'business_type_update_error' => 'Не удалось обновить основной тип деятельности.',
        'specializations_updated' => 'Специализации обновлены.',
        'specializations_update_error' => 'Не удалось обновить специализации.',
        'certifications_updated' => 'Сертификаты обновлены.',
        'certifications_update_error' => 'Не удалось обновить сертификаты.',
        'onboarding_completed' => 'Настройка профиля завершена.',
        'onboarding_complete_error' => 'Не удалось завершить настройку профиля.',
        'profile_incomplete' => 'Профиль заполнен менее чем на 50%. Заполните основную информацию.',
        'capabilities_loaded' => 'Доступные возможности получены.',
    ],

    'profile' => [
        'avatar_upload_error' => 'Не удалось загрузить аватар.',
        'save_error' => 'Не удалось сохранить изменения профиля.',
        'updated' => 'Профиль обновлен.',
        'update_error' => 'Не удалось обновить профиль.',
    ],

    'roles' => [
        'loaded' => 'Сравнение ролей получено.',
        'all_roles' => 'Все роли',
        'contexts' => [
            'system' => 'Система',
            'organization' => 'Организация',
            'project' => 'Проект',
        ],
        'interfaces' => [
            'admin' => 'Админ-панель',
            'lk' => 'Личный кабинет',
            'mobile' => 'Мобильное приложение',
        ],
        'days' => [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье',
            'monday' => 'Понедельник',
            'tuesday' => 'Вторник',
            'wednesday' => 'Среда',
            'thursday' => 'Четверг',
            'friday' => 'Пятница',
            'saturday' => 'Суббота',
            'sunday' => 'Воскресенье',
        ],
    ],

    'system_roles' => [
        'loaded' => 'Системные роли получены.',
    ],

    'site_templates' => [
        'loaded' => 'Шаблоны получены.',
        'details_loaded' => 'Шаблон получен.',
        'load_error' => 'Не удалось получить шаблоны.',
        'not_found' => 'Шаблон не найден.',
    ],

    'holding_sites' => [
        'manage_forbidden' => 'Недостаточно прав для управления сайтами холдинга.',
        'list_error' => 'Не удалось получить список сайтов.',
        'create_forbidden' => 'Недостаточно прав для создания сайта.',
        'validation_error' => 'Проверьте корректность заполнения полей.',
        'created' => 'Сайт создан.',
        'create_error' => 'Не удалось создать сайт.',
        'view_forbidden' => 'Недостаточно прав для просмотра сайта.',
        'details_error' => 'Не удалось получить данные сайта.',
        'edit_forbidden' => 'Недостаточно прав для редактирования сайта.',
        'settings_updated' => 'Настройки сайта обновлены.',
        'settings_update_error' => 'Не удалось обновить настройки сайта.',
        'update_error' => 'Не удалось обновить сайт.',
        'deleted' => 'Сайт удален.',
        'delete_error' => 'Не удалось удалить сайт.',
    ],

    'holding_reports' => [
        'dashboard_error' => 'Не удалось получить данные дашборда.',
        'comparison_error' => 'Не удалось получить сравнение организаций.',
        'period_too_long' => 'Максимальный период отчета - 1 год.',
        'validation_error' => 'Проверьте корректность заполнения полей.',
        'financial_error' => 'Не удалось получить финансовый отчет.',
        'kpi_error' => 'Не удалось получить KPI-метрики.',
        'quick_metrics_error' => 'Не удалось получить быстрые метрики.',
        'cache_clear_forbidden' => 'Только владельцы могут очищать кеш.',
        'cache_cleared' => 'Кеш отчетов очищен.',
        'cache_clear_error' => 'Не удалось очистить кеш отчетов.',
    ],
];
