# R02 delivery report: «Эффективность холдинга»

Дата: 2026-08-06.

## Результат

Отчёт `holding_performance` опубликован как единый canonical report МОСТ только для корпоративного контура с активным модулем нескольких организаций. Текущая организация, actor, состав холдинга и разрешённые проекты определяются только серверным auth/context; организация и проект не принимаются из клиентских context-полей.

Формула `holding_performance.v1` сравнивает вклад организаций и проектов отдельно по календарному периоду, валюте и основе показателя. Договорный объём, принятые работы и денежный поток не заменяют друг друга. Итог холдинга равен сумме вкладов организаций для одной валюты и одной основы расчёта; значения разных валют не складываются и не пересчитываются по условному курсу.

## Поставка

- Общий неизменяемый источниковый фундамент: backend PR #218, merge `4a911c7b16540804cc47eb7f09f189d52e308a13`, production workflow `31007671996` — success; уточнение фиксированных сумм PR #219, merge `108e97b7cb46106032c4273447e44425984451b6`, workflow `31008485977` — success.
- Backend runtime: PR #227, merge `cfa8c9d5fc5e4f512f1e787d79d9191f2cf68cbf`. Первый workflow `31037076967` завершился до deploy ошибкой runtime fingerprint gate; отдельный PR #228, merge `20a6fc69f55bbbe7e3c7aa8163a67ef09e008bca`, исправил только точный fingerprint и успешно развёрнут workflow `31037513435`.
- Read-only preview событий для options: PR #229, merge `2209ff9ed731de5ceadb13764919081454b3a857`, production workflow `31040509965` — success.
- Backend options: PR #232, merge `ddb4375239236c799d9c7b657f4b6041ddff494d`, production workflow `31044996264` — success.
- Точность payment checkpoint: PR #233, merge `c0ce0bebeca983728af0d01667c45d89bfa8c359`, production workflow `31047625174` — success.
- Admin: PR #37, merge `93c6a10bb9d11ba8b56927547ce1a296fd449083`, production workflow `31051616637` — success.
- Knowledge Base: YouTrack evidence не создан, потому что доступная интеграция не находит ранее использованный project/issue namespace `180-*`; фиктивный идентификатор не использовался.

## Контракт доверия

- Formula fingerprint: `59c4e2d23349656ae8948679fcea4dc59da5be20792b865afb17da2b9a667f20`.
- Source fingerprint: `95144d292c56689d11ea6cf18c72d3abb2982a99e9a5981763bf94a61234b4ac`.
- Source schema: `holding_allocation_facts.v2`.
- Grain: организация холдинга, проект, календарный период, валюта и основа показателя.
- Публичные фильтры: `organization_ids`, `project_ids`, `contractor_ids`, `contract_statuses`, `currencies`, `period_from`, `period_to`. Множественные ID допустимы только внутри `filters` и лишь сужают server-owned holding/project scope.
- Dedicated options endpoint принимает точный `as_of` и необязательные границы периода. Клиентские `organization_id`, `current_organization_id`, `project_id`, `current_project_id`, `user_id`, `actor_id`, `scope`, `permission` и `permissions` запрещены.
- Просмотр и детализация требуют `multi-organization.reports.kpi`, экспорт — `multi-organization.reports.export`; каталог и экран дополнительно закрыты модулем `multi-organization`.
- Договорная основа строится из неизменяемого opening checkpoint, принятые работы — из версионированной истории актов, денежный поток — из append-only истории payment transactions. Текущая mutable-строка не подменяет состояние на момент снимка.
- Payment checkpoint хранит `timestamptz(6)` и сравнивается по строковому timestamp с микросекундами. Начальная фиксация атомарно дописывает реальные источники и fail-closed отклоняет несовпадение ожидаемого и записанного количества.
- Runtime проверяет persisted projection coverage отдельно для актов и оплат; missing, inactive, reversed и принадлежащие другому холдингу версии не превращаются в вклад текущего снимка.
- Общий enum `CurrencyCode` входит в semantic fingerprint. Неизвестная валюта исключается из денежных итогов и не подменяется RUB.
- Один immutable snapshot используется для строк, раздельных валютных итогов, подписанного drill-down и CSV/XLSX.

## Production evidence

- Production backend checkout после последнего R02 deploy имеет точный HEAD `c0ce0bebeca983728af0d01667c45d89bfa8c359`.
- Safe migration workflow PR #233 применил одну миграцию успешно. Read-only проверка подтвердила сохранение микросекунд payment checkpoint, непустой checkpoint и равенство числа зафиксированных событий фактическим eligible payment sources; расхождений runtime coverage не обнаружено.
- Сразу после поставки options честно возвращают предметное недоступное состояние: покрытие началось внутри текущего локального дня, поэтому первый полностью закрытый период появится не раньше 2026-08-07 по московскому времени. Пустой или фиктивный набор options не подставляется.
- Без сессии `/api/v1/admin/reports/catalog` и `/api/v1/admin/reports/holding-performance/options` возвращают стандартизированный 401, а не 404 или 500.
- В актуальном production-логе после R02 deploy не обнаружены новые `holding_performance`, `holding_payment`, `report_source` или `report_catalog` ошибки. Единственная найденная `report_catalog_metadata_invalid` относится к закрытому инциденту 2026-08-05 08:36:54, до исправления PR #213.
- Admin `/release.json` вернул точный SHA `93c6a10bb9d11ba8b56927547ce1a296fd449083`; активный production asset отвечает 200 и содержит route `holding-performance`, dedicated options contract, report code `holding_performance` и formula version `holding_performance.v1`.
- Авторизованная визуальная сессия организации 38 в доступной browser-среде отсутствовала, поэтому персональный список карточек не объявляется просмотренным. Серверный контракт публикует 22 определения; при отключённом `multi-organization` R02 и R03 скрыты модульным gate, следовательно ожидаемый каталог `organization_owner` содержит 20 карточек. Ранее наблюдавшиеся 12 карточек не являются нормальным результатом этого контракта.

## UI/UX

- Текущая организация показывается read-only через общий `useReportWorkingContext`; ручного ввода `organization_id` нет, рабочий project context не используется как источник holding scope.
- Организации и проекты холдинга, подрядчики, статусы договоров и валюты поступают только из dedicated server-scoped options.
- Реализованы точный момент среза, период покрытия, loading, validation, empty, running/progress, failed, cancelled, expired, stale, partial и unavailable состояния.
- Таблица показывает бизнес-названия организаций и проектов, месяц, валюту, основу показателя, договорный объём, принятые работы и денежный поток.
- Итоги формируются отдельной карточкой для каждой валюты; фиктивного fallback на RUB нет.
- В интерфейсе и детализации не показываются `row_key`, `source_type`, `column_id`, внутренние availability-коды и технические labels resource links.
- Конкурирующие запросы защищены раздельными generation sequence: изменение любого контекста или фильтра инвалидирует ожидающий и готовый снимок, запоздавший drill-down не перезаписывает последнюю выбранную строку и не открывает закрытый диалог.
- Export исключает `row_key` и drill token и использует тот же snapshot и сортировку по периоду, что таблица.
- Каталог и route server-gated: неопубликованный или недоступный модулю отчёт не отображается и не открывается локальным реестром.

## Проверки

- Backend runtime/options/checkpoint: `php -l` затронутых PHP-файлов, точные formula/source fingerprints, `git diff --check` и независимые review — CLEAN. Локальные PHPUnit/Larastan не запускались, потому что в workspace отсутствовал `vendor`; соответствующие production workflows завершились, а runtime fingerprint gate отдельно доказал fail-closed поведение на первой попытке PR #227.
- Admin: `npx tsc --noEmit`, ESLint 12 изменённых файлов, 25 целевых Vitest-тестов — UI 7, presentation 4, catalog 3, service 11; `git diff --check`.
- Повторное независимое review admin diff после исправления гонок: CLEAN, findings отсутствуют.
- Локальный frontend build не запускался согласно ограничениям проекта; production workflow успешно выполнил install, Reverb verification, build, release attestation, asset verification, copy и activation.
