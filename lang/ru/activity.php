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
    'audit_resource' => [
        'navigation_group' => 'Аудит',
        'navigation_label' => 'Журнал событий',
        'model_label' => 'Событие аудита',
        'plural_model_label' => 'Журнал событий',
        'empty_value' => 'Не указано',
        'empty_details' => 'Нет данных для отображения',
        'sections' => [
            'event' => 'Событие',
            'actor' => 'Инициатор',
            'subject' => 'Объект',
            'details' => 'Безопасные детали',
        ],
        'fields' => [
            'occurred_at' => 'Время события',
            'severity' => 'Уровень риска',
            'module' => 'Раздел',
            'event_type' => 'Тип события',
            'action' => 'Действие',
            'result' => 'Результат',
            'title' => 'Название',
            'description' => 'Описание',
            'actor_type' => 'Тип инициатора',
            'actor_name' => 'Инициатор',
            'actor_email' => 'Email инициатора',
            'interface' => 'Интерфейс',
            'ip_address' => 'IP-адрес',
            'correlation_id' => 'ID запроса',
            'organization' => 'Организация',
            'project' => 'Проект',
            'subject_type' => 'Тип объекта',
            'subject_id' => 'ID объекта',
            'subject_label' => 'Объект',
            'changes' => 'Изменения',
            'context' => 'Контекст',
        ],
        'filters' => [
            'from' => 'С даты',
            'until' => 'По дату',
        ],
        'actor_types' => [
            'system_admin' => 'Системный администратор',
            'user' => 'Пользователь',
            'system' => 'Система',
        ],
        'actions' => [
            'created' => 'Создание',
            'updated' => 'Изменение',
            'deleted' => 'Удаление',
            'viewed' => 'Просмотр',
            'approved' => 'Подтверждение',
            'rejected' => 'Отклонение',
            'cancelled' => 'Отмена',
            'assigned' => 'Назначение',
            'revoked' => 'Отзыв доступа',
            'exported' => 'Экспорт',
            'login' => 'Вход',
            'logout' => 'Выход',
        ],
        'severities' => [
            'info' => 'Информация',
            'notice' => 'Уведомление',
            'warning' => 'Предупреждение',
            'critical' => 'Критично',
        ],
    ],
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
        'project.deleted' => [
            'title' => ':actor удалил проект :subject',
            'description' => 'Проект удален из организации.',
        ],
        'contract.created' => [
            'title' => ':actor создал договор :subject',
            'description' => 'Новый договор добавлен в проект.',
        ],
        'contract.updated' => [
            'title' => ':actor изменил договор :subject',
            'description' => 'Данные договора обновлены.',
        ],
        'contract.deleted' => [
            'title' => ':actor удалил договор :subject',
            'description' => 'Договор удален из проекта.',
        ],
        'contract.side_review.resolved' => [
            'title' => ':actor уточнил стороны договора :subject',
            'description' => 'Тип сторон договора подтвержден вручную.',
        ],
        'performance_act.created' => [
            'title' => ':actor создал акт :subject',
            'description' => 'Акт выполненных работ добавлен к договору.',
        ],
        'performance_act.updated' => [
            'title' => ':actor изменил акт :subject',
            'description' => 'Данные акта выполненных работ обновлены.',
        ],
        'performance_act.works.modified' => [
            'title' => ':actor изменил состав работ в акте :subject',
            'description' => 'Список работ в акте выполненных работ обновлен.',
        ],
        'performance_act.deleted' => [
            'title' => ':actor удалил акт :subject',
            'description' => 'Акт выполненных работ удален.',
        ],
        'material.created' => [
            'title' => ':actor создал материал :subject',
            'description' => 'Новый материал добавлен в каталог.',
        ],
        'material.bulk.import' => [
            'title' => ':actor импортировал материалы',
            'description' => 'Каталог материалов обновлен импортом.',
        ],
        'time_tracking.entry.created' => [
            'title' => ':actor добавил запись учета времени :subject',
            'description' => 'Запись учета времени сохранена.',
        ],
        'time_tracking.entry.approved' => [
            'title' => ':actor утвердил запись учета времени :subject',
            'description' => 'Запись учета времени утверждена.',
        ],
        'time_tracking.entry.rejected' => [
            'title' => ':actor отклонил запись учета времени :subject',
            'description' => 'Запись учета времени отклонена.',
        ],
        'project_schedule.created' => [
            'title' => ':actor создал график :subject',
            'description' => 'График проекта создан.',
        ],
        'project_schedule.updated' => [
            'title' => ':actor изменил график :subject',
            'description' => 'График проекта обновлен.',
        ],
        'project_schedule.deleted' => [
            'title' => ':actor удалил график :subject',
            'description' => 'График проекта удален.',
        ],
        'project_schedule.exported' => [
            'title' => ':actor экспортировал график :subject',
            'description' => 'График проекта выгружен в файл.',
        ],
        'project_schedule.critical_path_calculated' => [
            'title' => ':actor пересчитал критический путь графика :subject',
            'description' => 'Критический путь графика пересчитан.',
        ],
        'project_schedule.baseline_saved' => [
            'title' => ':actor сохранил базовый план графика :subject',
            'description' => 'Базовый план графика сохранен.',
        ],
        'project_schedule.baseline_cleared' => [
            'title' => ':actor очистил базовый план графика :subject',
            'description' => 'Базовый план графика очищен.',
        ],
        'construction_journal.created' => [
            'title' => ':actor создал журнал работ :subject',
            'description' => 'Журнал работ добавлен в проект.',
        ],
        'construction_journal.updated' => [
            'title' => ':actor изменил журнал работ :subject',
            'description' => 'Данные журнала работ обновлены.',
        ],
        'construction_journal.deleted' => [
            'title' => ':actor удалил журнал работ :subject',
            'description' => 'Журнал работ удален из проекта.',
        ],
        'construction_journal_entry.created' => [
            'title' => ':actor создал запись журнала :subject',
            'description' => 'Запись журнала работ добавлена.',
        ],
        'construction_journal_entry.updated' => [
            'title' => ':actor изменил запись журнала :subject',
            'description' => 'Запись журнала работ обновлена.',
        ],
        'construction_journal_entry.deleted' => [
            'title' => ':actor удалил запись журнала :subject',
            'description' => 'Запись журнала работ удалена.',
        ],
        'construction_journal_entry.submitted' => [
            'title' => ':actor отправил запись журнала на согласование :subject',
            'description' => 'Запись журнала ожидает проверки.',
        ],
        'construction_journal_entry.approved' => [
            'title' => ':actor утвердил запись журнала :subject',
            'description' => 'Запись журнала работ утверждена.',
        ],
        'construction_journal_entry.rejected' => [
            'title' => ':actor отклонил запись журнала :subject',
            'description' => 'Запись журнала работ отклонена.',
        ],
        'schedule_task.created' => [
            'title' => ':actor создал задачу графика :subject',
            'description' => 'Задача добавлена в график проекта.',
        ],
        'schedule_task.updated' => [
            'title' => ':actor изменил задачу графика :subject',
            'description' => 'Задача графика обновлена.',
        ],
        'schedule_task.deleted' => [
            'title' => ':actor удалил задачу графика :subject',
            'description' => 'Задача удалена из графика проекта.',
        ],
        'schedule_dependency.created' => [
            'title' => ':actor создал связь задач графика :subject',
            'description' => 'Связь между задачами графика создана.',
        ],
        'schedule_dependency.updated' => [
            'title' => ':actor изменил связь задач графика :subject',
            'description' => 'Связь между задачами графика обновлена.',
        ],
        'schedule_dependency.deleted' => [
            'title' => ':actor удалил связь задач графика :subject',
            'description' => 'Связь между задачами графика удалена.',
        ],
        'auth.role.assigned' => [
            'title' => ':actor назначил роль :subject',
            'description' => 'Права доступа пользователя обновлены.',
        ],
        'auth.role.revoked' => [
            'title' => ':actor отозвал роль :subject',
            'description' => 'Права доступа пользователя изменены.',
        ],
        'module.renewed' => [
            'title' => ':actor продлил модуль :subject',
            'description' => 'Доступ к модулю продлен.',
        ],
        'workflow.override.used' => [
            'title' => ':actor применил обход правила :subject',
            'description' => 'Действие выполнено с ручным подтверждением.',
        ],
        'user_invitation.created' => [
            'title' => ':actor создал приглашение :subject',
            'description' => 'Пользователю отправлено приглашение в организацию.',
        ],
        'user_invitation.accepted' => [
            'title' => ':actor принял приглашение :subject',
            'description' => 'Пользователь присоединился к организации.',
        ],
        'user_invitation.cancelled' => [
            'title' => ':actor отменил приглашение :subject',
            'description' => 'Приглашение пользователя отменено.',
        ],
        'report.official_material_usage.exported' => [
            'title' => ':actor экспортировал отчет :subject',
            'description' => 'Отчет по использованию материалов выгружен.',
        ],
        'report.official_material_usage.viewed' => [
            'title' => ':actor открыл отчет :subject',
            'description' => 'Отчет по использованию материалов просмотрен.',
        ],
        'organization.verification.completed' => [
            'title' => ':actor завершил проверку организации :subject',
            'description' => 'Проверка данных организации завершена.',
        ],
        'organization.data.updated' => [
            'title' => ':actor изменил данные организации :subject',
            'description' => 'Реквизиты организации обновлены.',
        ],
        'contractor.invitation.sent' => [
            'title' => ':actor отправил приглашение подрядчику :subject',
            'description' => 'Подрядчику отправлено приглашение к сотрудничеству.',
        ],
        'contractor_marketplace.profile.published' => [
            'title' => ':actor опубликовал профиль подрядчика :subject',
            'description' => 'Профиль подрядчика стал доступен в закрытом каталоге.',
        ],
        'contractor_marketplace.profile.paused' => [
            'title' => ':actor скрыл профиль подрядчика :subject',
            'description' => 'Профиль подрядчика временно скрыт из закрытого каталога.',
        ],
        'contractor_marketplace.offer.sent' => [
            'title' => ':actor отправил офер подрядчику :target',
            'description' => 'Подрядчику отправлено проектное предложение на выполнение работ.',
        ],
        'contractor_marketplace.offer.viewed' => [
            'title' => ':actor просмотрел офер :subject',
            'description' => 'Подрядчик открыл проектное предложение.',
        ],
        'contractor_marketplace.offer.accepted' => [
            'title' => ':actor принял офер :subject',
            'description' => 'Подрядчик принял проектное предложение и добавлен в команду проекта.',
        ],
        'contractor_marketplace.offer.declined' => [
            'title' => ':actor отклонил офер :subject',
            'description' => 'Подрядчик отклонил проектное предложение.',
        ],
        'contractor_marketplace.offer.cancelled' => [
            'title' => ':actor отменил офер :subject',
            'description' => 'Проектное предложение подрядчику отменено.',
        ],
        'contractor_marketplace.offer.reviewed' => [
            'title' => ':actor оценил работу подрядчика :subject',
            'description' => 'Оценка по оферу обновила рейтинг подрядчика в marketplace.',
        ],
        'agreement.applied_to_contract' => [
            'title' => ':actor применил допсоглашение :subject',
            'description' => 'Дополнительное соглашение применено к договору.',
        ],
        'completed_work.created' => [
            'title' => ':actor добавил выполненную работу :subject',
            'description' => 'Выполненная работа сохранена в проекте.',
        ],
        'billing.transaction.credit' => [
            'title' => ':actor пополнил баланс :subject',
            'description' => 'Баланс организации увеличен.',
        ],
        'billing.transaction.debit' => [
            'title' => ':actor списал средства :subject',
            'description' => 'Средства списаны с баланса организации.',
        ],
        'subscription.created' => [
            'title' => ':actor создал подписку :subject',
            'description' => 'Подписка организации создана.',
        ],
        'subscription.updated' => [
            'title' => ':actor изменил подписку :subject',
            'description' => 'Параметры подписки обновлены.',
        ],
        'subscription.canceled' => [
            'title' => ':actor отменил подписку :subject',
            'description' => 'Подписка организации отменена.',
        ],
        'subscription.renewed' => [
            'title' => ':actor продлил подписку :subject',
            'description' => 'Подписка организации продлена.',
        ],
        'custom_report.created' => [
            'title' => ':actor создал отчет :subject',
            'description' => 'Пользовательский отчет сохранен.',
        ],
        'ai.assistant.action.executed' => [
            'title' => ':actor выполнил действие ИИ-ассистента :subject',
            'description' => 'ИИ-ассистент выполнил запрошенное действие.',
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
    'default_event' => [
        'title' => ':actor выполнил действие',
        'description' => 'Действие зафиксировано в журнале.',
    ],
    'context_labels' => [
        'role' => 'Роль',
        'scope' => 'Область',
        'status' => 'Статус',
        'marketplace_visibility' => 'Видимость в каталоге',
        'reason' => 'Причина',
        'document_number' => 'Документ',
        'contract_number' => 'Договор',
        'contract_side_type' => 'Стороны договора',
        'contractor_id' => 'Подрядчик',
        'supplier_id' => 'Поставщик',
        'project_id' => 'Проект',
        'amount' => 'Сумма',
    ],
];
