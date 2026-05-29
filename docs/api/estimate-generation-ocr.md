# OCR в AI-генерации смет

Документ описывает production-контур распознавания документов для модуля `EstimateGeneration`.

## Назначение

OCR-документы участвуют в генерации сметы как полноценный источник фактов:

- площади объекта и зон;
- этажность, высоты, габариты;
- инженерные системы;
- ссылки на документ, страницу и фрагмент текста для трассировки позиций сметы.

Генерация не должна стартовать, пока документы находятся в обработке или требуют решения пользователя.

## Поддерживаемые форматы

- PDF, JPG, JPEG, PNG: распознаются через OCR-провайдер.
- XLSX, XLS: читаются локальным spreadsheet extractor через PhpSpreadsheet без вызова OCR-провайдера.

Файлы сохраняются только в S3 через `FileService` в каталоге организации.

## Очереди

Распознавание документов:

- job: `ProcessEstimateGenerationDocumentJob`;
- connection: `redis_estimate_generation`;
- queue: `estimate-generation`;
- retries: 3;
- timeout: 600 секунд.

Генерация сметы:

- job: `GenerateEstimateDraftJob`;
- connection: `redis_estimate_generation`;
- queue: `estimate-generation`;
- timeout: 1800 секунд.

## Конфигурация

`config/estimate-generation.php`:

- `ocr.provider`;
- `ocr.enabled`;
- `ocr.languages`;
- `ocr.model`;
- `ocr.timeout_seconds`;
- `ocr.retry_attempts`;
- `ocr.retry_delay_ms`;
- `ocr.max_sync_file_bytes`;
- `ocr.max_spreadsheet_file_bytes`;
- `ocr.max_spreadsheet_rows`;
- `ocr.max_spreadsheet_columns`;
- `ocr.yandex.endpoint`;
- `ocr.yandex.folder_id`;
- `ocr.yandex.api_key`;
- `ocr.yandex.iam_token`;
- `ocr.yandex.auth_mode`.

## Статусы документа

- `uploaded`: файл принят, но обработка еще не началась.
- `queued`: файл поставлен в очередь.
- `processing`: идет распознавание или извлечение данных.
- `ready`: документ готов и может участвовать в генерации.
- `needs_review`: распознавание выполнено, но качество низкое или найдены конфликтующие факты.
- `failed`: обработка не выполнена.
- `ignored`: пользователь исключил документ из генерации.

## Readiness

`documents_summary` возвращается в session/status/document endpoints:

- `has_pending = true`: анализ и генерация блокируются.
- `has_action_required = true`: генерация блокируется, пользователь должен повторить обработку или исключить документ.
- `can_analyze = true`: нет документов в обработке.
- `can_generate = true`: нет документов в обработке и нет документов, требующих действия.

Если нет описания объекта и нет готовых документов, анализ/генерация возвращают `422`.

## Endpoints

Все endpoints находятся под:

`/api/v1/admin/projects/{project}/estimate-generation/sessions`

### Создать сессию

`POST /{project}/estimate-generation/sessions`

`description` может быть пустым, если дальше будут загружены документы. Для генерации без документов описание обязательно.

### Загрузить документы

`POST /{project}/estimate-generation/sessions/{session}/documents`

FormData:

- `files[]`: PDF/JPG/JPEG/PNG/XLSX/XLS, до 10 файлов.

Ответ:

```json
{
  "documents": [],
  "documents_summary": {
    "total": 1,
    "ready_count": 0,
    "pending_count": 1,
    "action_required_count": 0,
    "can_analyze": false,
    "can_generate": false
  }
}
```

### Список документов

`GET /{project}/estimate-generation/sessions/{session}/documents`

Возвращает безопасный payload без `storage_path`, raw content и секретов.

### Детали документа

`GET /{project}/estimate-generation/sessions/{session}/documents/{document}`

Возвращает страницы и извлеченные факты. Raw payload OCR-провайдера наружу не отдается.

### Повторить обработку

`POST /{project}/estimate-generation/sessions/{session}/documents/{document}/retry`

Доступно для `failed`, `needs_review`, `ignored`.

### Исключить документ

`POST /{project}/estimate-generation/sessions/{session}/documents/{document}/ignore`

Доступно для `failed`, `needs_review`.

### Анализ

`POST /{project}/estimate-generation/sessions/{session}/analyze`

Блокируется, если есть pending-документы.

### Генерация

`POST /{project}/estimate-generation/sessions/{session}/generate`

Блокируется, если есть pending/action-required документы.

## Observability

`OcrUsageLogger` пишет события:

- document queued;
- recognition started;
- recognition completed;
- recognition failed.

В логах нет содержимого документа, storage path, имени файла, API-ключей или raw OCR payload. Контекст содержит только технически безопасные идентификаторы, mime type, расширение, размер, checksum prefix, provider/model, количество страниц/фактов, качество и время обработки.
