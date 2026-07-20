# Восстановление создания юридического досье

## Идентичность операции

Ручное создание передаёт `create_operation_key` — непустую строку до 191 символа, которую клиент сохраняет до получения ответа. Повторный `POST /api/v1/admin/legal-archive/documents` с тем же ключом, пользователем, организацией и содержимым возвращает исходное досье. Изменение содержимого при том же ключе считается конфликтом.

Создание из связанного объекта использует `source_type`, `source_id` и `source_idempotency_key`. В обоих сценариях сервер возвращает `create_recovery.operation_id` — стабильный UUID операции.

## Состояние ответа

`create_recovery` содержит:

- `operation_id`: UUID операции;
- `status`: `pending`, `failed` или `completed`;
- `retry_action`: `retry_upload`, `retry_finalize` или `null`;
- `attempt_count`: номер попытки;
- `started_at`, `heartbeat_at`, `lease_expires_at`: ISO 8601 или `null`.

Активная попытка возвращается с HTTP 202 и `meta.operation_result = in_progress`. `meta.retry_after` задаёт минимальную задержку следующей проверки. Клиент не должен перехватывать такую операцию до истечения аренды.

## Поиск и восстановление

`GET /api/v1/admin/legal-archive/document-recoveries?per_page=20` возвращает только незавершённые операции текущего пользователя, доступные ему в активной организации и проектном контексте. Ответ пагинирован стандартным `AdminResponse`.

`POST /api/v1/admin/legal-archive/document-recoveries/{operation_id}` восстанавливает операцию:

- при `retry_upload` запрос отправляется как `multipart/form-data` с обязательным `file`;
- при `retry_finalize` файл не передаётся;
- повторный запрос после завершения возвращает то же досье без новой версии;
- параллельный запрос к активной аренде получает HTTP 202 `in_progress`;
- неизвестная, чужая или недоступная операция не раскрывается пользователю.

При HTTP 202 после ошибки `meta.operation_id`, `meta.retry_action` и `meta.retry_document_id` определяют следующий запрос. Обычный endpoint добавления версии не восстанавливает незавершённое создание.
