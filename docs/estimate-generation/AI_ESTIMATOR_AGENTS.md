# ИИ-сметчик МОСТ: ответственность и задания агентов

Обновлено 23.10.2026. Задачи и приёмка: [roadmap](AI_ESTIMATOR_ROADMAP.md), исходные доказательства: [аудит](AI_ESTIMATOR_AUDIT.md), охват: [универсальность](AI_ESTIMATOR_UNIVERSALITY.md). Пользователь разрешил реализацию двумя независимыми потоками; главный координирует и выпускает, production-код третьим разработчиком не пишет.

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

**Текущий срез:** source proof readback/UI выпущены1687/824; оба точных head прошли Review, normal main deploy и public release/ready. A завершил отдельный Composer JSON checkpoint650a81e59e4d78500fa889f20607251cb3f7e907: `EstimateComposerInput`, `TimewebEstimateComposerModel`, `TimewebChatCompletionPayloadFactory` и четыре existing test-класса. Восьми текущим контрактам добавлены opt-in и transport identity; другие роли/defaults не менялись. Root владеет документацией/контрактом/release. Actual provider JSON capability до нового QA не доказана.

Контракт этого среза: existing GET questions optional `source_checks=0|1`; сервер проверяет authoritative `estimate_generation.view` отдельно от review. Без view вопросы доступны по прежним правам, proof не раскрывается. Один bounded consistent read; nested transaction не повышает isolation/read-only и не выдаёт proof. Default Composer source refs остаются прежними10полями. UI показывает конкретную характеристику и источник отдельно от engineering unresolved; stale/error/refresh/pending/org/session change немедленно скрывают прежний positive, не стирая ответ пользователя. Нет нового платного вызова или записи через GET.

**Следующее задание A:** пройти JSON review/main deploy и продолжить тот же bounded QA через свежую quote. Затем в отдельной согласованной задаче связать work question с exact scalar/unit/basis, текущими document pins и carrier B; сейчас текстовый ответ и manual numeric fact разделены. Переиспользовать Questions/ManualFacts/ProjectModel/Learning, не парсить `other` regex и не вводить второй контур. Запросить модель/config/manual edition и недостающие параметры; независимый source threshold отделить от измерения человека. Подготовить current admission/reuse и отказ при stale/revoke/foreign scope. Без независимого numeric source producer компонент остаётся unresolved; LLM, hash или doc-link authority не создают.

## Агент B — инженерная точность

**Отвечает:** извлечение/проверка измерений и объёмов, физическая допустимость/формулы/decimal/единицы, нормативная применимость, ресурсы/коэффициенты/цены, reproducibility и происхождение чисел, экспорт/apply и числовая acceptance. Порядок: R01 → R03, короткий R10; параллельно R04/oracle/R05/R07 predicate, затем R08 и числовые consumers R06/R09. Не уходить в бесконечные частные конструкции.

**Основные модули:** `Quantities`, DerivedQuantity/FactVocabulary/current proof guards, `Normatives` и существующие hard gates/pins, ResourceAssembly/Pricing, арифметика `Domain/Evaluation`, financial session/apply, `Application/Export` и Excel export integration, текущие Benchmark/tests. Общие DTO/vocabulary только по контракту главного. Frontend B — quantities/norm/resources/prices/Review/Summary/export, не отдельный workflow вопросов.

**Зависит от A:** stable entity/work/package scope, specification/current facts/evidence/decisions, source revision/invalidation. B не создаёт второй graph, не определяет неизвестное здание как дом и не подменяет отсутствующую норму скрытым аналогом.

**Критерии:** невозможная геометрия не confirmed; каждый результат воспроизводим по операндам/формуле/unit/source/version/price/rounding. Missing evidence возвращает unresolved, не нулевую цену. Norm selection учитывает измеритель, тип/материал/условия/редакцию; перенос единиц имеет основание. Выбранные CSV/XLSX/apply сохраняют состав/net, а НДС/gross/резерв сравниваются отдельно. Исторические persisted формулы/receipts не удаляются как мёртвый код.

**Последняя поставка:** PR1686 exact DB resource coefficient, reviewed6bc5a91a→main2922b746/deploy40010683989 SUCCESS. Unit82/425 и canonical PG3/89 подтверждают decimal/pin guards, не официальную норму и не полный HTTP-money flow. Ветка/worktree убраны, следующий runtime требует новой fresh-main ветки.

**Следующее задание B:** закончить согласованную generic parameter foundation и передать A точный интерфейс. Только `Fact`, `FactVocabulary`, `EloquentProjectModelRepository`, `NativeNumericFactProof` и три existing unit-класса. Сохранять scalar value/unit и legacy пустой metadata/hash; непустой `semantic_attributes` включает полные definition/parameter/source/work pins и участвует в identity. Два параметра на одной entity не схлопываются. Чистое сравнение length через BigDecimal не повышает trust. Native proof не принимает shape/document-link за основание; public/HTTP consumer пишет A после согласования. Foundation отдельно не считается выполненным knowledge loop и не выпускается без подключённого consumer. Для первого независимого numeric predicate всё ещё нужен lawful current source producer и applicability; GEA pump не подставлять вместо неизвестной доильной установки.

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

## QA, UI и передача следующему чату

Один независимый production QA продолжается только после reviewed main releases и успешного deploy. Shared cap500ед./90₽, свежие release/access/budget/source gates и quote перед paid действием. Ошибка/неизвестный исход не разрешают повтор новой UUID, ручную очистку storage или прямую правку current-флагов. Учёт supplier cost, credit ledger и UI balance проверяются раздельно. Учётные данные не сохранять в отчётах/репозитории. Backfill нормативного поиска остаётся read-only без отдельного GO на исправление/запуск.

Отдельный третий UI/UX-агент пока не нужен: текущая карточка проверки — самостоятельный ограниченный consumer A. Выделять третьего исполнителя только после browser pilot, если доказан существенный объём навигации/источников/больших таблиц; правила расчёта/прав/contracts остаются у владельцев.

Handoff содержит R-задачу, immutable base/head/tree, точные owned/shared paths, действительные команды и результаты, mocks/native/provider границы, неустановленные причины, PR/review/deploy и оставшиеся ветки/worktree с владельцем. Главный хранит подробный журнал/логи вне checkout; эти документы сохраняют компактный текущий план. Перед каждым новым code task перечитать актуальные AGENTS и правила затронутой области.
