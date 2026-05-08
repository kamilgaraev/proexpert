<?php

declare(strict_types=1);

return [
    'events_loaded' => 'Журнал действий загружен.',
    'events_load_error' => 'Не удалось загрузить журнал действий.',
    'event_loaded' => 'Событие загружено.',
    'event_not_found' => 'Событие не найдено.',
    'event_load_error' => 'Не удалось загрузить событие.',
    'summary_loaded' => 'Сводка журнала действий загружена.',
    'summary_load_error' => 'Не удалось загрузить сводку журнала действий.',
    'actors_loaded' => 'Пользователи журнала действий загружены.',
    'actors_load_error' => 'Не удалось загрузить пользователей журнала действий.',
    'export_error' => 'Не удалось экспортировать журнал действий.',
    'system_actor' => 'Система',
    'unknown_actor' => 'Неизвестный пользователь',
    'unknown_target' => 'объект',
    'unknown_subject' => 'объект',
    'unknown_role' => 'роль',
    'events' => [
        'auth.login.success' => [
            'title' => ':actor вошел в систему',
            'description' => 'Успешный вход в административную панель.',
        ],
        'auth.login.failed' => [
            'title' => 'Неуспешная попытка входа',
            'description' => 'Система отклонила попытку входа.',
        ],
        'auth.access.denied' => [
            'title' => ':actor не получил доступ в админку',
            'description' => 'Система заблокировала вход из-за недостаточных прав.',
        ],
        'user.admin.created' => [
            'title' => ':actor создал пользователя :target',
            'description' => 'Пользователь добавлен в организацию.',
        ],
        'user.admin.updated' => [
            'title' => ':actor изменил пользователя :target',
            'description' => 'Данные пользователя обновлены.',
        ],
        'user.admin.role.assigned' => [
            'title' => ':actor назначил роль :role пользователю :target',
            'description' => 'Изменены права доступа пользователя.',
        ],
        'user.admin.role.revoked' => [
            'title' => ':actor отозвал роль :role у пользователя :target',
            'description' => 'Изменены права доступа пользователя.',
        ],
        'project.created' => [
            'title' => ':actor создал проект :subject',
            'description' => 'В организации создан новый проект.',
        ],
        'project.updated' => [
            'title' => ':actor изменил проект :subject',
            'description' => 'Данные проекта обновлены.',
        ],
        'procurement.supplier_request_created' => [
            'title' => ':actor создал запрос поставщика по объекту :subject',
            'description' => 'В закупках создан запрос поставщику.',
        ],
        'procurement.supplier_request_sent' => [
            'title' => ':actor отправил запрос поставщику по объекту :subject',
            'description' => 'Запрос поставщику отправлен.',
        ],
        'procurement.supplier_request_version_created' => [
            'title' => ':actor создал новую версию запроса поставщику',
            'description' => 'Версия запроса поставщику сохранена в закупках.',
        ],
        'procurement.supplier_request_cancelled' => [
            'title' => ':actor отменил запрос поставщику по объекту :subject',
            'description' => 'Запрос поставщику отменен.',
        ],
        'procurement.supplier_proposal_created' => [
            'title' => ':actor добавил предложение поставщика по объекту :subject',
            'description' => 'В закупках сохранено предложение поставщика.',
        ],
        'procurement.supplier_proposal_intake_recorded' => [
            'title' => ':actor зафиксировал получение предложения поставщика',
            'description' => 'Получение предложения поставщика отражено в закупках.',
        ],
        'procurement.supplier_proposal_version_created' => [
            'title' => ':actor создал новую версию предложения поставщика',
            'description' => 'Версия предложения поставщика сохранена.',
        ],
        'procurement.supplier_proposal_selected' => [
            'title' => ':actor выбрал предложение поставщика по объекту :subject',
            'description' => 'Предложение поставщика выбрано для дальнейшей работы.',
        ],
        'procurement.procurement_approval_requested' => [
            'title' => ':actor отправил закупку на согласование',
            'description' => 'В закупках запущено согласование.',
        ],
        'procurement.procurement_approval_approved' => [
            'title' => ':actor согласовал закупку',
            'description' => 'Согласование закупки завершено успешно.',
        ],
        'procurement.procurement_approval_rejected' => [
            'title' => ':actor отклонил закупку',
            'description' => 'Закупка отклонена на согласовании.',
        ],
        'procurement.purchase_order_created' => [
            'title' => ':actor создал заказ поставщику по объекту :subject',
            'description' => 'Заказ поставщику создан в закупках.',
        ],
        'procurement.materials_received' => [
            'title' => ':actor отметил поступление материалов по объекту :subject',
            'description' => 'Поступление материалов отражено в закупках.',
        ],
    ],
    'fallback' => [
        'title' => ':actor выполнил действие',
        'description' => 'Действие зафиксировано в журнале.',
    ],
    'context_labels' => [
        'role' => 'Роль',
        'scope' => 'Область',
        'status' => 'Статус',
        'reason' => 'Причина',
        'document_number' => 'Документ',
        'amount' => 'Сумма',
    ],
];
