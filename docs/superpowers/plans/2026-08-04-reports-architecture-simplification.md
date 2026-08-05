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
