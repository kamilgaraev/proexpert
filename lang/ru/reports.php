<?php

declare(strict_types=1);

return [
    'available' => json_decode('"\u041c\u043e\u0434\u0443\u043b\u044c \u043e\u0442\u0447\u0435\u0442\u043e\u0432 \u0434\u043e\u0441\u0442\u0443\u043f\u0435\u043d."'),
    'module_not_available' => json_decode('"\u041c\u043e\u0434\u0443\u043b\u044c \u043e\u0442\u0447\u0435\u0442\u043e\u0432 \u043d\u0435\u0434\u043e\u0441\u0442\u0443\u043f\u0435\u043d \u0434\u043b\u044f \u0442\u0435\u043a\u0443\u0449\u0435\u0439 \u043e\u0440\u0433\u0430\u043d\u0438\u0437\u0430\u0446\u0438\u0438."'),
    'generated' => json_decode('"\u041e\u0442\u0447\u0435\u0442 \u0443\u0441\u043f\u0435\u0448\u043d\u043e \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d."'),
    'empty' => json_decode('"\u0414\u0430\u043d\u043d\u044b\u0435 \u043f\u043e \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u044b\u043c \u0444\u0438\u043b\u044c\u0442\u0440\u0430\u043c \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b."'),
    'generation_failed' => json_decode('"\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u043e\u0442\u0447\u0435\u0442."'),
    'storage_failed' => json_decode('"\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043e\u0442\u0447\u0435\u0442 \u0432 \u0445\u0440\u0430\u043d\u0438\u043b\u0438\u0449\u0435."'),
    'contractor_required' => json_decode('"\u041d\u0435\u043e\u0431\u0445\u043e\u0434\u0438\u043c\u043e \u0443\u043a\u0430\u0437\u0430\u0442\u044c \u043f\u043e\u0434\u0440\u044f\u0434\u0447\u0438\u043a\u0430 \u0434\u043b\u044f \u0434\u0435\u0442\u0430\u043b\u044c\u043d\u043e\u0433\u043e \u043e\u0442\u0447\u0435\u0442\u0430."'),
    'unsupported_export_format' => 'Неподдерживаемый формат экспорта.',
    'validation' => [
        'project_not_found' => 'Указанный объект не найден.',
        'contractor_not_found' => 'Указанный подрядчик не найден.',
        'contract_not_found' => 'Указанный договор не найден.',
        'act_status_invalid' => 'Выбран некорректный статус акта.',
        'date_to_after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
        'format_invalid' => 'Выбран некорректный формат отчета.',
    ],
    'errors' => [
        'report_not_found' => 'Отчёт не найден.',
        'report_scope_forbidden' => 'Отчёт недоступен в текущей организации.',
        'report_request_invalid' => 'Проверьте параметры запроса отчёта.',
        'report_filter_unsupported' => 'Один из фильтров не поддерживается этим отчётом.',
        'report_filter_value_not_found' => 'Выбранное значение фильтра недоступно.',
        'report_filter_range_invalid' => 'Диапазон фильтра задан неверно.',
        'report_sort_unsupported' => 'Выбранный порядок сортировки недоступен.',
        'report_cursor_invalid' => 'Не удалось продолжить просмотр строк отчёта.',
        'report_idempotency_key_invalid' => 'Не удалось безопасно повторить операцию с отчётом.',
        'report_idempotency_conflict' => 'Эта операция уже выполнялась с другими параметрами.',
        'report_snapshot_not_ready' => 'Данные отчёта ещё формируются.',
        'report_export_not_ready' => 'Файл отчёта ещё формируется.',
        'report_official_snapshot_unsealed' => 'Официальная версия отчёта ещё не зафиксирована.',
        'report_snapshot_expired' => 'Срок хранения данных отчёта истёк.',
        'report_export_expired' => 'Срок хранения файла отчёта истёк.',
        'report_export_limit_exceeded' => 'Отчёт слишком большой для выбранного формата.',
        'report_rate_limited' => 'Слишком много операций с отчётами. Повторите позже.',
        'report_source_unavailable' => 'Источник данных отчёта временно недоступен.',
        'report_dependency_failed' => 'Не удалось получить все данные для отчёта.',
        'report_internal_error' => 'Не удалось обработать отчёт. Повторите позже.',
    ],
];
