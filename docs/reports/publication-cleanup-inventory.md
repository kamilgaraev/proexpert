# Cleanup publication-протокола отчётности: инвентаризация

## Граница блока

Документ фиксирует фактическую границу cleanup после архитектурной редакции 2026-08-04. Общий runtime управленческой отчётности МОСТ, реальные providers, registry определений, server-owned context, RBAC/ABAC, snapshot/replay, export, audit, concurrency и retention сохраняются.

Удаляется только отдельная release-платформа, в которой CI формировал, передавал и подписывал артефакты допуска, а runtime доверял этому артефакту как условию публикации.

## Фактический объём

| Группа | Файлов | Строк | Решение |
| --- | ---: | ---: | --- |
| Publication application/infrastructure/DTO/contracts | 63 | 5 152 | Разделить: обычный registry оставить, signing/admission/transfer удалить |
| Publication tests | 33 | 7 273 | Сохранить продуктовые registry/outbox/concurrency проверки; удалить тесты release-церемонии |
| CLI и tooling | 5 | 548 | Удалить issuer, protected admission и artifact transfer |
| JSON schemas | 6 | 393 | Удалить signing/ledger/transfer/release schemas |
| Смешанный workflow `notification-concurrency.yml` | 1 | 731 | Удалён после отдельного разрешения пользователя |

Первичная инвентаризация не учитывала отдельный контур `PlanOneA/B/C Evidence`. Сверка зависимостей подтвердила, что он повторно упаковывал результаты прямых тестов в lock-файлы, SHA-связанные JSON-артефакты и схемы, не участвовал в runtime и также удалён полностью.

## Сохранить

- `BuiltinPublishedReportDefinitionRegistry` и встроенную публикацию G10 без зависимости от БД или CI admission.
- `ReportDefinitionRegistry`, catalog metadata и scheduling capability registries.
- `DatabasePublishedReportDefinitionRegistry` как строго read-only совместимость для уже активированных продуктовых отчётов.
- Source/formula/conformance проверки, которые доказывают корректность данных отчёта, а не происхождение CI-артефакта.

## Удалить

- Ed25519 signer/verifier и release artifact issuer.
- Trusted roots, release request discovery/resolver/registry и bundle ingestion.
- Admission profiles/requirements, eligibility через подписанный proof и delivery-contract hashes.
- Filesystem publication ledger и promotion протокол с activation/freeze/re-entry SHA.
- Backend-to-admin artifact transfer и cleanup evidence release-церемонии.
- Команду `reports:publications:ingest-release` и обслуживающие её scripts/schemas/fixtures/tests.
- G10/R15 CI candidate builders и adapters, если они существуют только для admission, а не для source/formula conformance.
- Специальные DB-функции записи через безопасную forward migration; локально миграцию не запускать.
- `PlanOneA/B/C Evidence`: генераторы, валидаторы, lock-файлы, SHA-реестры, schemas, fixtures и тесты самого evidence-конвейера.

## Подтверждённые связи

- G10 уже доступен через `BudgetPlanFactBuiltinPublishedReport` и не требует database publication.
- `ProductionReportPublicationReleaseIngestion`, feature gate и большая часть signing-контура имеют только test/tooling callers.
- `EloquentReportPublicationRegistry` зарегистрирован в `ReportingCatalogServiceProvider` и используется database read-side, поэтому механическое удаление всей папки `Publication` сломает каталог.
- Совместимость с прежними строками `report_publications` не авторизует публикацию и ничего не записывает. Её граница — только чтение. Она удаляется после переноса всех реально используемых database-defined кодов во встроенный registry и отдельного подтверждения, что production больше не содержит необходимых строк старого формата.
- Reporting-specific OIDC/signing/artifact jobs удалены вместе с остальными дополнительными workflow. В репозитории оставлен только основной `deploy-backend.yml`; его production-запуск после консолидации завершился успешно.

## Реализованная последовательность

1. G10 переведён на встроенное определение и не зависит от publication-протокола.
2. Database registry ограничен read-only совместимостью для ранее активированных определений.
3. Production DI, CLI ingestion и все пути записи удалены.
4. Signing/request/bundle/transfer и `PlanOneA/B/C Evidence` удалены вместе с обслуживающими tests/tooling/schemas.
5. Добавлена forward migration удаления четырёх осиротевших DB-функций записи; локально она не выполнялась.
6. Дополнительные workflow удалены, основной `deploy-backend.yml` сохранён без изменения и уже прошёл production deploy.
7. Каталог, встроенный G10 и read-only compatibility registry проверяются прямыми целевыми тестами.
