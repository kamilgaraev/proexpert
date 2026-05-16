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
        'payroll_period_locked' => 'Расчетный период закрыт.',
        'export_package_created' => 'Пакет экспорта создан.',
    ],
    'errors' => [
        'unexpected' => 'Не удалось выполнить действие. Попробуйте позже или обратитесь к администратору.',
        'employee_not_found' => 'Сотрудник не найден.',
        'user_not_found' => 'Пользователь не найден в текущей организации.',
        'record_not_found' => 'Запись не найдена в текущей организации.',
        'project_not_found' => 'Проект не найден в текущей организации.',
        'staff_unit_structure_mismatch' => 'Штатная единица не соответствует выбранному подразделению или должности.',
        'assignment_overlap' => 'У сотрудника уже есть активное назначение на этот период.',
        'payroll_period_locked' => 'Расчетный период уже закрыт.',
        'payroll_period_not_validated' => 'Сначала проверьте расчетный период.',
        'payroll_period_has_blocking_issues' => 'В расчетном периоде есть блокирующие замечания.',
        'payroll_period_not_locked' => 'Сначала закройте расчетный период.',
        'payroll_source_empty' => 'В расчетном периоде нет данных для передачи.',
        'export_package_exists' => 'По расчетному периоду уже есть активный пакет экспорта.',
        'export_package_accepted' => 'Принятый пакет экспорта нельзя сформировать повторно.',
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
        'missing_accounting_mapping' => 'Не настроена статья затрат или счет для начисления.',
    ],
];
