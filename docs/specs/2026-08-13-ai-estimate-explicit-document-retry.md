# Явный повтор обработки сохранённого документа AI-сметчика МОСТ

## Цель

Добавить контролируемое действие уровня документа для повторной обработки сохранённого `system_failure` после устранения системной причины. Повтор не требует повторной загрузки файла, не запускается автоматически и не применяется к пользовательским, целостностным, безопасностным или лимитным ошибкам.

## Границы

- Backend: Laravel 11, PostgreSQL row locks, существующий `AuthorizationService`, `AdminResponse`, очередь обработки документов и текущий S3 artifact.
- Admin: React 18, типизированный API, MUI dialog, Vitest и MSW 2.
- Без миграций, новых очередей, инфраструктуры, provider/S3 вызовов в транзакции и изменений месячной quota.
- Production retry документа `168` и любой платный AI-вызов не входят в выпуск и требуют отдельного разрешения пользователя.

## Backend-контракт

Существующий document retry endpoint остаётся точкой входа, но становится узким explicit-retry контрактом. Запрос содержит обязательные `state_version`, `source_version` и UUID `idempotency_key`. Path задаёт project/session/document scope; FormRequest и application service повторно применяют ABAC `estimate_generation.review`.

Под PostgreSQL `lockForUpdate` сервис повторно проверяет tenant, project, session, document, session state fence, source fence, актуальность документа, document-wide `system_failure`, отсутствие активной обработки и допустимость системной причины. Неподходящий или stale документ не dispatch'ится. Повтор того же idempotency key возвращает текущий результат той же lineage. Конкурирующий другой key после победителя получает безопасный `already_in_progress` без второго dispatch.

## Lineage, история и breaker

Текущий S3 path, checksum и `source_version` не меняются. У каждой принятой явной попытки новый UUID `processing_attempt_id`; прежняя попытка и hash idempotency key записываются в audit history документа без хранения исходного ключа.

Processing units не удаляются. Перед сбросом их прежние failure code, fingerprint, category, attempt count, output count и timestamps добавляются в `metadata.failure_history`. Затем только units текущего документа и текущего source переводятся в pending, очищаются lease/claim/current failure поля и получают новую lineage. Страницы текущего документа возвращаются в queued без создания 22 одинаковых пользовательских ошибок.

Такой reset обнуляет текущий breaker state, сохраняя старые fingerprints в истории. Если новая lineage снова получает три одинаковых circuit-breaking fingerprint, существующий breaker снова останавливает оставшиеся units.

## Idempotency и dispatch

Hash ключа и принятая lineage сохраняются вместе с document audit entry в одной транзакции. Queue job регистрируется через `DB::afterCommit`/`afterCommit`; rollback не создаёт dispatch. В транзакции нет provider/S3 сети. Retry не вызывает quota reservation: месячная AI-смета остаётся session-level, а downstream provider usage/cost продолжает записываться штатным ledger.

## Eligibility и capability

Backend action builder возвращает `retry_document` только после успешного ABAC и только с disposition `explicit_system_failure`. Action отсутствует для active processing, ready, stale/superseded source, user action required, integrity/security/corrupt/hard-limit failures и любого не document-wide system failure. Для legacy incident документа допустимость определяется текущими tenant-scoped units одного source: все failed, output_count=0, единый systemic fingerprint.

## Admin UX

Кнопка «Повторить обработку» строится только из backend action/capability/disposition. MUI dialog объясняет, что файл сохранён, повторная загрузка не нужна, обработка займёт время и действие выполняется один раз. Во время запроса кнопки disabled/loading.

UUID хранится в `sessionStorage` по project/session/document/source/action fence. Неопределённый сетевой результат и remount сохраняют ключ. Он очищается только после terminal snapshot или stale refresh, который явно заменяет действие. После успеха UI принимает backend snapshot/detail и отображает реальные processing counts. После 409 выполняется refresh и показывается бизнес-понятное сообщение.

## Проверки

- RED/GREEN unit/API tests без БД: eligibility, FormRequest/API contract, ABAC, stale/forbidden/current responses и single after-commit dispatch.
- Реальный PostgreSQL wrapper: same-key replay, concurrent different-key winner, source fence, audit/failure history, breaker reset и cross-tenant isolation; новые сценарии без skip.
- Регрессии incident flow: terminal без тройного retry, breaker, canonical counts/outcome и full-page Vision fallback.
- Admin Vitest/MSW: visibility, dialog, double click, timeout/remount key, success refresh, 409 refresh и no-button.
- PHP syntax, Pint, минимальный Larastan с честной фиксацией `storage_configuration_invalid`, UTF-8 и `git diff --check`.
- Admin Vitest/MSW, `tsc --noEmit`, ESLint/Prettier изменённых файлов, UTF-8 и `git diff --check`; build не запускается.

## Выпуск и остановка

Backend и admin выпускаются обычными PR/workflow. Read-only canary проверяет `/ready`, оба `release.json`, 401 защищённого endpoint и свежие production logs. После canary агент останавливается до фактического retry документа `168`.
