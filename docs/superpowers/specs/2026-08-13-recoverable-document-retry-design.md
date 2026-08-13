# Восстановление AI-сметчика после исправимого сбоя документа

## Цель

Вернуть уполномоченному пользователю безопасный явный повтор обработки текущей версии документа после завершившейся системной ошибки, направлять его на шаг «Документы» и блокировать следующие шаги при отсутствии пригодных страниц.

## Контракт backend

- `retry_document` публикуется только для document-mutable сессии, текущей `source_version`, канонического `system_failure`, нулевого числа пригодных страниц и repairable terminal units без active pending/running attempt.
- Наличие immutable `explicit_document_retry_history` само по себе не запрещает повтор. Current explicit attempt со статусом `processing` запрещает новый key; terminal `failed/system_failure` разрешает новый key. Exact replay прежнего key возвращает прежний terminal result без новой lineage и dispatch.
- Accepted retry под row lock повторно проверяет tenant, ABAC, state/source fences и eligibility, добавляет history, создаёт UUID lineage, сбрасывает unit circuit-breaker state только для новой lineage, переводит документ в queue и сессию из resumable `failed` в `processing_documents`.
- Provider/S3 не вызываются в транзакции; job dispatch выполняется после commit. Generation quota повторно не списывается.
- Snapshot отдаёт явный `recommended_step`. Для `failed + resume_status=processing_documents` при document system failure это `documents`; downstream недоступен при `0 ready`.

## Контракт admin

- Навигация сначала использует `recommended_step`; legacy fallback явно распознаёт document-system codes и production-shaped `0/N` failure, не полагаясь только на `documents_`.
- Недоступный или устаревший active step заменяется серверной рекомендацией. При `0/22` summary и прочие downstream шаги disabled.
- Кнопка повторной обработки строится только из `available_actions` документа. Pending action блокирует double click.
- Idempotency key привязан к project/session/document/source/state capability. Timeout/remount сохраняет key; новый terminal snapshot/state создаёт новый key.
- Accepted/stale responses обновляют snapshot и документы; 409 сопровождается понятным сообщением.

## Безопасность и границы

- Integrity, hard-limit, user-action-required, stale, cross-tenant и no-ABAC состояния остаются fail-closed.
- История append-only. Автоматический бесконечный retry не добавляется; восстановление запускается только явным действием пользователя.
- Production document `168` не перезапускается в рамках выпуска; платный AI не вызывается.

## Проверка

- Backend: DB-free/API RED→GREEN, PostgreSQL wrapper без skip для concurrency/lineage/replay/tenant/source, incident/lifecycle/circuit-breaker regressions, syntax/Pint/Larastan/diff/UTF-8.
- Admin: Vitest/MSW для production snapshot/navigation/capability/idempotency/stale flows, TypeScript, ESLint/Prettier/diff/UTF-8; build не запускается.
- После deploy: `/ready`, release JSON backend/admin, защищённый endpoint без auth = 401, свежие logs/GlitchTip и read-only snapshot документа 168 без retry.

