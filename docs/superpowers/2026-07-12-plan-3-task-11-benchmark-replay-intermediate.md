# План 3, задача 11: промежуточный отчёт benchmark replay

Статус: `INTERMEDIATE`. Quality gate задачи 11 не закрыт.

## Выполнено

- Введён закрытый versioned envelope для записей только недетерминированных портов: vision/document/CAD extraction, work-planning model и normative reranker.
- Envelope фиксирует SHA-256 источника и непосредственной входной зависимости, версии provider/model/prompt/schema, ограниченный payload, его SHA-256, privacy scanner/version, `capture_kind=contract_fixture`, approval metadata и SHA-256 benchmark manifest.
- Разрешён только `approval_kind=maintainer_code_review`; fixture должен представлять независимо сформированный provider/model output, а не производную от benchmark expected.
- Рекурсивно запрещены oracle-поля (`expected*`, labels, metrics, prediction, readiness, итоговые цены/стоимости) и чувствительные ключи.
- Loader проверяет безопасный immutable path, SHA-256 descriptor/file, соответствие case input SHA, benchmark manifest SHA, уникальность порта и замкнутую цепочку input dependency.
- Проверены tampering, oracle/secret rejection и целостность loader.

## Карта следующей реализации

1. Передавать адаптеру projection кейса без `expectedLocator`, `expectedPath`, `expectedSha256` и fixture root. `BenchmarkRunner` загружает expected только после завершения prediction.
2. Source dispatch:
   - vector PDF -> production vector/document extraction DTO validator;
   - scanned PDF/photo/sketch -> `VisionAnalysisData::fromProviderArray`;
   - DWG/DXF -> production `CadGeometryProvider` output validator;
   - затем geometry fusion/scale resolution -> `BuildingModelAssembler`.
3. Количества получать через `BuildingQuantityCalculator`, сохраняя точные evidence IDs и source certainty.
4. Recorded work-planning output валидировать закрытым provider DTO и передавать в production planner (`PlanWorkItemsStage` либо выделенный чистый planner contract), не принимать готовый benchmark `work_ids`.
5. Normative flow: DB-less versioned fixture candidate catalog -> `NormativeHardGate` -> production workflow с recorded reranker output. Envelope не может содержать готовую финальную норму.
6. Resource/price flow: production resource assembly и pricing над versioned fixture price catalog/snapshot; итоговые цены вычисляются, не записываются в provider envelope.
7. Readiness вычислять единым production evaluator, затем строить prediction contract и только из него метрики.
8. Расширить regression corpus до минимум восьми содержательно разных обезличенных cases; отдельно проверить disjoint source/artifact hashes с acceptance.

## Открытые gates

- Production replay orchestrator и CLI-only registration не реализованы.
- Строгая projection-изоляция adapter от expected пока не реализована.
- Regression corpus содержит четыре cases, один DWG всё ещё является unsupported placeholder descriptor.
- Required command ещё не может дать `attempted >= 8`, `failure=skip=0` и целевые метрики.
- Полный Plan 3 test/phpstan gate и двукратный deterministic CLI replay не запускались.

## Продолжение: anti-oracle projection

- Добавлен `BenchmarkPredictionCaseData`: адаптер получает только идентификатор, dataset/source type, input locator/hash, tags/capabilities и ссылки/хэши recorded envelopes.
- `expectedLocator`, `expectedSha256`, expected content и fixture root отсутствуют в свойствах и сериализованном projection; это проверено adversarial reflection/serialization test.
- In-process executor и изолированный worker передают адаптеру только projection. Полный `BenchmarkCaseData` остаётся на dispatch/evaluation стороне.
- Local/private object readers запрещают чтение expected через projection; input разрешён только после проверки ограниченного locator, containment и SHA-256.
- Traversal, абсолютные пути и произвольные URI закрыты; для acceptance разрешён только точный org-scoped S3 locator contract.
- Benchmark gate после изменения: `90 passed`, `300 assertions`, `4` ожидаемых DB-contract skips; PHPStan benchmark/worker: `No errors`.

Этапы payload validators, production replay adapter и первые два end-to-end кейса ещё не реализованы.

Task 11 нельзя отмечать `DONE`, пока все открытые gates не закрыты без снижения порогов и oracle-подстановок.

## Продолжение: projection-safe replay dependencies и нормативный каталог

- `BenchmarkPredictionCaseData` расширен только входными replay-зависимостями: SHA-256 отдельного recording manifest и парой locator/SHA-256 нормативного каталога. Expected-данные в projection не передаются.
- `RecordedPortEnvelopeLoader::loadProjection()` разрешает только явно объявленные projection locator/hash, проверяет SHA-256 исходного файла, recording manifest и замкнутую цепочку зависимостей. Полный `BenchmarkCaseData` адаптеру не требуется.
- Добавлен закрытый `RecordedBenchmarkCatalogData` версии `recorded-benchmark-catalog:v1`: dataset/version/status, region/period/currency, кандидаты норм, ресурсы и цены, approval/privacy metadata. Рекурсивно запрещены expected/label/prediction/readiness/final price/cost поля.
- RED→GREEN проверки закрывают projection-only загрузку, descriptor mismatch и oracle-поля каталога.

## Открытый блокер production replay adapter

- В production отсутствует преобразователь `VisionAnalysisData` или `VectorGeometryData` в `FusedGeometryElementData`, `ScaleCandidateData` и `VisionBuildingModelInputData`; `BuildingModelAssembler::assembleVision()` сейчас вызывается только тестами.
- `BuildingQuantityCalculator` принимает плоский calculator schema (`rooms`, `walls`, `openings`, scale/evidence operands), который не совпадает с `NormalizedBuildingModelData::toArray()`. Нужен отдельный lossless production mapper с проверяемыми evidence refs, а не benchmark-only подстановка.
- Поэтому два E2E и финальный `ProductionReplayBenchmarkAdapter` в этом срезе не заявлены реализованными; значения prediction, norm top-3 и цены не записывались в artifacts.

## Продолжение: port validators и planner boundary

- `RecordedPortEnvelopeLoader` валидирует payload по объявленному порту через production factories: `VisionAnalysisData::fromProviderArray`, `VectorGeometryData::fromArray` и `NormativeRerankResultData::fromProviderArray`.
- Production LLM reranker и replay используют единый закрытый normative factory; ordering обязан быть полной перестановкой candidate set без придуманных или пропущенных ID.
- Введён закрытый `RecordedWorkPlannerResponseData`: только sections и семантические work intents с name/category/unit/quantity, обязательными quantity evidence refs и confidence.
- Planner envelope не может содержать `work_id`, `norm_id`, цены, readiness или expected-поля: exact schema отклоняет их до replay.
- Следующий обязательный срез: выделить чистый `WorkPlanCompiler` из `PlanWorkItemsStage`, сохранить parity текущего runtime output и подключить recorded/live planner DTO через единый provider interface. Этот срез и два end-to-end replay cases ещё не реализованы.
