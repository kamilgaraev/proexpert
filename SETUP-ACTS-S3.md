# Настройка сохранения актов в S3

## Проблема
Акты выполненных работ генерировались "на лету" и сразу отдавались пользователю для скачивания, не сохраняясь в S3. Это приводило к:
- Отсутствию архива документов
- Невозможности повторного получения того же файла
- Отсутствию backup-а важных документов

## Решение

### 1. Модификация контроллера актов

Изменен метод `exportPdf` в `ActReportsController`:
- Генерирует PDF акт
- Сохраняет файл в S3 в папке `documents/acts/{organization_id}/`
- Создает запись в таблице `files` для отслеживания
- Возвращает JSON с информацией о файле вместо прямого скачивания

### 2. Новый функционал

#### Проверка существующих файлов
```php
// Проверяет есть ли уже сохраненный PDF для данного акта
$existingFile = File::where('fileable_type', ContractPerformanceAct::class)
    ->where('fileable_id', $act->id)
    ->where('type', 'pdf_export')
    ->where('organization_id', $organizationId)
    ->first();
```

#### Сохранение в S3
```php
// Сохраняет PDF в S3 с правильной структурой папок
$path = "documents/acts/{$organizationId}/{$filename}";
Storage::disk('s3')->put($path, $pdfContent, 'public');
```

#### Создание записи в БД
```php
File::create([
    'organization_id' => $organizationId,
    'fileable_id' => $act->id,
    'fileable_type' => ContractPerformanceAct::class,
    'user_id' => $user->id,
    'name' => $filename,
    'original_name' => "Акт_{$act->act_document_number}.pdf",
    'path' => $path,
    'mime_type' => 'application/pdf',
    'size' => strlen($pdfContent),
    'disk' => 's3',
    'type' => 'pdf_export',
    'category' => 'act_report'
]);
```

### 3. Новые API методы

#### Экспорт PDF (модифицированный)
**GET** `/api/v1/admin/act-reports/{act}/export/pdf`

**Ответ:**
```json
{
    "success": true,
    "message": "Акт успешно создан и сохранен",
    "file_url": "https://s3-bucket-url/documents/acts/1/act_001_2024-01-15.pdf",
    "file_id": 123,
    "download_url": "/api/v1/admin/act-reports/1/download-pdf/123"
}
```

#### Скачивание сохраненного PDF
**GET** `/api/v1/admin/act-reports/{act}/download-pdf/{file}`

**Описание:** Скачивает ранее сохраненный PDF файл из S3

## Настройка Laravel Scheduler

### Скрипт автоматической настройки
Создан скрипт `setup-laravel-scheduler.sh` для автоматической настройки cron:

```bash
sudo chmod +x setup-laravel-scheduler.sh
sudo ./setup-laravel-scheduler.sh
```

### Ручная настройка
```bash
# Добавить в crontab
* * * * * cd /var/www/prohelper && php artisan schedule:run >> /dev/null 2>&1

# Проверить настроенные задачи
php artisan schedule:list
```

### Настроенные задачи
- **Очистка файлов**: Ежедневно в 03:00 - удаление "осиротевших" файлов старше 72 часов
- **Обработка подписок**: Ежедневно в 02:00 - автопродление подписок

## Структура файлов в S3

```
documents/
├── acts/
│   ├── {organization_id}/
│   │   ├── act_001_2024-01-15.pdf
│   │   ├── act_002_2024-01-16.pdf
│   │   └── ...
│   └── ...
└── ...
```

## Переменные окружения

Убедитесь что настроены переменные для S3:
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=your_region
AWS_BUCKET=your_bucket_name
AWS_URL=https://your-bucket.s3.region.amazonaws.com
```

## Миграция существующих данных

Для актов, созданных до внедрения этого функционала:
1. Запустите повторную генерацию через API
2. Или создайте команду для массовой генерации PDF для существующих актов

## Проверка работы

### 1. Проверка S3 настроек
```bash
cd /var/www/prohelper
php artisan tinker
Storage::disk('s3')->put('test.txt', 'Hello World');
Storage::disk('s3')->exists('test.txt');
Storage::disk('s3')->delete('test.txt');
```

### 2. Проверка scheduler
```bash
cd /var/www/prohelper
php artisan schedule:run
tail -f storage/logs/laravel.log
```

### 3. Проверка генерации актов
1. Перейти в админку
2. Выбрать акт
3. Нажать "Экспорт в PDF"
4. Проверить ответ API и наличие файла в S3

## Мониторинг

### Логи
- Laravel: `storage/logs/laravel.log`
- Scheduler: `storage/logs/schedule-files-cleanup.log`
- Cron: `journalctl -u cron -f`

### Метрики
- Количество сохраненных файлов в таблице `files`
- Размер папки `documents/acts/` в S3
- Частота генерации актов

## Откат изменений

Если нужно вернуться к старому поведению:
1. Заменить `return response()->json(...)` на `return $pdf->download($filename)` в методе `exportPdf`
2. Удалить создание записей в таблице `files`
3. Удалить метод `downloadPdf` 