# Evidence этапа 3 AI-помощника сметчика МОСТ

## Task 10. Канонические представления документов

Реализован закрытый `DocumentRepresentationCapabilities` для PDF, изображений, CAD и XLSX. Capability имеет только два честных исхода: `available` либо `unavailable:<safe_reason>`. Недоступная нативная возможность формирует типизированный `DocumentRepresentationLimitation` и `DocumentManifestNeedsReview` с безопасным контекстом.

`DocumentRepresentationBuilder` формирует единый контракт из organization-scoped S3 locator, нативного artifact reference, визуального artifact reference, source coordinate space, capability contract и bounded resource usage. `DocumentCoordinateTransform` обеспечивает обратимый переход source coordinates ↔ normalized coordinates, включая отрицательные CAD bounds.

XLSX extractor сохраняет листы, ячейки, формулы, объединения и фактический диапазон. Визуальное представление таблицы публикуется через существующий `DocumentSourceManifestStorage` как bounded SVG, а не сохраняется на локальном диске. PDF сохраняет отдельные ссылки на text spans, vectors и page render. Image/CAD capabilities не маскируют ещё не опубликованные OCR/native/render artifacts: вместо скрытой подмены они возвращают review limitation.

Обезличенные fixtures находятся в `tests/Fixtures/EstimateGeneration/documents/v2/`: по одному минимальному и одному production-sized manifest для PDF, image, CAD и XLSX. Production manifests фиксируют верхние границы страниц, объектов, размера, памяти и времени; тяжёлые бинарные parser fixtures остаются CI-only, чтобы локальный набор не превышал пять минут.

Замена источника проверена существующими scoped contracts: инвалидируются только старая source version, её units, evidence и транзитивные dependants; новая версия, другой tenant и другие документы остаются активными.

### Проверки

- RED: `DocumentRepresentationMatrixTest` — отсутствовали capability, coordinate и resource-limit contracts.
- GREEN: DB-free document matrix, canonical adapters, native PDF/CAD/XLSX contracts, source replacement и evidence invalidation — `32 tests, 154 assertions`.
- Дополнительный RED/GREEN: XLSX merged range и фактические row/column bounds — `1 test, 9 assertions`.
- PHPStan изменённых production PHP-файлов — PASS с безопасным `APP_ENV=testing` и фиктивной канонической S3-конфигурацией; внешние соединения не выполнялись.

Широкий directory selector `tests/Unit/EstimateGeneration/Ocr` не является допустимым локальным gate: часть файлов использует `RefreshDatabase` и запускает test-schema bootstrap. Он исключён из дальнейших локальных прогонов; DB-dependent проверки выполняются только в CI.

## Task 11. Ролевой мультимодальный анализ

Введён закрытый `sheet-analysis:v2`: лист имеет одну из шести ролей (`plan`, `section`, `facade`, `explication`, `specification`, `unknown`), а каждый provider-вызов получает ровно одну роль и один контракт. Результат материализуется в `PlanSheetAnalysis`, `SectionSheetAnalysis`, `FacadeSheetAnalysis`, `SpecificationSheetAnalysis` либо `UnknownSheetAnalysis`. Экспликация и спецификация намеренно используют общий табличный типизированный контракт, сохраняя различимый role.

Каждый факт содержит только `entityKey`, `factType`, `value`, `unit`, `evidenceRef`, `sourcePolygonOrNativeRef`, `confidence`, `contractVersion`. Validator применяет role-specific allowlist, требует существующий evidence, проверяет normalized polygon либо ограниченную нативную ссылку и отклоняет лишние ключи, повторные сущности, битые координаты и факты неизвестного листа.

`TargetedSheetRecheckScope` ограничивает повторный анализ одной конфликтной сущностью на одном листе либо парой листов. В provider payload передаются только безопасные scoped IDs, причина и тип контракта. Те же `reason`, `source_set`, `role` и `entity_key` входят в immutable fingerprint и сохраняются в новом закрытом `request_context` существующего usage ledger. Миграция добавлена, но локально не запускалась; PostgreSQL schema gate остаётся CI-only.

Самостоятельный review-pass подтвердил отсутствие второго pipeline/adapters/finalization слоя. Исправлены две исходные RED-регрессии: неверный delimiter regex в project sheet validator и отсутствующий 1860-секундный journal lease contract.

### Проверки

- RED: отсутствовали `sheet-analysis:v2`, пять типизированных DTO и `TargetedSheetRecheckScope`; старые validator/routing tests падали на regex и lease contract.
- GREEN: role/contract, targeted scope, routing, provider payload, privacy и usage ledger — `48 tests, 177 assertions` в полном DB-free наборе; предупреждения PHPUnit относятся к существующему database-less bootstrap и не являются ошибками тестов.
- После review-правок: целевые role/recheck/routing tests, provider tests изменённых ветвей и регрессия native reference — PASS.
- PHPStan всех изменённых production-файлов — PASS с `--memory-limit=1G`; первый запуск с конфигурационным лимитом 128 MB завершился только memory exhaustion и был повторён один раз с достаточным лимитом.
- Миграции, DB-команды, dev server, merge, push и deploy не выполнялись.
