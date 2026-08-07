# R01 — «Здоровье портфеля проектов»: evidence фундамента готовности

## Итог блока

В production поставлен только fail-closed фундамент готовности R01 `project_portfolio_health`. Отчёт не опубликован, его карточка не добавлена в каталог, а защитный вызов `assertImmutableHealthCoverage()` не снят. Количество доказанно опубликованных отчётов остаётся 24/28.

Backend PR #268, merge SHA `48b0cc7267284fb5e2f2b49cb8d3e738df256d53`, основной production workflow `31136817366` — success.

## Зафиксированный source contract

- Критический tuple состоит из неизменяемых owner-снимков `project_margin`, `budget_plan_fact`, `wip_completion_forecast` и доказательства `portfolio_liquidity` на одном `as_of`.
- Организация, проекты и scope берутся из `ReportExecutionContext`; клиентские значения не используются как доверенный tenant context.
- Для owner-снимков проверяются точные definition/query identity, формула, source schema, период, свежесть, полнота строк, parent EPM snapshot, source hashes, версии и закрытие периода.
- Ответственность центра разрешается в UUID только внутри текущей организации. Валюты проверяются через общий enum `CurrencyCode` и не смешиваются.
- Несовместимая когорта, неоднозначный exact snapshot, отсутствующий parent, неполное покрытие или повреждение exact storage identity переводят readiness в gap; foreign storage scope не блокирует корректный scope.
- Проверка вариантов одного query использует существующий unique-index prefix `organization_id + report_code + scope_hash + query_hash`; миграций и новых индексов не добавлено.
- Eligible/projected/gap/unknown, input hash, output hash и watermark формируются детерминированно и не зависят от порядка чтения.

## Проверки и review

- `php -l`: 21/21 изменённых PHP-файлов.
- Автономные harness: 4/4 — owner candidate selector, owner source policy, readiness measurement и source tuple.
- `git diff --check` — success.
- Независимое review после исправления точной query identity, route-injected project scope, tenant-scoped responsibility centers, damaged exact candidates и production-size lookup — CLEAN.
- PHPUnit и Larastan локально не запускались: в worktree отсутствовал `vendor`. Основной production workflow полностью завершился success.

## Production evidence

- Production HEAD через read-only SSH: `48b0cc7267284fb5e2f2b49cb8d3e738df256d53`.
- На сервере присутствуют `ProjectPortfolioHealthReadinessProbe` и `ProjectPortfolioHealthOwnerCandidateSelector`.
- Последняя доступная запись `laravel.log` имела время `2026-08-07 01:00:19 UTC`, раньше merge и deploy этого блока. В проверенном окне не было `project_portfolio_health`, `owner_source_*` или `report_catalog_metadata_invalid`, но оно не является post-deploy log evidence; отсутствие ошибок новой версии по логу пока не доказано.
- Catalog API без сессии отвечает стандартизированным 401 и не раскрывает R01 преждевременно.

## Честная граница следующего этапа

До статуса delivered для R01 остаются отдельные мини-релизы runtime с заменой mutable materialization на доказанный immutable tuple, реальные scoped options, единый admin UI, снятие catalog gate только после DoD и финальное production evidence. Этот фундамент сам по себе не является публикацией R01.

Workflow sync в YouTrack не выполнялся: блок не меняет доступный пользователю сценарий и намеренно не публикует отчёт.
