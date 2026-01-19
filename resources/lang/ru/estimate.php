<?php

return [
    // Экспорт сметы
    'export_excel_success' => 'Смета успешно экспортирована в Excel',
    'export_pdf_success' => 'Смета успешно экспортирована в PDF',
    'export_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'export_not_found' => 'Смета не найдена или у вас нет прав доступа',
    'export_validation_error' => 'Ошибка валидации параметров экспорта',

    // Импорт сметы
    'import_prohelper_success' => 'Смета Prohelper успешно импортирована с сохранением всех связей',
    'import_prohelper_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_started' => 'Импорт сметы запущен',
    'import_completed' => 'Импорт сметы завершен успешно',
    'import_failed' => 'Импорт сметы завершился с ошибкой',
    'import_file_invalid' => 'Недопустимый формат файла',
    'import_file_too_large' => 'Файл слишком большой',
    'import_format_detected' => 'Определен формат сметы: :format',
    'import_prohelper_detected' => 'Обнаружен формат Prohelper - будут восстановлены все связи и метаданные',

    // Операции со сметой
    'created' => 'Смета успешно создана',
    'updated' => 'Смета успешно обновлена',
    'deleted' => 'Смета успешно удалена',
    'duplicated' => 'Смета успешно дублирована',
    'recalculated' => 'Смета успешно пересчитана',
    'not_found' => 'Смета не найдена',
    'access_denied' => 'У вас нет прав для доступа к этой смете',

    // Статусы
    'status_draft' => 'Черновик',
    'status_in_review' => 'На проверке',
    'status_approved' => 'Утверждена',
    'status_rejected' => 'Отклонена',
    'status_archived' => 'В архиве',
    'status_cancelled' => 'Отменена',
    
    // Статусные переходы
    'status_changed_to_draft' => 'Смета возвращена в черновик',
    'status_changed_to_review' => 'Смета отправлена на проверку',
    'status_changed_to_approved' => 'Смета успешно утверждена',
    'status_changed_to_cancelled' => 'Смета отменена',
    'status_changed' => 'Статус сметы успешно изменен',
    
    // Ошибки статусов
    'status_cannot_change_cancelled' => 'Нельзя изменить статус отмененной сметы',
    'status_invalid_transition' => 'Недопустимый переход статуса из ":from" в ":to"',
    'status_can_approve_only_in_review' => 'Утвердить можно только смету со статусом "На проверке"',

    // Контекст
    'project_context_required' => 'Смета должна быть создана в контексте проекта',

    // Версии
    'version_created' => 'Новая версия сметы создана',
    'version_rollback' => 'Выполнен откат к версии сметы',
    
    // Шаблоны
    'template_created' => 'Шаблон успешно создан',
    'template_deleted' => 'Шаблон успешно удален',
    'template_applied' => 'Смета создана из шаблона',
    'template_shared' => 'Шаблон сделан доступным для холдинга',

    // Импорт смет
    'import_no_file' => 'Файл не загружен',
    'import_file_process_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_organization_not_found' => 'Организация не найдена',
    'import_upload_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_detect_type_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_detect_format_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_preview_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_file_id_empty' => 'file_id не может быть пустым',
    'import_match_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_project_required' => 'Параметр project_id обязателен',
    'import_execute_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_status_not_found' => 'Статус импорта не найден',

    // Каталог позиций
    'catalog_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'catalog_position_not_found' => 'Позиция не найдена',
    'catalog_position_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'catalog_position_created' => 'Позиция успешно создана',
    'catalog_position_create_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'catalog_position_updated' => 'Позиция успешно обновлена',
    'catalog_position_update_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'catalog_position_deleted' => 'Позиция успешно удалена',
    'catalog_position_delete_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'catalog_search_query_empty' => 'Поисковый запрос не может быть пустым',
    'catalog_search_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',

    // Позиции смет
    'item_added' => 'Позиция успешно добавлена',
    'items_added' => 'Позиции успешно добавлены',
    'item_updated' => 'Позиция успешно обновлена',
    'item_deleted' => 'Позиция успешно удалена',
    'item_moved' => 'Позиция успешно перемещена',
    'item_not_belongs_to_estimate' => 'Позиция не принадлежит данной смете',
    'items_reordered' => 'Порядок позиций успешно обновлен',
    'items_reorder_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'item_numbering_recalculated' => 'Нумерация позиций успешно пересчитана',
    'item_numbering_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',

    // Разделы
    'section_created' => 'Раздел успешно создан',
    'section_updated' => 'Раздел успешно обновлен',
    'section_deleted' => 'Раздел успешно удален',
    'section_moved' => 'Раздел успешно перемещен',
    'sections_reordered' => 'Порядок разделов успешно обновлен',
    'sections_reorder_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'section_not_belongs_to_estimate' => 'Раздел не принадлежит данной смете',
    'section_numbering_recalculated' => 'Нумерация разделов успешно пересчитана',
    'section_numbering_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'section_numbering_valid' => 'Нумерация корректна',
    'section_numbering_invalid' => 'Обнаружены ошибки в нумерации',
    'section_validation_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',

    // История цен
    'price_history_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'price_compare_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',

    // Импорт/экспорт позиций каталога
    'template_generation_error' => 'Не удалось создать шаблон',
    'positions_import_completed' => 'Импорт завершен. Импортировано: :imported, пропущено: :skipped',
    'positions_import_error' => 'Ошибка при импорте',
    'positions_export_error' => 'Не удалось экспортировать данные',

    // Категории каталога
    'categories_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'category_tree_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'category_not_found' => 'Категория не найдена',
    'category_load_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'category_created' => 'Категория успешно создана',
    'category_create_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'category_updated' => 'Категория успешно обновлена',
    'category_update_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'category_deleted' => 'Категория успешно удалена',
    'category_delete_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'categories_reordered' => 'Порядок категорий успешно изменен',
    'categories_reorder_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',

    // Ошибки
    'validation_error' => 'Ошибка валидации данных',
    'calculation_error' => 'Ошибка расчета сметы',
    'section_not_found' => 'Раздел не найден',
    'item_not_found' => 'Позиция не найдена',
    'delete_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
];
