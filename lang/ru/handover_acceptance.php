<?php

declare(strict_types=1);

return [
    'messages' => [
        'location_created' => 'Локация проекта создана.',
        'scope_created' => 'Зона приемки создана.',
        'checklist_created' => 'Чек-лист приемки создан.',
        'session_created' => 'Сессия приемки создана.',
        'finding_created' => 'Замечание приемки создано.',
        'package_created' => 'Комплект передачи создан.',
    ],
    'errors' => [
        'module_inactive' => 'Модуль приемки зон недоступен для организации.',
        'project_not_found' => 'Проект не найден в текущей организации.',
        'location_not_found' => 'Локация проекта не найдена.',
        'location_parent_invalid' => 'Родительская локация относится к другому проекту.',
        'invalid_status' => 'Действие недоступно для текущего статуса приемки.',
        'open_findings_block_accept' => 'Нельзя принять зону, пока есть открытые замечания.',
        'open_findings_block_ready' => 'Нельзя передать зону на повторный осмотр, пока есть открытые замечания.',
        'required_documents_block_handover' => 'Нельзя передать зону заказчику без обязательных документов.',
        'finding_resolve_invalid_status' => 'Можно закрыть только открытое замечание приемки.',
        'action_failed' => 'Не удалось выполнить действие по приемке зоны.',
    ],
    'problem_flags' => [
        'open_findings' => 'Есть открытые замечания приемки.',
        'required_documents_missing' => 'Не все обязательные документы готовы к передаче.',
    ],
];
