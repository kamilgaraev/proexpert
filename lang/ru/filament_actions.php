<?php

declare(strict_types=1);

return [
    'audit' => [
        'deleted_title' => 'Удалена запись: :subject',
        'deleted_description' => 'Системный администратор удалил запись :subject через суперадминку.',
        'organization_suspended_title' => 'Организация приостановлена: :organization',
        'organization_suspended_description' => 'Системный администратор приостановил доступ организации :organization.',
        'organization_reactivated_title' => 'Организация активирована: :organization',
        'organization_reactivated_description' => 'Системный администратор восстановил доступ организации :organization.',
        'user_blocked_title' => 'Пользователь заблокирован: :user',
        'user_blocked_description' => 'Системный администратор заблокировал доступ пользователя :user.',
        'user_unblocked_title' => 'Пользователь разблокирован: :user',
        'user_unblocked_description' => 'Системный администратор восстановил доступ пользователя :user.',
        'user_email_verified_title' => 'Email пользователя подтвержден: :user',
        'user_email_verified_description' => 'Системный администратор подтвердил email пользователя :user.',
        'user_password_reset_sent_title' => 'Отправлена ссылка восстановления пароля: :user',
        'user_password_reset_sent_description' => 'Системный администратор отправил пользователю :user ссылку восстановления пароля.',
        'subscription_cancellation_scheduled_title' => 'Запланирована отмена подписки: :subscription',
        'subscription_cancellation_scheduled_description' => 'Системный администратор запланировал отмену подписки :subscription.',
        'subscription_reactivated_title' => 'Подписка активирована: :subscription',
        'subscription_reactivated_description' => 'Системный администратор восстановил действие подписки :subscription.',
        'subscription_manual_extension_granted_title' => 'Продлен срок подписки: :subscription',
        'subscription_manual_extension_granted_description' => 'Системный администратор вручную продлил срок действия подписки :subscription.',
        'subscription_manual_extension_revoked_title' => 'Отменено ручное продление подписки: :subscription',
        'subscription_manual_extension_revoked_description' => 'Системный администратор отменил ручное продление подписки :subscription.',
    ],
    'delete' => [
        'confirm' => 'Удалить',
        'article' => [
            'heading' => 'Удалить статью',
            'description' => 'Статья будет удалена из редакционного списка. Перед удалением убедитесь, что материал не нужен для публикации или истории работы редакции.',
        ],
        'category' => [
            'heading' => 'Удалить категорию',
            'description' => 'Категория будет удалена из блога. Проверьте, что к ней не привязаны актуальные материалы.',
        ],
        'comment' => [
            'heading' => 'Удалить комментарий',
            'description' => 'Комментарий будет удален из модерации и публичного обсуждения.',
        ],
        'media_asset' => [
            'heading' => 'Удалить медиафайл',
            'description' => 'Медиафайл будет удален из библиотеки. Проверьте, что он не используется в опубликованных материалах.',
        ],
        'tag' => [
            'heading' => 'Удалить тег',
            'description' => 'Тег будет удален из блога. Проверьте, что он не нужен для навигации и группировки статей.',
        ],
        'notification_template' => [
            'heading' => 'Удалить шаблон уведомления',
            'description' => 'Шаблон будет удален из панели уведомлений. Проверьте, что он не используется в активных сценариях отправки.',
        ],
    ],
    'organization' => [
        'suspend' => [
            'label' => 'Приостановить',
            'heading' => 'Приостановить организацию',
            'description' => 'Доступ организации к платформе будет остановлен. Действие попадет в журнал аудита суперадминки.',
            'confirm' => 'Приостановить',
            'success' => 'Организация приостановлена',
        ],
        'reactivate' => [
            'label' => 'Активировать',
            'heading' => 'Активировать организацию',
            'description' => 'Организация снова получит доступ к платформе. Действие попадет в журнал аудита суперадминки.',
            'confirm' => 'Активировать',
            'success' => 'Организация активирована',
        ],
    ],
    'user' => [
        'block' => [
            'label' => 'Заблокировать',
            'heading' => 'Заблокировать пользователя',
            'description' => 'Пользователь потеряет доступ к платформе. Действие попадет в журнал аудита суперадминки.',
            'confirm' => 'Заблокировать',
            'success' => 'Пользователь заблокирован',
        ],
        'unblock' => [
            'label' => 'Разблокировать',
            'heading' => 'Разблокировать пользователя',
            'description' => 'Пользователь снова сможет войти в платформу. Действие попадет в журнал аудита суперадминки.',
            'confirm' => 'Разблокировать',
            'success' => 'Пользователь разблокирован',
        ],
        'mark_email_verified' => [
            'label' => 'Подтвердить email',
            'heading' => 'Подтвердить email пользователя',
            'description' => 'Email будет отмечен как подтвержденный вручную. Используйте действие только после проверки владельца аккаунта.',
            'confirm' => 'Подтвердить',
            'success' => 'Email подтвержден',
        ],
        'send_password_reset' => [
            'label' => 'Сбросить пароль',
            'heading' => 'Отправить ссылку восстановления',
            'description' => 'Пользователь получит ссылку восстановления пароля через стандартный поток авторизации.',
            'confirm' => 'Отправить',
            'success' => 'Ссылка восстановления отправлена',
        ],
    ],
    'subscription' => [
        'reason' => 'Причина',
        'cancel_at_period_end' => [
            'label' => 'Отменить в конце периода',
            'heading' => 'Запланировать отмену подписки',
            'description' => 'Автосписание будет отключено, а действие попадет в журнал аудита суперадминки.',
            'confirm' => 'Запланировать отмену',
            'success' => 'Отмена подписки запланирована',
        ],
        'reactivate' => [
            'label' => 'Активировать',
            'heading' => 'Активировать подписку',
            'description' => 'Подписка снова станет активной, автосписание будет включено, а доступные модули синхронизируются.',
            'confirm' => 'Активировать',
            'success' => 'Подписка активирована',
        ],
        'extension' => [
            'invalid_days' => 'Срок продления должен быть больше нуля.',
            'days' => 'Дней',
            'grant_label' => 'Продлить вручную',
            'grant_heading' => 'Продлить срок подписки',
            'grant_success' => 'Срок подписки продлен',
            'revoke_label' => 'Отменить продление',
            'revoke_heading' => 'Отменить ручное продление',
            'revoke_success' => 'Ручное продление отменено',
        ],
    ],
];
