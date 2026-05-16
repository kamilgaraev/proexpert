<?php

declare(strict_types=1);

return [
    'messages' => [
        'employee_created' => 'Сотрудник создан.',
        'employee_updated' => 'Сотрудник обновлен.',
        'employee_dismissed' => 'Сотрудник уволен.',
        'record_created' => 'Запись создана.',
        'record_updated' => 'Запись обновлена.',
        'payroll_source_built' => 'Источник зарплатных данных собран.',
        'payroll_period_validated' => 'Расчетный период проверен.',
    ],
    'errors' => [
        'unexpected' => 'Не удалось выполнить действие. Попробуйте позже или обратитесь к администратору.',
        'employee_not_found' => 'Сотрудник не найден.',
        'user_not_found' => 'Пользователь не найден в текущей организации.',
        'record_not_found' => 'Запись не найдена в текущей организации.',
        'project_not_found' => 'Проект не найден в текущей организации.',
        'staff_unit_structure_mismatch' => 'Штатная единица не соответствует выбранному подразделению или должности.',
        'assignment_overlap' => 'У сотрудника уже есть активное назначение на этот период.',
    ],
    'employee_statuses' => [
        'active' => 'Работает',
        'dismissed' => 'Уволен',
        'inactive' => 'Временно неактивен',
    ],
    'absence_types' => [
        'vacation' => 'Отпуск',
    ],
    'validation' => [
        'missing_assignment' => 'Нет активного назначения сотрудника на дату работы.',
        'missing_work_schedule' => 'Нет графика работы для назначения сотрудника.',
        'work_schedule_conflict' => 'График работы не допускает начисление на дату работы.',
        'absence_conflict' => 'Есть утвержденное отсутствие на дату работы.',
    ],
];
