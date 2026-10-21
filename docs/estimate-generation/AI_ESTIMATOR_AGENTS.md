# ИИ-сметчик МОСТ: зоны ответственности агентов

Контекст: [аудит](AI_ESTIMATOR_AUDIT.md), [универсальность](AI_ESTIMATOR_UNIVERSALITY.md), [приоритеты и приёмка R01–R13](AI_ESTIMATOR_ROADMAP.md). Baseline 21.10.2026: backend `proexpert` / `c492b1ab1`, frontend `prohelper_admin` / `09bf5477`. Перед следующей задачей сверить актуальный main; не считать исторический baseline текущим production. Имена каталогов не являются названиями продукта.

Эта сессия создала документацию и провела безопасные проверки. Ниже задания будущим чатам; они **не означают, что реализация началась**. Два основных исполнителя используют текущие engine/ProjectModel/Learning. Общие изменения координирует главный агент.

## Главный агент: единственный координатор общих границ

Главный агент отвечает за смысл предметных терминов, authoritative источники, shared DTO/API/schema и acceptance oracle. Он определяет один контракт, назначает единственного автора общих файлов и согласует изменение с обоими consumers до кода. Не даёт A и B параллельно менять одну schema или считать разные поля источником истины.

Общие границы, требующие координации:

| Граница | Общий смысл | Кто реализует после согласования |
| --- | --- | --- |
| Entity/Fact/Evidence/Decision/ProjectModelSnapshot | Идентичность сооружения/системы/элемента, org/project/session, source revision/hash, тип связи, неизвестность и подтверждение | A; B адаптирует числовые consumers |
| Work intent / package / selected scope | Stable work/entity/package IDs, состав/исключения/зависимости, specification/technology, evidence, completeness | A; B заполняет проверяемые quantity/norm/resource/price результаты |
| QuantityData / DerivedQuantity / proof | Decimal value и unit, formula/version, operands/evidence/decisions/current fingerprint, unresolved reason | B; A передаёт актуальные facts и показывает вопросы |
| Normative/price decision | Dataset/version/section/unit/applicability, ресурсы/коэффициенты, регион/период/источник и правила округления | B; A использует outcome, не меняет пороги и деньги |
| Trust / reuse | Candidate, user-confirmed, source-supported, independently-reviewed, rejected; условия, версия и отзыв. Статус решения не равен вероятности правильности | A; B предоставляет проверки единиц/арифметики/норм. Публичный shape утверждает главный |
| Input fingerprint / invalidation | Какой вход делает артефакт/решение устаревшим, какая dependency closure пересчитывается | A и B проектируют, главный назначает одного автора shared boundary |
| HTTP snapshot/evaluation/receipts и frontend types | Версии, nullable unknown, errors, allowed actions, идемпотентность, scope и смысл экспорта | Главный или назначенный им один автор, второй агент только consumer/reviewer |

Особенно конфликтные файлы: `EstimateGenerationServiceProvider.php`, `routes.php`, `Pipeline/Stages/PlanWorkItemsStage.php`, `BuildDraftStage.php`, `Domain/ProjectModel/*` DTO, `Domain/Evaluation/*` общие DTO, frontend `api/estimateGenerationApi.ts`, shared normalizers/types, `EstimateGenerationWorkspacePage.tsx`, `EvaluationResultPanel.tsx`. Не назначать одну и ту же единицу двум исполнителям. Каждый пишет список точных затрагиваемых файлов перед реализацией; главный интегрирует изменения по очереди.

## Агент A — универсальное ядро

**Ответственность:** модель сложного проекта, состав работ и технологические зависимости, unknown/вопросы/локальные решения, независимая проверка применимости через существующие источники и результаты B, Learning/Estimate Memory, scope комплексов, контекст изменений и частичный пересчёт. A определяет, *что требуется и что ещё неизвестно*; расчёт B даёт проверяемые числа и основания.

Преимущественные backend-модули внутри `app/BusinessModules/Addons/EstimateGeneration`:

- `Domain/ProjectModel` и `Application/Understanding`, `Application/Planning`, `Pipeline/CanonicalProjectModelPipelineProjection`; формы общих DTO — только через главного.
- Work composition: `Services/PackagePlannerService`, `Planning/WorkPlanCompiler`, `Application/Planning/ProjectPlanningCoordinator`, `Planning/TechnologyRecommendation*`, `Services/EstimateDecompositionService`, Composer и `Questions`.
- `Application/Corrections`, `Application/Generation/RebuildGeneratedSection`, `Application/TargetedRebuild`, evidence invalidation и decision orchestration.
- `Services/Learning`, learning source в AIAssistant, интеграционный extractor `app/Integrations/EstimateGeneration/EstimateLearningExampleExtractor.php`, `Evaluation`/Benchmark lifecycle. Retrieval/reuse остаются org-scoped.
- Документный ingestion/публикация facts, когда pilot требует расширения входа; числовая интерпретация/геометрические проверки — совместно по контракту B.

Frontend-область A: setup/documents/questions/prerequisites, объяснения unknown/trust/change scope, recovery их операций. Изменения общего Workspace/API/types — только назначенным главным автором. Backend остаётся источником правил, прав и allowed actions.

Практический порядок: **R02 → R05 → R06 → R07 → R09**; R04 baseline готовить сразу с главным и B. R11/R13 выполнять только по подтверждённой проблеме/пилоту. R02 не должен ждать проектирования всей иерархии.

Зависимости от B: vocabulary допустимых форм/единиц, typed results проверок, нормативные/ценовые gates, правила current quantity proof и цифры oracle. A не заменяет отсутствующую формулу предположительным числом и не повышает инженерное доверие по ответу LLM. Противоречивое количество становится конкретным вопросом пользователю.

Готовность результатов A:

- Незнакомый объект не получает house автоматически, известные элементы из его документов продолжают работать.
- Несколько сооружений/общие сети различимы и не дублируются; stable IDs и scope выдерживают repeat/change.
- Работа/технология объяснимо связана с evidence/решением/условиями; неизвестность сохраняется явно, без ложной completeness.
- User-confirmed ответ полезен локально, имеет ограничения и не публикуется универсальным правилом. Reuse требует current source/unit/context/normative version и изоляции организаций.
- Изменения не принимают stale proof и не теряют незатронутые решения; частичность подтверждается trace исполнения.
- Пройдены проверки конкретной R-задачи, а не только in-memory lifecycle. Чётко перечислены неисполненные runtime/product проверки.

### Готовое первое задание чату A

> Работай над R02 из AI_ESTIMATOR_ROADMAP.md. Прочитай актуальные AGENTS/core/профильные правила, аудит F02/E06 и источники. Устрани unknown→house в текущем PackagePlanner/WorkPlanCompiler, сохрани evidence-only/известные профили и не добавляй объектные templates. Сначала согласуй с главным один смысл unknown/completeness и владельца shared файлов. Воспроизведи bridge/structures и bridge/equipment, добавь negative cases неизвестных типов и known-profile регрессии. Не меняй формулы/нормативные пороги/цены; не трогай работу B. Выполни проверки по риску, явно отдели unit от integration. Перед удалением заменённого кода проверь все точки входа, включая динамические.

## Агент B — точность и сметные расчёты

**Ответственность:** проверка измерений/объёмов, формы/геометрия/арифметика, нормативная применимость и измерители, ресурсы/коэффициенты/цены, финансовый результат и его reproducibility, lineage каждого числа, quality regressions и числовая часть acceptance. B определяет, *какое число допустимо и на каком основании*.

Преимущественные backend-модули:

- `Quantities`, числовые инварианты `Domain/ProjectModel/DerivedQuantity`, `FactVocabulary`, `Application/Review` quantity proof и `Application/Apply/CurrentDraftQuantityProofGuard`. Изменение публичного vocabulary/DTO согласует главный.
- `Normatives` candidate/pin/hard gates, `Services/Normatives`, `Services/ResourceAssemblyService`, `Pricing`, `Services/EstimatePricingService`.
- `Domain/Evaluation` арифметика/price evidence/scenarios, финансовая часть `Application/Sessions`, `Application/Apply`, ordinary estimate writer и существующий `GeneratedEstimateDecimalCalculation`.
- `Application/Export` и `app/Integrations/EstimateGeneration/EstimateGenerationExcelExportService`; persisted price finalization boundary и импортные индексы в рамках точности, без правки deployment.
- Целевые quantity/norm/price/export/apply tests, oracle/метрики качества в существующем Benchmark. Исторические формулы/rounding сохранённых артефактов не считать мёртвыми.

Frontend-область B: отображение quantities/норм/ресурсов/цен/расчётных источников, Review/Summary/export. Вопросы A не копировать отдельной workflow-моделью; использовать общий typed unresolved outcome. Общие API/Workspace/EvaluationResultPanel меняет один назначенный автор.

Практический порядок: **R01 → R03**, отдельно короткий **R10**; затем R04/R08, числовые проверки R06/R07/R09. R12 оптимизации — после профилирования; обещание «5000 строк поддержано» требует измерений и round-trip.

Зависимости от A: stable entity/work/package scope и specification, current facts/evidence/decisions, completeness/unknown, source changes/dependency closure. B не создаёт второй project graph, не определяет неизвестное здание как дом и не добавляет скрытую нормативную замену. Недостаток evidence возвращает unresolved с причиной, а не нулевую цену/количество.

Готовность результатов B:

- Невозможные/неподдерживаемые формы не получают confirmed quantity; допустимые граничные случаи сохраняются.
- Любой результат восстанавливается из текущих операндов/формулы/unit/proof, нормы/измерителя/ресурсов/цены и принятого rounding. Допустимый перенос по единицам имеет параметры и источник, не hardcoded аналог неизвестного изделия.
- Нормативные gates не путают цифровые разделы, редакцию, тип/материал/единицу и условия применимости. Покрытие каталога измерено, отсутствующее не объявляется поддержанным.
- Выбранная смета и финансовые экспорты согласованы; нет потери коммерческих строк и двойного включения ресурсов. Apply/replay сохраняет принятые деньги.
- Независимый oracle проверяет состав и каждую числовую стадию, а не только общий итог. Технические replay/mocks и real provider accuracy различимы.
- Неподтверждённые полномасштабные/production свойства перечислены явно.

### Готовое первое задание чату B

> Работай над R01 из AI_ESTIMATOR_ROADMAP.md. Прочитай актуальные правила, аудит F01/E07 и DerivedQuantityFactory. Добавь regression: confirmed стена 10×3 м с проёмом 4×4 м не должна давать confirmed 14 м². Минимально проверь физическую допустимость совместимых форм/осей; при недостаточном вводе дай review без guessed clipping. Сохрани current source/decision/evidence guards, проверь корректные границы/дубли/пересечения при наличии координат. Общий тип вопроса согласуй с главным/A. Не меняй planner/идентичность проекта и не переписывай формулы ради общего solver. Затем по отдельной задаче возьми R03/R10. Верификацию DB выполняй только разрешённым изолированным способом; production и миграции вне прямого разрешения не запускай.

## Контракт взаимодействия A ↔ B

1. **A передаёт** scope/identity, source fingerprint, current facts/evidence/decision IDs, спецификацию и work intent, выбранные разделы и требования полноты. Отсутствующий вход имеет причину/вопрос, не выдуманное значение.
2. **B возвращает** `passed / unresolved / failed` проверок с кодом причины; количество/единицу/formula/operands/proof либо null; нормативное решение и pinned source; resource/price snapshots, money/rounding; affected dependencies. Не выдаёт confidence за инженерную истину.
3. **A использует** результат для вопросов, trust/reuse и планирования. Подтверждение исходного параметра пользователем меняет version/decision, но не переводит автоматически любую downstream проверку в passed.
4. **Оба сверяют** context/source version перед записью/чтением. Обновление документа/системы снимает только доказанно затронутые решения; до готового partial scheduler допустим явный полный пересчёт.
5. **Главный принимает** shared contract, backward compatibility, один oracle и результаты. Спор о числе решает источник/детерминированная проверка/экспертный expected result, а не голосование LLM.

Семантика выше предлагается для согласования; это не требование вводить перечисленные английские поля отдельным новым API. Сначала сопоставить существующие DTO/outcomes и добавить только недостающее.

## Параллельная работа и UI/UX

Первый безопасный параллельный шаг: A исправляет R02, B — R01 в разных implementation файлах. Далее A — R05, B — R03/R10. Перед R06 главный фиксирует identity и план миграции consumers; A и B не коммитят разные версии контракта одновременно. Затем B расширяет приёмку R08, A — R07/R09 по согласованному scope. Каждый независимый task использует собственную ветку/обычный workflow; чужие ветки, worktree, тестовый runtime и изменения не переключать/не очищать. Никаких автоматических merge при незавершённом актуальном Codex Review.

Отдельный третий агент UI/UX **сейчас не нужен**: доказанные permission/capability/output-parity исправления относятся к A/B и не требуют параллельной архитектуры интерфейса. Сначала browser pilot новичка/специалиста. Если подтвердится самостоятельный существенный объём очереди вопросов, навигации/поиска и работы с большой таблицей, главному выделить точные UI-файлы третьему исполнителю после стабилизации contracts. Ему принадлежат представление/фокус/навигация; DTO, денежные правила, permissions, recovery и смысл confirmed остаются у текущих владельцев.

## Передача результата новому чату

В handoff указать задачу Rxx, точный baseline/head SHA, владельца и затронутые файлы/контракты, фактически выполненные команды/результаты, unresolved доказательства, ссылку на PR/review и оставшиеся ветки/worktree. Не писать «всё работает» по зелёным unit. Не запускать новые функции из этого документа без выбранной конкретной задачи и актуальных правил репозитория.
