<?php

return [
    // Экспорт сметы
    'export_excel_success' => 'Смета успешно экспортирована в Excel',
    'export_pdf_success' => 'Смета успешно экспортирована в PDF',
    'export_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'export_not_found' => 'Смета не найдена или у вас нет прав доступа',
    'export_validation_error' => 'Ошибка валидации параметров экспорта',

    // Импорт сметы
    'import_most_success' => 'Смета МОСТ успешно импортирована с сохранением всех связей',
    'import_most_error' => 'Произошла ошибка, свяжитесь с технической поддержкой',
    'import_started' => 'Импорт сметы запущен',
    'import_completed' => 'Импорт сметы завершен успешно',
    'import_queued' => 'Импорт сметы поставлен в очередь',
    'import_processing_started' => 'Подготовка сметы к импорту',
    'import_processing_file' => 'Файл сметы обрабатывается',
    'import_processing_rows' => 'Обрабатываем строки сметы: :count',
    'import_processed_rows' => 'Обработано строк: :current из :total',
    'import_recalculating_totals' => 'Пересчитываем итоги сметы',
    'import_validated' => 'Смета проверена и готова к импорту',
    'import_validation_failed' => 'В смете найдены ошибки, исправьте их перед импортом',
    'import_validation_failed_with_reason' => 'Импорт остановлен: :reason',
    'import_format_not_detected' => 'Не удалось определить формат сметы',
    'import_unsupported_format' => 'Формат файла не поддерживается',
    'import_failed' => 'Импорт сметы завершился с ошибкой',
    'import_file_invalid' => 'Недопустимый формат файла',
    'import_file_too_large' => 'Файл слишком большой',
    'import_file_required' => 'Необходимо выбрать файл для загрузки',
    'import_file_mimes' => 'Поддерживаемые форматы: Excel (.xlsx, .xls, .xlsm), XML (.xml), CSV (.csv), текст (.txt), PDF (.pdf)',
    'import_file_max' => 'Размер файла не должен превышать 10 МБ',
    'import_file_id_required' => 'Не указан идентификатор файла',
    'import_matching_config_required' => 'Необходимо указать настройки сопоставления',
    'import_create_work_types_required' => 'Необходимо указать, создавать ли новые виды работ',
    'import_estimate_settings_required' => 'Необходимо указать настройки сметы',
    'import_estimate_name_required' => 'Необходимо указать название сметы',
    'import_estimate_type_required' => 'Необходимо указать тип сметы',
    'import_estimate_type_invalid' => 'Недопустимый тип сметы',
    'import_format_detected' => 'Определен формат сметы: :format',
    'import_most_detected' => 'Обнаружен формат МОСТ - будут восстановлены все связи и метаданные',

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
    'version_compare_failed' => 'Не удалось сравнить версии сметы',
    'version_not_found' => 'Версия сметы не найдена',
    
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
    'import_empty_preview' => 'В файле не найдены строки сметы для импорта',
    'import_low_confidence' => 'Формат сметы определён с низкой уверенностью, проверьте сопоставление колонок',
    'import_header_not_found' => 'Не удалось определить строку с заголовками колонок',
    'import_sections_not_found' => 'Разделы не найдены, позиции будут импортированы общим списком',
    'import_pdf_parser_unavailable' => 'PDF не удалось прочитать: модуль чтения PDF недоступен',
    'import_pdf_text_layer_empty' => 'В PDF не найден текстовый слой, потребуется распознавание или ручная проверка',
    'import_pdf_text_extract_failed' => 'Не удалось извлечь текст из PDF',
    'import_pdf_text_layer_low_quality' => 'Текстовый слой PDF не похож на корректную таблицу сметы, пробуем распознавание',
    'import_pdf_requires_staging' => 'PDF-смета требует обязательной проверки перед импортом',
    'import_pdf_no_rows' => 'В PDF не найдены строки сметы для импорта',
    'import_pdf_table_quality_failed' => 'Не удалось надежно разобрать таблицу PDF. Загрузите исходный Excel/XML или проверьте смету вручную перед импортом.',
    'import_pdf_ocr_disabled' => 'Распознавание текста PDF временно отключено',
    'import_pdf_ocr_failed' => 'Не удалось распознать текст PDF',
    'import_pdf_ocr_empty' => 'После распознавания в PDF не найден текст сметы',

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
