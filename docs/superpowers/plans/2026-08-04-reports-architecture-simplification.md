# Управленческая отчётность: упрощение архитектуры и обязательная редакция мастер-плана

## Статус документа

Этот документ является обязательной актуальной редакцией Планов 1b, 1c и 2. При противоречии применяется этот документ. Старые подробные задачи сохраняют силу только для runtime, доменных контрактов, источников, state machines, конкурентности, хранения, тестов и UI, которые прямо не отменены ниже.

## Цель

Сохранить production-ready управленческую отчётность МОСТ и удалить отдельную release-платформу, которая усложняет разработку и эксплуатацию, но не повышает достоверность самих отчётов.

## Сохраняется

- единый каталог и runtime отчётности;
- server-owned organization/project context;
- RBAC/ABAC и повторная проверка прав в фоновых операциях;
- реальные providers, формулы, source/schema/formula versions;
- immutable snapshot и replay там, где нужна историческая воспроизводимость;
- PostgreSQL state transitions, durable outbox, Redis workers, lease/retry/recovery;
- стабильная пагинация, totals и drill-down одного snapshot;
- streaming CSV/XLSX, ограниченный PDF и S3 через `FileService`;
- аудит, telemetry, retention и cleanup;
- saved views, schedules и subscriptions с повторной авторизацией;
- unit, contract, UI и один параметризованный PostgreSQL suite;
- подробные тестовые матрицы старых планов, если они проверяют продуктовый runtime, данные, права или конкурентность.

## Отменяется и не должно создаваться заново

- отдельный GitHub Actions workflow для отчёта, кандидата или источника;
- CI admission как бизнес-условие регистрации или выполнения отчёта;
- Ed25519 signing publication/evidence artifacts;
- OIDC permissions, signing job и protected environment `report-publication-release`;
- trusted request/discovery/signing pipeline;
- backend → admin → backend artifact transfer;
- freeze SHA, activation commit, admin-evidence re-entry и release commit protocol;
- требование ignored/generated CI JSON для запуска приложения;
- release ledger, используемый как runtime authorization;
- специальные DB-роли, если они существуют только для отменённого publication protocol;
- `SECURITY DEFINER` admission-функции, не защищающие обычные продуктовые данные;
- отдельный Plan 4, если после удаления release-церемонии в нём не остаётся продуктовой проверки;
- изменение CI/CD в рамках реализации очередного отчёта.

CI может запускать стандартные тесты проекта и публиковать обычный test report. Результат CI не является DTO, токеном допуска или runtime-зависимостью продукта.

## Cleanup уже слитой инфраструктуры

Cleanup выполняется отдельным логическим блоком и отдельной feature-веткой.

1. Построить карту workflow, signing, transfer, release ledger, CLI, schemas, migrations, DB functions/roles и тестов старого publication protocol.
2. Отделить общие catalog/authorization/audit/outbox компоненты от изолированной release-инфраструктуры.
3. Удалить отдельные reporting publication/admission workflows и их OIDC/secrets.
4. Удалить signing, trusted request и artifact-transfer application code.
5. Новые отчёты регистрировать обычными встроенными определениями приложения. Для уже активированных database-defined отчётов временно сохранить только read-only registry без API, CLI и DB-точек записи.
6. Подготовить безопасные миграции удаления ненужных write functions/roles; совместимые read-side таблицы удалять только после переноса всех реально используемых определений и проверки production-данных. Локально миграции не запускать.
7. Удалить dead configuration, CLI, schemas, fixtures и тесты, обслуживающие только отменённый протокол.
8. Сохранить полезные source, formula, replay, RBAC, outbox и concurrency tests.
9. Проверить «План-факт бюджета» и каталог через стандартный runtime.

Не вводить новый универсальный activation-протокол вместо удаляемого publication-протокола без отдельного подтверждённого продуктового сценария и прямого согласования пользователя.

Удаление не должно затронуть CI/CD вне явно подтверждённых reporting-файлов и не должно менять общий deployment pipeline МОСТ.

## Server-owned context

- Организация определяется только backend из actor/auth и активного контекста.
- `organization_id`, `owner_id`, `user_id`, role, permission и произвольный scope не принимаются как доверенные поля запроса.
- Проект берётся из server-confirmed project context или из options, доступных текущему actor.
- Переданный вручную чужой или недоступный `project_id` отклоняется.
- Пустой набор разрешённых проектов означает отсутствие доступа, а не все проекты.
- URL, query string, local storage и saved view не являются источником прав.
- Контекст повторно проверяется для options, run, rows, totals, drill-down, export, retry, download, schedule и subscription delivery.
- Отзыв прав или смена tenant/project во время фоновой операции закрывает доступ.
- Cross-tenant запрос не раскрывает существование чужого run/export/snapshot.

## UI/UX Definition of Done

### Поля и options

- Все поля имеют логичные русские названия, подсказки и единицы измерения.
- Организация отображается из контекста и не вводится пользователем.
- Проект выбирается только из реальных доступных options.
- Options возвращаются backend с учётом организации, проектов, модулей и прав.
- Зависимые поля каскадны: смена проекта очищает несовместимые закрытия, версии, договоры, ЦФО, статьи и контрагентов.
- Поле недоступно до выбора обязательного родителя.
- Устаревшая option отклоняется backend при ручной подмене запроса.
- Defaults безопасны, предсказуемы и не расширяют scope.

### Состояния и действия

UI различает initial, options loading, ready to run, validation error, running, empty, ready, stale, access denied, failed и cancelled. Запуск защищён от двойного нажатия. Долгая операция показывает статус и прогресс. Retry доступен только для retryable failure.

Таблица показывает единицы, валюту, даты, сортировку и итоги. Drill-down доступен только для объявленных значений. Export использует тот же snapshot, фильтры, сортировку и scope, что видит пользователь. Интерфейс адаптивен, работает с клавиатуры и не содержит фиктивных options или демонстрационных данных.

### Обязательные негативные сценарии

1. Подмена `organization_id`.
2. Чужой или недоступный `project_id`.
3. Run/export/snapshot другой организации или проекта.
4. Подмена user/owner/role/permission/scope.
5. Устаревшее закрытие, версия или option.
6. Ручное изменение URL/query/local storage.
7. Смена организации или проекта при открытом отчёте.
8. Отзыв прав во время фоновой операции.
9. Пустой разрешённый project scope.
10. Двойной запуск, повтор worker и повторная доставка subscription.

## Definition of Ready отчёта

До implementation известны бизнес-владелец, реальные источники, grain, формулы, знаки, валюта, версии расчёта, правила закрытия/restatement, scope, redaction, filters/options/grouping, totals, drill-down, export, права, freshness и replay. Если контракт не подтверждён, отчёт остаётся недоступным без production-заглушки.

## Definition of Done отчёта

Отчёт готов только когда одновременно выполнены:

- реальный provider и проверенные формулы;
- server-owned organization/project context;
- реальные scoped options и серверная валидация;
- run, snapshot, rows, totals и drill-down;
- export/download того же snapshot;
- русскоязычный полный UI со всеми состояниями;
- негативные security/context сценарии;
- unit/contract/UI/PostgreSQL tests по риску;
- независимое review diff;
- правдивый handoff с источниками, формулами и ограничениями.

Страница, таблица или наличие runtime binding отдельно не доказывают готовность.

## Следующий порядок работ

1. Reconciliation G10 «План-факт бюджета»: сверить фактический `main` с полным Definition of Done и исправить устаревшие source/handoff документы.
2. Cleanup отменённой publication/CI-инфраструктуры отдельным блоком.
- R06 `baseline_schedule_variance` «Отклонение от базового графика»: dependency-блок перед G09 завершён; canonical backend и admin UI опубликованы на реальном базовом плане и историческом состоянии задач. Организация и проект берутся из server-owned context; отсутствие baseline остаётся неизвестным значением, завершённые задачи не считаются просроченными, а строки, итоги, детализация и CSV/XLSX относятся к одному снимку. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r06-delivery-report.md`.
- R28 `customer_sla` «SLA заказчиков»: canonical backend и единая карточка admin UI опубликованы на реальных событиях обращений, версионных политиках SLA и рабочем календаре. Период и тип обращения являются единственными пользовательскими фильтрами; организация и проект определяются server-owned context. Неизвестная сторона события не подменяется успешным SLA, строки, итоги, история и CSV/XLSX относятся к одному снимку. Backend PR #195 и исправления #196–#197 развёрнуты workflow `30978814068`; admin PR #28 развёрнут workflow `30979194411`; пользовательская инструкция — YouTrack `180-72`. Формула закреплена SHA-256 `63e1999fa8411abbdbe0ea38b5012a61e587a11ca7150bde8c3245f3ebed9319`, источник — `2647891111121878bf6fdffec2df91c1b0c6595167d421a6edeb042fe3a0aa42`.
- R26 `handover_readiness` «Готовность к передаче объекта»: canonical backend и единая карточка admin UI опубликованы на реальных версиях ворот передачи и append-only событиях доказательств. Единственный фильтр — дата среза, строго совпадающая с canonical `as_of`; организация и проект определяются server-owned context. Готовность требует полной комплектности обязательных пунктов и документов, отсутствия жёстких блокеров и успешного результата каждой попытки. Пустой проект даёт empty-состояние, детализация использует подписанный токен и source ABAC, строки, итоги и CSV/XLSX относятся к одному снимку. Backend PR #199 и исправление #200 развёрнуты workflow `30979972299`; admin PR #29 развёрнут workflow `30980432518`; пользовательская инструкция — YouTrack `180-73`. Формула закреплена SHA-256 `480796625d579f1b2887bc92674efe7dff6e178da9272f34d3da830dca9bb8c5`, источник — `abf160e4af08783cd7f1ad8c08103590efa3334ff84a60a14d66b1f5a781ed21`.
- R27 `contractor_scorecard` «Карточка подрядчика»: canonical backend и шестнадцатая карточка единого admin-каталога опубликованы на подтверждённых отзывах и результатах уже опубликованных owner-отчётов R06/R17/R23/R24. Каждый компонент рассчитывается и показывается отдельно; составной рейтинг и ранжирование подрядчиков не вводятся. Публичный UI принимает только дату среза, а организация и проект определяются server-owned context; когорта разрешается по действующей политике. Недостаточная выборка или покрытие остаются неизвестным значением. Строки, итоги, подписанный drill-down с повторной ABAC/redaction-проверкой и CSV/XLSX относятся к одному снимку. Backend PR #204 развёрнут workflow `30982610385`; admin PR #31 развёрнут workflow `30983272641`; evidence — YouTrack `180-74`. Формула закреплена SHA-256 `a965552184893020d8da3da25875a0f6fe1ee7af6c8d5c373dd062b16a6d9d18`, источник — `f2f8e82344c4f11521a0bc16cd6fc992f8ac9b4e64a7ccd482416afc478d9e7b`.
- R04 `portfolio_liquidity` «Ликвидность портфеля»: canonical backend и семнадцатая карточка единого admin-каталога опубликованы на версионированных денежных событиях, обязательствах и утверждённых начальных остатках. Организация и доступные проекты определяются server-owned context; проекты и валюты поступают из реальных scoped options, сценарии — из серверной расчётной модели. Валюты не суммируются между собой. Строки, раздельные валютные итоги, подписанный drill-down и CSV/XLSX относятся к одному снимку. Runtime backend PR #207 развёрнут workflow `30985228176`, options PR #208 — workflow `30985815135`; admin PR #32 развёрнут workflow `30986323835`; evidence — YouTrack `180-75`. Формула закреплена SHA-256 `c74d47950d55c2d8c2f701c05a1c6847b81597375de275c673135c812cbcc09b`, источник — `4b8f8e0ddd8adc898cb0c518c5c41dcfc37e6a176b6808452bbdd934a819f10b`.
- R11 `wip_completion_forecast` «Прогноз завершения НЗП»: canonical backend и восемнадцатая карточка единого admin-каталога опубликованы на неизменяемом EPM-снимке и единственной активной версии прогноза выбранного проекта. Организация, проект, actor и scope определяются только server-owned context; options возвращают фактическую активную версию и расчётный период, а отсутствие версии или снимка отображается честным недоступным состоянием. Денежные итоги разделены по валютам; строки, чувствительные значения, подписанный drill-down и CSV/XLSX относятся к одному снимку. Runtime backend PR #210 развёрнут workflow `30987572786`, options PR #211 — workflow `30988050879`; admin PR #33 развёрнут workflow `30989148187`. Formula fingerprint — `4e42f6e1dbf929763ff2c78f5db678f1bc1bf4657addff46b364fc2cba7a7ce2`, source fingerprint — `e8d8232fbbed7d8514fc46695bdb1fe3456d8d4e8910bd34c76bf55ddd2a1561`. YouTrack evidence не создан: доступная интеграция не нашла ранее использованный проект/issue namespace `180-*`; deploy evidence закреплён здесь без фиктивного идентификатора.
- Production-инцидент каталога закрыт без маскировки исключения пустым ответом: metadata теперь принимает ordinal R28, а композиционный тест проверяет metadata каждого опубликованного builtin-отчёта. Backend PR #213 развёрнут workflow `30990094726` с merge SHA `ff09eb7350ee65e47976deb8926561f1f8a2e613`; после deploy endpoint каталога возвращает стандартизированный 401 без сессии вместо 500, а read-only production-проверка не обнаружила новых `report_catalog_metadata_invalid`.
- Общий валютный контракт МОСТ закреплён enum `CurrencyCode`: устранены дублирующие списки валют в генерации смет и закупках, а R05 использует этот же тип и включает его в semantic fingerprint. Backend PR #214 развёрнут workflow `30994973983` с merge SHA `cca8e17404bb323801527c8876f0a374ec9fea8f`.
- Общий read-only блок рабочего контекста отчётов переведён на `useReportWorkingContext`: организация в приоритете определяется из `current_organization` пользователя и при его отсутствии может быть восстановлена только из согласованного project context, проект принимается только при совпадении организации, а служебное объяснение о способе определения scope удалено из интерфейса. Admin PR #34 развёрнут workflow `30992998710` с merge SHA `6a871b1a422b8db20985f9b5e2f3a3be1e92401e`.
- R05 `project_evm_control` «Контроль стоимости и сроков проекта»: canonical backend и карточка R05 опубликованы в едином admin-каталоге из 20 подтверждённых сервером отчётов; тем же релизом в общий список возвращена ранее выпавшая карточка уже опубликованного `budget_plan_fact`. R05 опирается на утверждённый неизменяемый базовый план, историческое состояние задач, выполнение, фактические затраты и единственную активную версию прогноза. BAC/PV/EV/AC, SV/CV/SPI/CPI/EAC/VAC/TCPI рассчитываются по `project_control_core.v1`; нулевой знаменатель даёт неизвестное значение, валюты не смешиваются. Организация, проект, actor и scope определяются server-owned context; реальные WBS, задачи, подрядчики, центры ответственности и валюты поступают из project-scoped options, а выбранный локальный момент времени передаётся без сдвига календарной даты. Строки, раздельные валютные итоги, чувствительные показатели, подписанный drill-down и CSV/XLSX относятся к одному снимку. Runtime backend PR #215 развёрнут workflow `30997456319`, options PR #216 — workflow `31000068846`; admin PR #35 развёрнут workflow `31002687374` с production release SHA `57a5c254e9503cd93e2da94d79dbedd110bed9ad`. Production smoke подтвердил 200 для страницы каталога и R05 route и стандартизированный 401 для catalog/options API без сессии. Formula fingerprint — `e653f7ec0c7cca52e54616d7e5e93478c8fd37d13551029eeffd2fddf5f53c44`, source fingerprint — `cdced2ac8ffbb47ec2b400141003f0a0258327f0349efd3b3ba09a6875401754`. YouTrack evidence не создан по уже зафиксированной причине недоступности project/issue namespace `180-*`; фиктивный идентификатор не использовался.
- Для R02 `holding_performance` и R03 `intercompany_contract_flows` развёрнут общий неизменяемый источниковый фундамент без преждевременной публикации отчётов. Backend PR #218 (merge SHA `4a911c7b16540804cc47eb7f09f189d52e308a13`, workflow `31007671996`) добавил версионированные checkpoints контекста холдинга, договорных измерений, иерархии организаций и распределений, point-in-time resolvers, append-only/source-capture triggers и схему фактов `holding_allocation_facts.v2`; PR #219 (merge SHA `108e97b7cb46106032c4273447e44425984451b6`, workflow `31008485977`) отдельно закрепил точную сумму фиксированного распределения, не переписывая ранее зафиксированные события. Read-only production evidence подтвердил checkpoints `contract_dimensions`, `organization_hierarchy`, `allocation_dimensions` на `2026-08-05 12:56:25.79967+00` и `allocation_amount_dimensions` на `2026-08-05 13:07:05.745213+00`; покрытие составляет 225/225 договоров, 51/51 организаций и 72/72 распределений, после корректирующего checkpoint сохранены 72 исходных и добавлены 72 новых события, все 32 активных фиксированных распределения разрешаются по неизменяемой сумме, активных неразрешимых контекстов — 0. В production присутствуют все 11 ожидаемых source/append-only triggers и четыре поля схемы v2. На момент поставки фундамента фактов v2 было 0 и искусственный backfill не выполнялся; последующая штатная материализация R03 сохранила 34 факта из реальных checkpoints. На этом этапе R02 оставался скрытым до собственного DoR/DoD и не считался доставленным на основании одного общего источника.
- R03 `intercompany_contract_flows` «Потоки по договорам холдинга»: canonical backend и двадцать первая карточка единого admin-каталога опубликованы для корпоративного контура с активным модулем нескольких организаций. Отчёт использует только фактический состав холдинга и неизменяемые договорные checkpoints; организация, actor и разрешённый holding/project scope определяются server-owned context. Пользователь может сузить расчёт только реальными server-scoped options организаций и проектов холдинга, контрагентов, видов работ, договоров и валют; подмена context-полей отклоняется. Общий объём равен сумме внутреннего, внешнего и неклассифицированного потоков, доли при нулевом итоге остаются неизвестными, разница связанных распределений не называется маржой, валюты не смешиваются. Runtime backend PR #221 развёрнут workflow `31011701491`, options PR #222 — workflow `31015730969`; admin PR #36 развёрнут workflow `31020176907` с production release SHA `9669839ac54cc71be19a187961581dacdede4062`. Read-only production evidence подтвердил 34 фактических источника без gaps, 21 опубликованный отчёт и scoped options с двумя реальными проектами, 17 договорами, двумя видами работ и RUB; все 34 текущих источника честно остаются неклассифицированными из-за отсутствия подтверждённой стороны-контрагента. Formula fingerprint — `335648ea8becc17ed3d2543deacb02a7c218c8e5546647fc9e94cd3497e57282`, source fingerprint — `428f859e7a352ad3c86d62ed2852921c6facbae38348b79807616a8955fea7a3`. Полный handoff: `.superpowers/sdd/2026-08-04-reports-architecture-simplification/r03-delivery-report.md`. YouTrack evidence не создан по уже зафиксированной причине недоступности project/issue namespace `180-*`; фиктивный идентификатор не использовался.
- Персональная видимость единого каталога синхронизирована с системными ролями: server-side report ABAC использует тот же общий набор эквивалентных permission namespaces, что основной `PermissionResolver`, при этом чужой namespace остаётся fail-closed. Для `organization_owner` добавлены точные права R05 `reports.project_control.view/export`; при 21 опубликованном определении и отключённом `multi-organization` ожидаемый каталог содержит 20 отчётов, а корпоративный R03 остаётся скрытым модульным gate. Backend PR #224, merge `dd6117f52f519d6dce81f6656ecbe87ebda31de5`, production workflow `31023116745` — success. Read-only production evidence подтвердил новую alias-реализацию и права роли на сервере; после deploy не обнаружены новые ошибки `report_catalog`, `permissions` или report scope authorization.
- R02 `holding_performance` «Эффективность холдинга»: canonical backend и двадцать вторая карточка единого admin-каталога опубликованы только для корпоративного контура с активным модулем нескольких организаций. Организация, actor, состав холдинга и разрешённые проекты определяются server-owned context; публичные фильтры лишь сужают реальные scoped options организаций и проектов холдинга, подрядчиков, статусов договоров, валют и периода. Договорный объём, принятые работы и денежный поток остаются разными основами показателя; итог холдинга равен сумме вкладов организаций отдельно по валюте и основе, неизвестные валюты не подменяются RUB. Runtime PR #227 был остановлен workflow `31037076967` до deploy из-за честно обнаруженного drift fingerprint; отдельное исправление PR #228 развёрнуто workflow `31037513435`. Read-only preview источников PR #229 развёрнут workflow `31040509965`, dedicated options PR #232 — workflow `31044996264`, точность payment checkpoint PR #233 — workflow `31047625174`; production backend HEAD `c0ce0bebeca983728af0d01667c45d89bfa8c359`. Admin PR #37 развёрнут workflow `31051616637` с production release SHA `93c6a10bb9d11ba8b56927547ce1a296fd449083`. Сервер публикует 22 определения; при отключённом `multi-organization` R02 и R03 скрыты модульным gate, поэтому ожидаемый каталог `organization_owner` содержит 20 карточек. Formula fingerprint — `59c4e2d23349656ae8948679fcea4dc59da5be20792b865afb17da2b9a667f20`, source fingerprint — `95144d292c56689d11ea6cf18c72d3abb2982a99e9a5981763bf94a61234b4ac`. Полный handoff: `.superpowers/sdd/2026-08-04-reports-architecture-simplification/r02-delivery-report.md`. YouTrack evidence не создан по уже зафиксированной причине недоступности project/issue namespace `180-*`; фиктивный идентификатор не использовался.
3. G09 «Маржинальность проекта»: canonical backend и admin UI опубликованы; server-owned context, закрытый snapshot, totals, drill-down и export доставлены через отдельные PR и успешные deploy.
4. G21 `project_labor_cost` «Стоимость труда по проектам»: canonical backend и admin UI опубликованы; используются реальный `DatabaseProjectLaborCostAdapter`, server-owned project scope, поисковые options, totals, sensitive-cost permission, signed drill-down и export.
5. R22 `payroll_readiness` «Готовность к расчёту зарплаты»: canonical backend и admin UI опубликованы на существующих версионированных payroll calculation sources и `DatabasePayrollReadinessAdapter`; доставлены project-scoped options, blockers/readiness totals, audit drill-down и export.
6. R19 `workforce_capacity` «Обеспеченность персоналом»: canonical backend и admin UI опубликованы на существующих temporal owner facts и immutable workforce snapshots; организация берётся из auth-контекста, проекты — только из server-authorized options, ставки скрываются без sensitive-права.
7. R15 `procurement_cycle` «Цикл закупки»: canonical backend и admin UI опубликованы на реальных process events и policy versions; организация и проект берутся из server-owned context, доставлены snapshot, итоги, audit drill-down и export. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r15-delivery-report.md`.
8. R16 `supplier_award_competitiveness` «Конкурентность выбора поставщика»: canonical backend и admin UI опубликованы на версионированных решениях и сопоставимых версиях предложений; организация и проект берутся из server-owned context, пользователь задаёт только строгий период, доставлены snapshot, итоги по валютам, sensitive drill-down и export. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r16-delivery-report.md`.
9. R17 `supply_reliability` «Надёжность поставок»: canonical backend и admin UI опубликованы на версиях обещаний заказа и событиях жизненного цикла поставки; OTIF учитывает срок, полный объём, стоимость, возвраты и реверсы. Каталог admin показывает только реально доступные серверу отчёты и не содержит заглушек или деления на базовые/расширенные. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r17-delivery-report.md`.
10. R18 `inventory_risk` «Риск запасов»: canonical backend и admin UI опубликованы на реальных складских событиях, дневных остатках, снимках спроса и версиях политики пополнения. Организация и проект берутся из server-owned context; сервер формирует итоги по состояниям и валютам, строки, доказательства и экспорт одного снимка. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r18-delivery-report.md`.
11. R20 `attendance_execution` «Исполнение рабочего времени»: canonical backend и admin UI опубликованы на действующих назначениях, рабочих графиках, подтверждённых отметках, согласованных отсутствиях и корректировках. Организация и проект берутся из server-owned context; audit evidence скрывается без отдельного права. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r20-delivery-report.md`.
12. R23 `quality_defect_flow` «Поток дефектов»: canonical backend и admin UI опубликованы на неизменяемой истории событий дефектов контроля качества, версиях правил и подтверждениях закрытия. Организация и проект берутся из server-owned context; сервер проверяет балансовую формулу, зрелость выборки, полноту истории, права на просмотр и экспорт. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r23-delivery-report.md`.
13. R24 `safety_incident_actions` «Инциденты и корректирующие действия»: canonical backend и admin UI опубликованы на версионированных переходах инцидентов, нарушений и корректирующих действий, дневной экспозиции и версиях политики безопасности. Организация и проект берутся из server-owned context; неполная экспозиция не подменяется нулевой частотой, а строки, детализация и экспорт относятся к одному снимку. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r24-delivery-report.md`.
14. R25 `workforce_admission` «Допуск персонала»: canonical backend и admin UI опубликованы на версионированных назначениях, правилах допуска и подтверждениях соответствия. Организация и проект берутся из server-owned context; медицинские сведения скрываются без отдельного права, а строки, детализация и экспорт относятся к одному снимку. Фактический handoff зафиксирован в `.superpowers/sdd/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates/r25-delivery-report.md`.
15. Остальные отчёты — по одному, в порядке реальной готовности источников. Перед каждой реализацией выполняется Definition of Ready audit; порядковый номер в каталоге сам по себе не является основанием публиковать отчёт.

Для каждого блока создаются отдельные backend/admin feature-ветки от актуальных `main`; выполняются минимальные релевантные проверки и одно независимое review.
