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

    // Ошибки
    'validation_error' => 'Ошибка валидации данных',
    'calculation_error' => 'Ошибка расчета сметы',
    'section_not_found' => 'Раздел не найден',
    'item_not_found' => 'Позиция не найдена',
];
