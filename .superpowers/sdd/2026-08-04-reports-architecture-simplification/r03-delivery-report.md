# R03 delivery report: «Потоки по договорам холдинга»

Дата: 2026-08-05.

## Результат

Отчёт `intercompany_contract_flows` опубликован как единый canonical report МОСТ только для корпоративного контура с активным модулем нескольких организаций. Он использует реальный состав холдинга, версионированные договорные измерения и неизменяемые checkpoints распределений. Текущая организация, actor, состав холдинга и разрешённые проекты определяются только серверным auth/context.

Формула `intercompany_contract_flow.v1` разделяет договорный объём на внутренний, внешний и неклассифицированный потоки. Общий объём равен сумме трёх классов; доли рассчитываются от общего объёма и при нулевом знаменателе остаются неизвестными. Разница связанных распределений показывается как контрольная разница, а не как экономическая маржа. Денежные значения и итоги всегда разделены по валютам.

## Поставка

- Источниковый фундамент: backend PR #218, merge `4a911c7b16540804cc47eb7f09f189d52e308a13`, production workflow `31007671996` — success; уточнение фиксированных сумм PR #219, merge `108e97b7cb46106032c4273447e44425984451b6`, workflow `31008485977` — success.
- Backend runtime: PR #221, merge `641e6b276c32127fd5b54e354783324524ba0cc4`, production workflow `31011701491` — success.
- Backend options: PR #222, merge `ba97955d3bcbe72378ee5ad8f7b676e1a04aa37c`, production workflow `31015730969` — success.
- Admin: PR #36, merge `9669839ac54cc71be19a187961581dacdede4062`, production workflow `31020176907` — success.
- Knowledge Base: YouTrack evidence не создан, потому что доступная интеграция не находит ранее использованный project/issue namespace `180-*`; фиктивный идентификатор не использовался.

## Контракт доверия

- Formula fingerprint: `335648ea8becc17ed3d2543deacb02a7c218c8e5546647fc9e94cd3497e57282`.
- Source fingerprint: `428f859e7a352ad3c86d62ed2852921c6facbae38348b79807616a8955fea7a3`.
- Grain: распределение договора, контрагент, проект, календарный месяц и валюта.
- Публичные фильтры: `organization_ids`, `project_ids`, `counterparty_ids`, `work_type_categories`, `contract_ids`, `currencies`, `period_from`, `period_to`. Множественные ID являются только сужением реальных options внутри уже вычисленного сервером holding scope и не заменяют текущую организацию.
- Dedicated options endpoint принимает только `as_of`; `organization_id`, `current_organization_id`, `holding_organization_ids`, `organization_ids`, `project_id`, `current_project_id`, `project_ids`, `user_id`, `actor_id`, `scope`, `permission` и `permissions` в нём запрещены. Во входе run множественные `organization_ids` и `project_ids` допустимы только внутри `filters` как сужение уже вычисленного server-owned scope.
- Просмотр и детализация требуют `multi-organization.reports.financial`, экспорт — `multi-organization.reports.export`; сам экран дополнительно закрыт модулем `multi-organization`.
- Чужие `organization_ids` и `project_ids` отклоняются стандартизированной scope-ошибкой. Остальные предметные фильтры не расширяют server-owned scope: значения вне доступных данных дают пустую выборку и не открывают чужие записи.
- Runtime материализует факты только из точных checkpoint sources и сохраняет их перед чтением снимка; искусственный backfill и фиктивные факты не используются.
- Один immutable snapshot используется для строк, раздельных валютных итогов, подписанного drill-down и CSV/XLSX.
- Общий валютный enum `CurrencyCode` участвует в semantic fingerprint; неизвестная или смешанная валюта не подменяется RUB.

## Production evidence

- Read-only source audit на момент `2026-08-05T14:39:30+03:00` подтвердил 34 подходящих checkpoint source, 0 gaps и начало покрытия `2026-08-05T13:07:05.745213Z`.
- Серверный scope текущей организации 14 разрешил holding organizations `[14, 29]` и проекты `[7, 50]`; попытка использовать чужой проект была отклонена.
- Все 34 текущих источника имеют валюту RUB и честно классифицированы как `unclassified`: подтверждённая сторона-контрагент отсутствует, поэтому данные не были ошибочно отнесены к внешнему потоку.
- Scoped options вернули `available=true`: 1 организацию с фактическими данными, 2 проекта, 0 подтверждённых контрагентов, 2 вида работ, 17 договоров и 1 валюту. Названия организаций, проектов, видов работ и договоров получены из реальных справочников.
- Серверный каталог содержит 21 опубликованное определение; R03 доступен только через dedicated run/options routes, generic run route для него запрещён.
- После backend deploy в production-логах не обнаружены новые ошибки R03, каталога или источников.
- Admin `/release.json` вернул точный SHA `9669839ac54cc71be19a187961581dacdede4062`; активный production bundle содержит route `intercompany-contract-flows`, report code `intercompany_contract_flows` и русские тексты текущей организации, unavailable-состояния, детализации и открытия договора.

## UI/UX

- Текущая организация отображается read-only через общий `useReportWorkingContext`; поле ручного ввода организации отсутствует, проект рабочего пространства для этого holding-scoped отчёта не используется как источник прав.
- Организации и проекты холдинга, контрагенты, виды работ, договоры и валюты поступают только из dedicated server-scoped options.
- Реализованы точный момент среза, период покрытия, loading, validation, empty, running/progress, failed, cancelled, expired, stale, partial и unavailable состояния.
- Таблица показывает бизнес-названия проектов и контрагентов, календарный месяц, валюту, три класса потока, их доли и контрольную разницу связанных распределений.
- Итоги формируются отдельной карточкой для каждой валюты; фиктивного fallback на RUB нет.
- В интерфейсе и детализации не показываются `row_key`, `snapshot_row_key`, `source_type`, `column_id`, внутренние availability-коды и технические labels resource links.
- Export исключает `row_key` и drill token и использует тот же snapshot и сортировку по периоду, что таблица.
- Каталог и route server-gated: неопубликованный отчёт не отображается и не открывается локальным реестром.

## Проверки

- Backend runtime/options: локальные `php -l` изменённых PHP-файлов и `git diff --check`; runtime formula/source fingerprints после options-релиза не изменились. PHPUnit/Larastan локально не запускались, потому что в workspace отсутствовал `vendor`; оба production workflow завершились успешно.
- Admin: `npx tsc --noEmit`, ESLint 12 изменённых файлов, 22 целевых Vitest-теста — UI 5, presentation 4, catalog 3, service 10; `git diff --check`.
- Независимое review staged admin diff: CLEAN, значимых замечаний нет.
- Локальный frontend build не запускался согласно ограничениям проекта; production workflow успешно выполнил install, Reverb verification, build, release attestation, asset verification, copy и activation.
