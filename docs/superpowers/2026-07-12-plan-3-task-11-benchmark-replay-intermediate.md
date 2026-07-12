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
