# Диагностический hotfix Vision GPT-5.6 Luna

## Цель выпуска

Первый выпуск не меняет запрос GPT-5.6 Luna и не заявляет исправление причины HTTP 400. Он сохраняет безопасную диагностическую причину следующего отказа, предотвращает каскад одинаковых terminal 4xx и честно показывает системное завершение обработки без пригодных страниц.

## Контракт provider error

- Неуспешный HTTP body читается до закрытия stream с жёстким пределом; превышение фиксируется без сохранения остатка.
- Поддерживается OpenAI-compatible envelope `error.message`, `error.type`, `error.code`, `error.param`.
- Durable observability содержит только HTTP status, нормализованный content type, allowlisted typed fields, bounded redacted summary/preview, hash прочитанного body, модель, endpoint kind, prompt contract и payload-shape fingerprints.
- Заголовки, ключи, request body, prompt, пользовательский текст, image data/signed URL и произвольный raw response не сохраняются.
- Неизвестный или malformed envelope получает bounded redacted preview только во внутренней observability; API и UI получают общий человекочитаемый системный статус.

## Классификация и повторы

- `408`, `409`, `429` и `5xx` — transient provider failures и могут повторяться в пределах настроенного лимита.
- Остальные `4xx` — deterministic request rejection и не повторяются по сети внутри unit.
- Transport outcome после начала wire остаётся отдельным ambiguous/idempotency состоянием.
- Rejected request записывает unavailable usage с нулевыми tokens/cost.

## Document breaker

- Breaker fingerprint включает стабильный provider error identity, модель, endpoint kind, prompt contract и payload shape; изменчивые message, request ID, body hash, image/prompt contents в него не входят. Отдельный body hash остаётся только observability-сигналом.
- Два одинаковых terminal rejection для текущих document/source/attempt lineage являются минимальным доказательным порогом.
- Транзакционная блокировка документа сериализует два worker; оставшиеся pending units становятся `breaker_stopped` без выполнения HTTP.
- Другой document, source version, attempt lineage или processing route не наследует breaker; model/prompt contract immutable внутри attempt lineage.
- Уже completed units и их страницы не изменяются.

## Session и UX

- Ноль ready pages при provider/system failure — `failed`/`document_processing_system_failed`, не пользовательская проверка.
- `progress_percent = 100` означает только завершённое выполнение; readiness остаётся `blocked`.
- После выпуска явный `retry_document` доступен для исправленного системного результата; автоматический retry не запускается.

## Release gate

- RED→GREEN unit tests provider diagnostics/classification/replay/usage.
- PostgreSQL contracts breaker concurrency/scope/idempotency и session reconciliation без skip.
- PHP lint, Pint, targeted Larastan; frontend checks только если изменится admin.
- Одно независимое correctness/security/architecture review.
- Стандартные PR/merge/deploy и read-only canary (`/ready`, `release.json`, защищённый endpoint `401`, логи/GlitchTip) без Vision AI-вызова.
