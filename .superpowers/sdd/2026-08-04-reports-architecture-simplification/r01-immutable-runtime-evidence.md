# R01 — «Здоровье портфеля проектов»: evidence immutable runtime

## Итог блока

В production поставлен отдельный backend runtime R01 `project_portfolio_health`, который материализует отчёт только из доказанного неизменяемого tuple. Отчёт не опубликован в каталоге, options и admin UI в этот релиз не входили. Количество доказанно опубликованных отчётов остаётся 24/28.

Backend PR #270, merge SHA `2b0589e626a8bd2c6ba30c22a55d3975374d0492`, основной production workflow `31139578212` — success.

## Реализованный runtime contract

- `ProjectPortfolioHealthProvider` использует dedicated immutable projection service и больше не вызывает mutable health-materialization.
- Owner snapshots `project_margin`, `budget_plan_fact` и `wip_completion_forecast`, а также выбранный liquidity calendar читаются через существующий `StableReportingSourceView` в одной `REPEATABLE READ` границе.
- Перед расчётом повторно проверяются organization, report kind, snapshot ID, source hash, `as_of`, formula/schema version, complete coverage и фактическое количество строк.
- Пустой server-owned project scope всегда даёт `REPORT_SCOPE_FORBIDDEN`; клиентский project filter может только сузить непустой разрешённый scope.
- Деньги агрегируются в целых minor units с проверкой переполнения. Строки разделены по `project × currency`; общий enum `CurrencyCode` отклоняет неизвестные валюты, phantom default-currency row не создаётся.
- Для WIP выполняется точный инвариант `FTC = EAC - AC`; план/факт сохраняет худший риск, отрицательная forecast margin не теряется.
- Реальная persisted shape с пустым `source_refs` принимается как валидный пустой список: фиктивные domain refs не создаются. Exact snapshot provenance остаётся в report-level source refs/hash/watermarks, а row DTO добавляет только честную ссылку на проект.
- `BudgetingPortfolioProjectionService` сохранён байт-в-байт, поэтому опубликованный R04 `portfolio_liquidity` не получил ложного drift своего source fingerprint.

## Архитектурная граница

Изменения ограничены backend runtime и тестами R01. Не добавлены миграции, таблицы, индексы, config, feature flags, очереди, фоновые процессы, новые workflow или общая платформенная абстракция. Существующие snapshot storage, provider registry, transaction boundary и projection persistence переиспользованы без изменения инфраструктуры.

## Проверки и review

- `php -l`: 12/12 затронутых PHP-файлов.
- Assertions-enabled harness: 5/5 — owner candidate selector, owner source policy, readiness measurement, source tuple и immutable owner payload.
- `git diff --check` — success.
- Проверка запрещённых путей: 0 изменений в `database/`, `config/`, `routes/`, `deploy/`, `.github/workflows/`.
- Проверка R04 fingerprint input: `BudgetingPortfolioProjectionService` неизменён, blob `fd4958a4278d3682259d481e11fb7de8d38d6873`.
- Независимое review обнаружило и помогло закрыть два дефекта до merge: позднюю проверку пустого server project scope и несовместимость с реальной persisted shape пустых row refs. Повторное review актуального diff — CLEAN.
- PHPUnit и PHPStan локально не запускались: в worktree отсутствовал `vendor`. Основной production workflow завершил build/push и deploy exact image digest со статусом success.

## Production evidence

- Workflow `31139578212` развернул exact merge SHA `2b0589e626a8bd2c6ba30c22a55d3975374d0492`; шаги `Build and push immutable image` и `Deploy exact image digest` завершились success.
- Read-only SSH подтвердил совпадение локальных и production SHA-256 для `ProjectPortfolioHealthImmutableProjectionService`, `ProjectPortfolioHealthImmutableSource`, `ProjectPortfolioHealthImmutableOwnerPayloadBuilder`, `ProjectPortfolioHealthProvider` и неизменённого `BudgetingPortfolioProjectionService`.
- В последних 5000 строках production `laravel.log` после deploy: 0 совпадений по `ProjectPortfolioHealthImmutable`, `project_portfolio_health_owner_` и `project_portfolio_health_source_selection_invalid`.
- Catalog API без сессии после redirect отвечает стандартизированным 401, а R01 не раскрывается преждевременно.

## Честная граница следующего этапа

Этот блок доказывает backend runtime, но не статус delivered. До публикации R01 остаются отдельные мини-релизы реальных server-scoped options, publication/binding contract, единого admin UI и финального DoD/evidence. До завершения этих блоков R01 остаётся скрытым, а опубликованный итог — 24/28.

Workflow sync в YouTrack не выполнялся: скрытый runtime не меняет доступный пользователю сценарий. Ранее зафиксированная недоступность project/issue namespace не обходится выдуманным ID.
