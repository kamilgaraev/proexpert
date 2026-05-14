<?php

declare(strict_types=1);

return [
    'errors' => [
        'organization_missing' => 'Не выбрана организация для работы с охраной труда.',
        'module_not_active' => 'Модуль охраны труда недоступен для текущей организации.',
        'project_not_found' => 'Выбранный объект не найден в текущей организации.',
        'user_not_found' => 'Ответственный пользователь не найден в текущей организации.',
        'permit_not_found' => 'Наряд-допуск не найден.',
        'incident_not_found' => 'Происшествие не найдено.',
        'violation_not_found' => 'Нарушение не найдено.',
        'permit_submit_invalid_status' => 'Наряд-допуск можно отправить только из черновика.',
        'permit_approve_invalid_status' => 'Согласовать можно только отправленный наряд-допуск.',
        'permit_close_invalid_status' => 'Закрыть можно только согласованный наряд-допуск.',
        'incident_start_invalid_status' => 'Расследование можно начать только по зарегистрированному происшествию.',
        'incident_close_invalid_status' => 'Закрыть можно только открытое происшествие.',
        'incident_close_evidence_required' => 'Для закрытия происшествия укажите причину и корректирующие действия.',
        'violation_resolve_invalid_status' => 'Устранить можно только открытое нарушение.',
        'violation_resolution_required' => 'Для устранения нарушения укажите результат.',
        'index_failed' => 'Не удалось загрузить данные охраны труда.',
        'store_failed' => 'Не удалось создать запись охраны труда.',
        'action_failed' => 'Не удалось выполнить действие по охране труда.',
    ],
    'messages' => [
        'permit_created' => 'Наряд-допуск создан.',
        'incident_created' => 'Происшествие зарегистрировано.',
        'violation_created' => 'Нарушение зарегистрировано.',
    ],
    'permit_statuses' => [
        'draft' => 'Черновик',
        'submitted' => 'На согласовании',
        'approved' => 'Согласован',
        'closed' => 'Закрыт',
        'cancelled' => 'Отменен',
    ],
    'incident_statuses' => [
        'reported' => 'Зарегистрировано',
        'investigating' => 'Расследуется',
        'closed' => 'Закрыто',
    ],
    'violation_statuses' => [
        'open' => 'Открыто',
        'resolved' => 'Устранено',
        'closed' => 'Закрыто',
    ],
    'problem_flags' => [
        'permit_expired' => 'Срок действия наряда-допуска истек.',
        'investigation_required' => 'Требуется расследование происшествия.',
        'violation_overdue' => 'Срок устранения нарушения просрочен.',
    ],
];
