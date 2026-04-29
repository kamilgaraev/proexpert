<?php

declare(strict_types=1);

return [
    'blocked' => 'Действие заблокировано',
    'override_forbidden' => 'У вас нет права на обход блокировки',
    'override_reason_required' => 'Укажите причину обхода блокировки',
    'blockers' => [
        'missing_estimate_item' => 'Для записи журнала нужно выбрать позицию сметы',
        'schedule_missing' => 'Работа не привязана к задаче графика',
        'contract_missing' => 'Позиция сметы не покрыта договором',
        'contract_selection_required' => 'Позиция сметы покрыта несколькими договорами, выберите нужный договор',
        'contract_coverage_missing' => 'Выбранный договор пока не покрывает эту позицию сметы',
        'missing_planned_quantity' => 'Не указан плановый объём',
        'already_acted_or_reserved' => 'Объём уже включён в акт или зарезервирован черновиком',
    ],
];
