# ИИ-сметчик МОСТ: ответственность и задания агентов

Обновлено 24.10.2026. Задачи и приёмка: [roadmap](AI_ESTIMATOR_ROADMAP.md), исходные доказательства: [аудит](AI_ESTIMATOR_AUDIT.md), охват: [универсальность](AI_ESTIMATOR_UNIVERSALITY.md). Пользователь разрешил реализацию двумя независимыми потоками; главный координирует и выпускает, production-код третьим разработчиком не пишет.

## Общие правила

Сохранять существующие ProjectModel, pipeline, Composer/Understanding, Learning, RAG и Benchmark. Не создавать второй движок/Memory, fine-tuning, двадцать шаблонов объектов, regex NLP или новый LLM-проход без доказанной необходимости. LLM отвечает за смысл; детерминированный код — за проверяемость структуры, единиц, расчётов, нормативов, денег и источников. Unknown/ambiguity дают предметный вопрос, а не guessed work/quantity/price.

Один shared контракт утверждает главный и передаёт одному автору. До реализации агент сообщает точные файлы и публичные/внутренние изменения. A/B работают в независимых task-ветках/worktree, не переключают чужие checkout и не откатывают чужие изменения. Изолированные PostgreSQL окна передаются явно; общий runtime/БД не пересоздавать. Правила репозитория и безопасного тестового контура обязательны.

Готовность — meaningful regression, проверки по риску с действительными логами и явными границами mocks/native/provider; независимый review сложного блока; затем Codex Review текущего полного remote SHA и разбор всех обсуждений. Новые коммиты требуют нового ревью. Главный выполняет squash в main, ждёт штатный deploy/public readiness и убирает только свои ветки/worktree после recovery bundle и проверки точного merged source tip. Незавершённое ревью/таймаут/успешный CI не разрешают merge.

## Главный координатор

Владеет shared domain/API/DTO/schema/trust/version contracts, acceptance oracle, тремя текущими документами, интеграцией, ревью, выпуском и одним production QA. Исторический AI_ESTIMATOR_AUDIT.md остаётся исходным снимком. Назначает одного автора каждого общего файла и согласует consumers до изменения. Не исправляет runtime параллельно A/B.

Особенно конфликтные файлы: `EstimateGenerationServiceProvider.php`, `routes.php`, `Pipeline/Stages/PlanWorkItemsStage.php`, общие `Domain/ProjectModel`/`Domain/Evaluation` DTO, frontend API/types/normalizers, Workspace/EvaluationResultPanel. Наличие свободного агента не разрешает ему менять эти файлы без назначения.

## Агент A — универсальное ядро

**Отвечает:** проект/сооружения/системы и их identity, состав и зависимости работ, неизвестные технологии и вопросы, scoped решения, Learning/Estimate Memory, контекст изменений/частичный пересчёт, связанные setup/documents/questions UI. Порядок: защитный R02 → R05/R07 knowledge loop → R06 → R09. R04-приёмка сопровождает каждую поставку.

**Основные модули** внутри `app/BusinessModules/Addons/EstimateGeneration`: `Domain/ProjectModel`, `Application/Understanding`, `Application/Planning`, `Planning/WorkPlanCompiler`, `Services/PackagePlannerService`, Composer/Questions, `Application/Corrections`/`TargetedRebuild`, `Services/Learning`; интеграция existing RAG/EstimateLearningExampleExtractor. Обработка документов — только при подтверждённой необходимости пилота; числовая семантика согласуется с B.

**Зависит от B:** typed исходы unit/geometry/norm/price проверок, актуальный quantity proof, независимые числовые oracle и условия применимости. A не выдаёт LLM-confidence, ответ пользователя или literal match за инженерное подтверждение.

**Критерии:** неизвестный объект не получает house; доказанные элементы сохраняются; вопрос объясняет недостающее и его влияние. Human/source/engineering outcomes различимы. Reuse требует current answer/work/entity/source/version/revocation/scope. Несколько сооружений и общие сети не схлопываются. Частичный пересчёт подтверждён trace и сохранностью соседних решений, иначе явно полный.

**Текущий срез A:** R06 выпущен PR1702; UI828 и backend1707 reviewed/deployed, B1703 integrated. Однако QA92 всё ещё не получает upload action; runtime причина не доказана. Новый semantic producer на runtime/test checkpoint7023dd23337521dde2537b6c29fe96f5d2b69ae5 проводит closed proposed descriptor через existing observer, arbitration, canonical reducer и ProjectModel storage. Сквозной target1/544 и полный class5/625, arbitration units49/336; live semantic accuracy и инженерная authority этим не доказаны. Root владеет review/release, A не меняет код во время интеграционных проверок B.

Контракт этого среза: existing GET questions optional `source_checks=0|1`; сервер проверяет authoritative `estimate_generation.view` отдельно от review. Без view вопросы доступны по прежним правам, proof не раскрывается. Один bounded consistent read; nested transaction не повышает isolation/read-only и не выдаёт proof. Default Composer source refs остаются прежними10полями. UI показывает конкретную характеристику и источник отдельно от engineering unresolved; stale/error/refresh/pending/org/session change немедленно скрывают прежний positive, не стирая ответ пользователя. Нет нового платного вызова или записи через GET.

**Следующее задание A:** после producer release устранить только доказанный блокер same-session documents, если его локализует owner HTTP/production evidence. Главный knowledge-приоритет — independent source/applicability admission и requirement без observed через существующий Learning. Сначала нужен реальный источник и проверяемые target conditions; ещё один proposed carrier не выпускать. Optional parameter_options/parameter_checks не меняют literal source_checks; human interpretation не получает numeric authority. Затем Factory clarification handoff, универсальная identity и комплексы по roadmap.

## Агент B — инженерная точность

**Отвечает:** извлечение/проверка измерений и объёмов, физическая допустимость/формулы/decimal/единицы, нормативная применимость, ресурсы/коэффициенты/цены, reproducibility и происхождение чисел, экспорт/apply и числовая acceptance. Порядок: R01 → R03, короткий R10; параллельно R04/oracle/R05/R07 predicate, затем R08 и числовые consumers R06/R09. Не уходить в бесконечные частные конструкции.

**Основные модули:** `Quantities`, DerivedQuantity/FactVocabulary/current proof guards, `Normatives` и существующие hard gates/pins, ResourceAssembly/Pricing, арифметика `Domain/Evaluation`, financial session/apply, `Application/Export` и Excel export integration, текущие Benchmark/tests. Общие DTO/vocabulary только по контракту главного. Frontend B — quantities/norm/resources/prices/Review/Summary/export, не отдельный workflow вопросов.

**Зависит от A:** stable entity/work/package scope, specification/current facts/evidence/decisions, source revision/invalidation. B не создаёт второй graph, не определяет неизвестное здание как дом и не подменяет отсутствующую норму скрытым аналогом.

**Критерии:** невозможная геометрия не confirmed; каждый результат воспроизводим по операндам/формуле/unit/source/version/price/rounding. Missing evidence возвращает unresolved, не нулевую цену. Norm selection учитывает измеритель, тип/материал/условия/редакцию; перенос единиц имеет основание. Выбранные CSV/XLSX/apply сохраняют состав/net, а НДС/gross/резерв сравниваются отдельно. Исторические persisted формулы/receipts не удаляются как мёртвый код.

**Последние поставки B:** generic carrier/native operand интегрированы в PR1698; независимый checksum guard и scoped checks сохранены. Tests-only PR1697 (`ee6b25a31a86dc96cd349f2b861f15963497a906`) и PR1699 (`82d0102c6c8bd9f6fdac71d520dfe4be5a930f16`) после exact Review интегрированы в main. Первый доказывает decimal HTTP money и coefficient drift, price immutability; второй actual PG1/92 edition/source/resource/price pins. Partial failed batches не объявляются GREEN; configured Larastan остаётся BLOCKED. Это не официальная применимость нормы или производителя.

**Следующее задание B:** producer acceptance на7023 прошёл target1/544 и весь ObserverEvidenceProjectionPostgresTest5/625. Oracle сохранил7 current source facts/5 proposed descriptors, strict no-HTTP/no-credit/no-autoGenerate, closed SQL shape/pins, legacy/history/replay/ignore. VisionProvider controlled; real provider не проверен. Далее один real-owner snapshot HTTP test без подмены разрешений, затем independent source/edition/target predicate для knowledge admission. Нормативный backfill остаётся read-only: protected rows не менять, skipped/eligible и unit_unknown учитывать; production запуск без отдельного разрешения запрещён.

## Контракт A/B

| Граница | Автор после согласования | Что гарантирует |
| --- | --- | --- |
| ProjectModel/entity/work/scope/source identity | A | Stable IDs, org/project/session, source hash/revision, неизвестность и принадлежность |
| Quantity/formula/operands/proof | B | Decimal/unit, допустимость, воспроизводимость и current source/decision fingerprint |
| Norm/resources/price | B | Dataset/edition/условия/измеритель, pin, цена/период/регион/источник/rounding |
| Question/Decision/trust/reuse | A | Human confirmation отдельно от passed/unresolved/failed независимых проверок; current context перед повтором |
| Invalidation/shared HTTP/types/schema | Один назначенный главным автор | Совместимость consumers, явная область пересчёта, версии/идемпотентность/allowed actions |

Это смысловые границы, не требование создать новые DTO/API с перечисленными именами. Сначала сопоставить текущие типы и добавить только недостающее. B возвращает проверяемый outcome/reason/source/conditions, A использует его для вопроса и допуска. Согласие LLM/curator label не решает спор об инженерном числе.

Действующий source-claim контракт PR1680: только closed active current included work_technology_context answer, exact binding/entity и attached current native XLSX equipment_specification Fact. Candidate имеет прежние6полей; authoritative source_document_id выводится из однозначных Evidence и проверяется вместе с version/hash/witness. Итог proposed/source_correspondence passed/engineering unresolved/numeric false/normless/nonpositive/not indexed/not training. Это не проверка narrative. Re-answer/revoke/stale/checksum/replacement/exclusion/foreign scope прекращают зависимый reuse; самостоятельный Fact не повышается и не отменяется произвольно. Raw answer/Decision не становятся обычными numeric facts. Новые privilege получают только exact версии answered contracts; история сохраняет прежний смысл.

Текущий additive parameter контракт: `{name,value,unit,basis,source_operand_ref,condition:minimum_length}` только для work-вопроса; сервер повторно разрешает opaque ref в текущей области. Raw source остаётся неизменным. Human interpretation и measured/assumed value — отдельные unresolved GenericFacts, evaluation_answer остаётся human-confirmed. Server-derived component хранится в existing Learning как estimate_session с origin=work_parameter_component:v1; literal ai_role_run сохраняет прежнюю семантику. Обе ветки proposed/nonpositive/normless/unaccepted, без training/index и повышения numeric authority. Context/source/re-answer/revoke/tenant guards обязательны перед capture и reuse.

Document semantic proposal остаётся schema_version1/status=proposed: closed payload, field_locators, source_pins, proposal_hash. Field refs разрешаются независимо в captured document/page/unit; source subject не current work binding. General source requirement допускает equipmentIdentity=null. Нормализация length/gte/decimal не подтверждает применимость. Новая additive migration сохраняет прежний SQL guard; down отказывается при существующей истории proposals, поэтому rollback приложения с сохранением DB contract и migration down — разные операции. Ручной production rollback/migrate не выполнять.

Следующий knowledge-контракт разделяет requirement verification, observed input confidence и conditional comparison. Отсутствие замера не отменяет самостоятельно подтверждённое требование; отзыв human value отменяет зависимое сравнение. Native literal proof не удостоверяет физический замер. Verified requirement не становится глобальным правилом, feedback positive или trusted RAG. До closed source/applicability admission и его независимой приёмки новый verified результат не объявлять реализованным.

## QA, UI и передача следующему чату

Один независимый production QA продолжается только после reviewed main releases и успешного deploy. Shared cap500ед./90₽, свежие release/access/budget/source gates и quote перед paid действием. Ошибка/неизвестный исход не разрешают повтор новой UUID, ручную очистку storage или прямую правку current-флагов. Учёт supplier cost, credit ledger и UI balance проверяются раздельно. Учётные данные не сохранять в отчётах/репозитории. Backfill нормативного поиска остаётся read-only без отдельного GO на исправление/запуск.

Отдельный третий UI/UX-агент пока не нужен: source/typed question и same-session document handoff — ограниченные consumers A. Единственный production QA сейчас operator-assisted: root управляет доступным IAB по шагам QA, тот независимо читает DOM/AX/PNG и фиксирует результат; прямой browser control субагента недоступен. Tooling failure не объявлять product bug. Выделять отдельного UI исполнителя только при доказанном самостоятельном объёме; расчёт/права/contracts остаются у владельцев.

Handoff содержит R-задачу, immutable base/head/tree, точные owned/shared paths, действительные команды и результаты, mocks/native/provider границы, неустановленные причины, PR/review/deploy и оставшиеся ветки/worktree с владельцем. Главный хранит подробный журнал/логи вне checkout; эти документы сохраняют компактный текущий план. Перед каждым новым code task перечитать актуальные AGENTS и правила затронутой области.
