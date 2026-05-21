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
        'scope_not_found' => 'Зона приемки не найдена.',
        'session_not_found' => 'Осмотр зоны приемки не найден.',
        'finding_not_found' => 'Замечание приемки не найдено.',
        'package_document_not_found' => 'Документ комплекта передачи не найден.',
        'location_parent_invalid' => 'Родительская локация относится к другому проекту.',
        'invalid_status' => 'Действие недоступно для текущего статуса приемки.',
        'open_findings_block_accept' => 'Нельзя принять зону, пока есть открытые замечания.',
        'open_findings_block_ready' => 'Нельзя передать зону на повторный осмотр, пока есть открытые замечания.',
        'required_documents_block_handover' => 'Нельзя передать зону заказчику без обязательных документов.',
        'finding_resolve_invalid_status' => 'Можно закрыть только открытое замечание приемки.',
        'validation_failed' => 'Проверьте данные приемки.',
        'action_failed' => 'Не удалось выполнить действие по приемке зоны.',
    ],
    'problem_flags' => [
        'open_findings' => 'Есть открытые замечания приемки.',
        'required_documents_missing' => 'Не все обязательные документы готовы к передаче.',
    ],
    'validation' => [
        'title_required' => 'Укажите замечание',
        'severity_required' => 'Укажите критичность замечания',
        'severity_invalid' => 'Выберите критичность из списка',
        'create_quality_defect_required' => 'Укажите, нужно ли создать дефект качества',
        'quality_defect_inspection_required' => 'Укажите, нужна ли проверка дефекта качества',
        'resolution_comment_required' => 'Укажите результат устранения',
    ],
];
