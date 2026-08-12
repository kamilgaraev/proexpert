# Устранение production-инцидента обработки PDF в AI-сметчике МОСТ

> **Для исполнителя:** план выполняется в этом чате лично по TDD. Субагенты допустимы только для одного read-only ревью каждого из двух завершённых крупных блоков; код, диагностика, тесты, git и выпуск не делегируются.

**Цель:** доказать и устранить первопричину системного отказа обработки PDF-страниц, остановить каскад одинаковых повторов, привести состояние документа и интерфейс к честному outcome, ограничить ресурсный риск и выпустить backend/admin штатными GitHub workflows.

**Архитектура:** существующий Laravel/Horizon/Redis/PostgreSQL/S3 pipeline остаётся единственным runtime. Ошибки unit получают явную классификацию `deterministic terminal` / `recoverable transient` / `user action required`; document-scoped circuit breaker строится на существующих processing units и failure ledger с tenant/project/session/document/source-version fencing. Канонический document outcome агрегируется транзакционно после завершения units, а admin отображает один безопасный document-level результат и отдельно только действительно разрешимые пользователем page-level проблемы.

**Стек:** PHP 8.2, Laravel 11, PostgreSQL, Redis/Horizon, S3, PHPUnit, Larastan/Pint; React/Vite/TypeScript, Vitest/MSW, ESLint/Prettier; существующие Docker и GitHub Actions МОСТ.

## Глобальные ограничения

- Не работать в `main`; backend и admin выполняются в отдельных worktree от свежего `origin/main`.
- Не добавлять Kubernetes, CI, secrets, MLOps, брокер, сервер или сервис; не менять production вручную.
- Не запускать production-записи, повтор документа, платную AI-генерацию, ручные production migrations, dev-серверы или admin build.
- Локальные PostgreSQL contract tests обязательны через штатный wrapper; новые сценарии должны пройти без skip.
- Пользовательские строки только на русском через существующий переводческий контракт; internal codes и stack не показываются.
- TDD: каждый behavior fix начинается с доказательного RED, затем минимальный GREEN и пропорциональная регрессия.
- Один собственный итоговый correctness/security/UX review; не маскировать baseline failures и `storage_configuration_invalid`.

---

## Зафиксированные доказательства инцидента

### Клиентский файл

- Локальный источник: `C:\Users\kamilgaraev\Downloads\ar (1).pdf`.
- 22 страницы, 2 690 324 байта, A3 landscape.
- Чистый векторный архитектурный альбом с извлекаемым текстом: общие данные, планы, фасады, разрезы, кровля и проёмы.
- Документ корректный; это не повреждённый скан.

### Production state

- `document_id=168`, `session_id=66`, `organization_id=38`, `project_id=52`.
- Создан `2026-08-12 20:47:09 UTC`, filename `ar (1).pdf`.
- Document: `status=needs_review`, `processing_stage=completed`, `progress_percent=100`, `page_count=22`, `processed_page_count=0`, `ocr_attempts=0`, `error_code=null`, `error_message_key=null`.
- Units `280..301`: все `pdf_page`, все `failed`, `attempt_count=3`, `output_count=0`, `failure_code=document_geometry_processing_failed`, единый unit fingerprint `4312edf6bbcb5daeb290f800a1d0ae95ed0f3c5335fce07a2dc28f50ac2e5517`.
- Failure ledger: 22 active terminal failures, `stage=understand_documents`, `operation=process_unit`, `occurrence_count=3`; `safe_context` хранит только safe code, исходное исключение не сохранено в доступном evidence.
- UI сначала показывал «0 страниц участвует», «20 требуют повторной обработки», «2 в обработке»; в итоге упали все 22.

### Временная линия и ресурсы

- `23:47:29 MSK`: manifest/document job завершён; page units уже начали падать с `23:47:32`.
- Выполнено 66 неуспешных unit-запусков; последние исчерпали попытки к `23:51:25`.
- Global OOM произошёл позже, `23:51:09`; kernel убил `clamd` с RSS около 963 MiB. OOM — усилитель каскада, не первопричина geometry failure.
- На host с 8 GiB: available около 470 MiB, swap отсутствует; Horizon около 2.93 GiB/34 процесса, OnlyOffice около 976 MiB, ClamAV около 945 MiB, API около 566 MiB; scheduler наблюдался с 155% CPU и 211 PID.
- Geometry worker/recovery worker были недавно перезапущены; ClamAV после OOM не мог поднять socket, signatures старше 7 дней.
- Увеличение host до 12 GiB — только временный запас, не исправление.

### Release и источники evidence

- Backend `origin/main` и production `/release.json` совпадают: `6035de84977cc21f95ff274e7122d02a64203d3b`.
- `/ready`: HTTP 200 с `{"ready":true,"phase":"phase_b","reason":null}`; admin: HTTP 200.
- Исходный отчёт: `C:\Users\kamilgaraev\Desktop\prohelper_full\ai-estimate-diagnostics-20260813-000018.txt` (158 518 байт, 3 061 строка); gzip-архив рядом.
- В отчёте нет исходного stack: Horizon показывает только RUNNING/FAIL, ledger — безопасный код. SSH ранее зависал на banner exchange; доступ перепроверяется только read-only.

### Подтверждённые дефекты текущего кода

- `ProductionDocumentUnitProcessor::process()` сохраняет previous exception, но catch-all сводит safe code к `document_geometry_processing_failed`; `ProcessDocumentUnit` передаёт recorder исключение, однако доступный ledger сохраняет только safe context.
- `processMeasured()` отправляет `PdfPage` в bounded raster path только при locator `content_type=image/png`; остальные PDF units идут в OCR processor. Это boundary для проверки manifest/locator contract.
- `ProcessDocumentUnit::handle()` всегда claim-ит до `MAX_ATTEMPTS=3`, fingerprint unit state строит из wrapper class + safe code, а затем rethrow; classification не управляет повтором.
- `ProcessEstimateGenerationUnitJob` имеет `tries=20`, timeout 1800/2100 и backoff, поэтому доменный limit и queue retry semantics расходятся.
- `EloquentDocumentUnitAggregateReconciler` после отсутствия blocking units выставляет `processing_stage=completed`, `progress_percent=100`; `processed_page_count` считает только страницы с текстом, а all-failed outcome через страницы превращается в `needs_review` без document error.
- Admin `pageFromUnit()` синтетически помечает каждый failed unit как `review.required=true` и кладёт internal `failure_code` в reasons; `DocumentDetailsPanel` отображает это постранично.
- Horizon production допускает до трёх тяжёлых unit workers по env/default, worker memory 512 MiB; на 8 GiB этот fan-out участвовал в каскаде.

---

## Блок 1. Root cause, geometry fix, retry и circuit breaker

### Зафиксированная причинная цепочка на 2026-08-13

- Deployed backend SHA `6035de84977cc21f95ff274e7122d02a64203d3b` совпадает с актуальным `origin/main` на момент начала расследования.
- Реальный `ar (1).pdf` успешно прошёл текущий `pdf_geometry_extract.py`: 22 страницы, 22 PNG preview, provider `pymupdf`, model `geometry_v1`, длительность 8841 ms. Это исключает повреждение PDF и сам geometry worker как первичный failing boundary.
- `PdfDocumentAdapter` формирует канонические associative arrays для `document_representation.capabilities` и `document_representation.resource_usage`.
- `CreateDocumentProcessingUnits` сохраняет locator в `estimate_generation_processing_units.locator`; колонка имеет PostgreSQL-тип `jsonb`, а Eloquent затем возвращает locator как array в `EloquentDocumentProcessingUnitStore::executionContext()`.
- Read-only `codex-tinker` для production unit `280` воспроизвёл точный boundary на сохранённом locator: `DocumentRepresentation::fromArray()` выбросил `InvalidArgumentException: Document capability contract is not canonical.` из `DocumentRepresentationCapabilities.php:27`. PostgreSQL `jsonb` вернул capability keys в порядке `vectors`, `text_spans`, `page_render`, `source_coordinates`, хотя набор и значения были корректны.
- После ручной канонизации только capabilities тот же production locator проявил второй order-sensitive дефект: `InvalidArgumentException: Document representation resource usage is invalid.` из `DocumentRepresentationResourceLimits.php:23`; production key order был `bytes`, `pages`, `objects`, `duration_ms`, `peak_memory_bytes`.
- Оба валидатора ошибочно требовали точный порядок ключей JSON object. Порядок полей JSON object не является persistence-контрактом; round-trip через PostgreSQL `jsonb` сохраняет набор и значения, но не входной порядок.
- `ProductionDocumentUnitProcessor::processRaster()` вызывает `DocumentRepresentation::fromArray()` до `VisionProvider::analyze()`. Нарушение ошибочного order-sensitive контракта выбрасывает `InvalidArgumentException` до платного AI-вызова.
- Общий `catch (Throwable)` оборачивает этот exception в `DocumentUnitProcessingException('document_geometry_processing_failed')`; `ProcessDocumentUnit` строит fingerprint только из wrapper class и safe code. Поэтому все страницы получают один fingerprint, а исходный boundary исчезает.
- Эта цепочка согласуется со всеми фактами инцидента: одинаковый быстрый отказ каждой страницы, `output_count=0`, отсутствие page-specific различий и 66 доменных запусков. OOM произошёл позже и является усилителем каскада, но не первопричиной.

Доказательная RED-регрессия `DocumentRepresentationJsonbRoundTripPostgresTest` прошла штатный PostgreSQL wrapper: до исправления она падала на capability order после двух предварительных assertions, после минимальной канонизации обеих карт проходит с `1 test, 5 assertions, 0 skipped`. DB-free matrix отдельно доказывает принятие переставленных ключей, возврат канонического порядка и сохранение fail-closed поведения для missing/extra/non-integer/negative values. Отдельная RED-регрессия должна зафиксировать typed safe code и сохранение безопасного previous-chain fingerprint вместо общего geometry code.

Read-only SSH evidence gate восстановлен. В `/var/www/prohelper/storage/logs/laravel.log` за `2026-08-12 20:47:00–20:52:00 UTC` подтверждены загрузка документа в `20:47:09` и 66 ошибок `document_geometry_processing_failed` с `20:47:32` по `20:51:25`; file logger сериализовал исходное исключение как `[Object]`, поэтому точный класс восстановлен безопасным production-shaped replay сохранённого locator через read-only `codex-tinker`. GlitchTip не является отдельным блокером при наличии этого evidence.

### Задача 1. Восстановить точный failing boundary

**Файлы:**

- Изучить: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php`.
- Изучить: manifest/locator builders и PDF OCR/geometry providers, найденные через call/data-flow graph.
- Изучить: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php`.
- Тест: `tests/Unit/EstimateGeneration/ProductionDocumentUnitProcessorTest.php` и наиболее узкий новый/существующий PDF adapter test.

- [ ] Read-only SSH: получить Laravel/container logs за `2026-08-12 20:47:00..20:52:00 UTC` по document 168, units 280–301, fingerprint и correlation IDs; не читать secrets и не выполнять writes.
- [ ] Локально проверить PDF metadata/representative pages 1, plan, façade/section и roof доступными бесплатными PDF tools; не обращаться к AI/S3/production DB.
- [ ] Проследить `document upload -> manifest -> locator -> unit context -> processor -> PDF provider` и записать одно проверяемое root-cause утверждение с точным class/message/boundary.
- [ ] Если stack невосстановим, сначала написать RED на безопасную structured observability: exception class, previous-chain fingerprint, boundary/adapter и fenced document/unit metadata без message/path/content/secrets.
- [ ] Запустить RED отдельно и подтвердить ожидаемый failure именно из-за потерянного evidence.
- [ ] Реализовать минимальную observability, повторить локальный unit/adapter и получить исходную причину до geometry fix.

### Задача 2. Исправить доказанную первичную ошибку

#### Обязательный architecture audit PDF → Vision

- Authoritative semantic reader страницы — мультимодальный AI, а не локальный text/vector/geometry extractor.
- Для каждой PDF page provider обязан получить канонический bounded full-page render исходной страницы без crop/потери визуального слоя, плюс доступные вспомогательные text/vector/metadata как необязательный evidence context.
- Audit должен проследить фактический payload от `PdfDocumentAdapter` и preview publisher через locator, `RasterPreprocessor`, `VisionDocumentInput` до provider: размеры, content type, source/derivative hash, transform, отсутствие crop и соответствие page index.
- Сбой или неканоничность только auxiliary text/vector/geometry extraction не блокирует Vision: страница деградирует в bounded full-page raster → AI, а unavailable/invalid auxiliary contract отражается в confidence/evidence/quality signals.
- До AI допускается fail-closed только если исходный PDF/render нельзя безопасно прочитать или отрисовать, нарушены integrity/tenant scope/size/pixel/resource limits либо невозможно сформировать канонический full-page raster. Provider unavailable остаётся recoverable и получает bounded retry.
- AI-derived geometry/locators не принимаются как точная истина: сервер проверяет schema, bounds, lineage и confidence, сохраняет evidence, а сомнительные факты переводит в точечный user review.
- Точные количественные вычисления выполняются детерминированно на сервере после AI-located facts/scale; локальный extractor и AI не подменяют этот слой расчётов.
- Обязательная RED-регрессия: vector/parser/auxiliary geometry failure при валидном full-page render всё равно вызывает Vision ровно один раз с полной страницей и создаёт output с корректной lineage. Отдельные RED должны доказать, что corrupt/unsafe render и integrity/limit violation не вызывают AI.

#### Результат architecture audit на 2026-08-13

- `pdf_geometry_extract.py::legacy()` создаёт preview каждой страницы через `pypdfium2.PdfPage.render(scale=2)` без `clip`; размеры рассчитываются по видимому `crop_box` с учётом rotation, проверяются per-page/aggregate pixel и byte limits. Реальный 22-page файл дал 22 preview и не потерял страницу на vector extraction.
- `PdfDocumentAdapter` публикует PNG preview как `artifact_path` PDF-unit с hash/bytes/source-version lineage; `ProductionDocumentUnitProcessor::processMeasured()` направляет такой `PdfPage + image/png` в raster/vision path.
- `RasterPreprocessor` не crop-ит PDF page и использует aspect-preserving `scaleDown`; до исправления он безусловно переводил render в grayscale, поэтому визуальный цветовой слой терялся. Новый PDF-specific `preserveColor` сохраняет полный цветной render, flatten-ит alpha на белый фон и оставляет bounded scale/hash/integrity checks.
- `ProductionDocumentUnitProcessor` читает сохранённый derivative по точным bytes/hash и создаёт `VisionDocumentInput` с полными PNG bytes, `detail=high`, page/unit/source lineage и transform. `TimewebVisionProvider::requestPayload()` передаёт эти bytes как `data:image/png;base64,...` в единственном primary `image_url`.
- Auxiliary `document_representation`/geometry теперь не является blocking semantic reader: invalid/missing vector contract отмечается как unavailable, после чего full-page raster всё равно один раз уходит Vision. Canonical source mismatch и небезопасный/нечитаемый render остаются fail-closed.
- В provider prompt вместе с изображением передаются bounded native PDF text (до 12 000 символов), capability/extraction statuses, source bounds и native reference registry. Локальная geometry не заменяет визуальную интерпретацию AI.
- Ответ Vision проходит schema/provenance/evidence validation, polygon mapping через обратимый transform и confidence/warning gates; output отдельно маркирует `evidence_source=vision`. Дальнейшие quantity takeoff остаются серверными детерминированными расчётами по validated facts/scale.
- RED `invalid_auxiliary_pdf_geometry_degrades_to_full_page_vision` доказывает один Vision-call, полный render `12x8`, PDF lineage и unavailable vector status. RED preprocessor различает красную/синюю половины и сохраняет `120x80`; provider RED проверяет совместную передачу image, native text и auxiliary metadata.

**Файлы:** точный adapter/provider/manifest class из задачи 1; `ProductionDocumentUnitProcessor.php`; соответствующие unit/integration tests.

- [ ] Написать минимальный RED на установленную причину с реальным fixture/репрезентативным PDF fragment; ожидание — один matching output и ready page lineage.
- [ ] Исправить источник неверного locator/geometry/serialization/provider contract, не доверяя расширению файла.
- [ ] Для допустимой auxiliary vector/text/geometry failure выполнить bounded full-page raster/vision degradation; unsafe/corrupt source или render завершать fail-closed точным typed terminal code.
- [ ] Проверить representative pages и полный локальный 22-page adapter pass без production DB, paid AI и fake S3.
- [ ] Доказать `output_count=1`, page `ready`, совпадающие `unit_id`, `unit_index`, `source_version`, provenance и canonical representation.

### Задача 3. Классифицировать ошибки и остановить повторы

**Файлы:**

- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php`.
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentProcessingUnitStore.php` и его interface/records только если контракт требует.
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationUnitJob.php`.
- Reuse: существующие `FailureCategory`, `TypedFailureException`, failure recorder/ledger.
- Tests: `tests/Unit/EstimateGeneration/DocumentProcessingUnitContractTest.php` и новый/расширенный PostgreSQL contract test в `tests/Feature/EstimateGeneration`.

- [ ] RED: deterministic terminal выполняется один раз; recoverable transient получает bounded retry/backoff; user-action-required не маскируется system failure.
- [ ] Ввести единый resolver категории/code/fingerprint по полной previous chain; public/safe contracts не содержат raw exception message.
- [ ] Согласовать domain claim и queue job: terminal outcome не rethrow для повторного выполнения, transient сохраняет retry, stale/busy остаются идемпотентными.
- [ ] RED PostgreSQL: конкурентные claims одного unit не создают два физических выполнения и сохраняют точный attempt count.

### Задача 4. Document-scoped fail-fast/circuit breaker

**Контракт:** scope строго `organization_id + project_id + session_id + document_id + source_version`; порог — первые 3 terminal system failures с одним нормализованным fingerprint. Разные page-specific fingerprints и user-review не открывают breaker. Новая source version/явный разрешённый retry начинает новый fenced цикл.

**Файлы:** focused service в `Application/Documents` (например, `DocumentUnitFailureCircuitBreaker.php` + Eloquent implementation/store extension); `ProcessDocumentUnit.php`; recovery/dispatch paths; PostgreSQL tests.

- [x] RED PostgreSQL: три конкурентных одинаковых system failures атомарно открывают breaker только один раз. Три отдельных PHP-процесса одновременно фиксируют failures; PostgreSQL wrapper прошёл `2 tests, 13 assertions, 0 skipped`, pending units остановлены только в точном document/source scope.
- [ ] RED: оставшиеся pending/processing-with-expired-lease units завершаются безопасным shared terminal code без processor/provider invocation; уже published unit не перезаписывается.
- [ ] RED: разные fingerprints, меньше порога, другой tenant/document/source version не открывают breaker.
- [ ] Реализовать через существующие units/ledger и PostgreSQL row locks/conditional updates; новую таблицу не создавать без доказанной невозможности.
- [ ] RED recovery/replay: explicit retry/new source version не зацикливает breaker и не повторяет provider без нового основания.

### Gate блока 1

- [ ] Targeted DB-free PHPUnit: processor, failure classification, job semantics, recovery.
- [ ] Реальный PostgreSQL wrapper: claim race, breaker race, stale source/replay; `0 skipped` для новых tests.
- [ ] `php -l`, Pint изменённых PHP, минимальный Larastan без фиктивной storage-конфигурации, UTF-8 и `git diff --check`.
- [ ] Один read-only субагент проверяет только завершённый diff блока на correctness/concurrency/security. Подтверждённые замечания исправляет основной агент, затем повторяются только затронутые tests.
- [ ] Первый содержательный backend-коммит включает этот план и block-1 changes; отдельного docs-only релиза нет.

---

## Блок 2. Canonical state, admin UX и resource safety

### Задача 5. Канонический document outcome

**Файлы:**

- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php`.
- Modify: document presentation/resource и translation keys `lang/ru/estimate_generation.php` (точное имя проверить по существующему contract).
- Tests: `DocumentProcessingUnitContractTest`, document resource/presentation tests, отдельный PostgreSQL reconciler contract test.

- [ ] RED matrix: all success, partial success, `0/22` system failure, mixed user review, stale source version, replay/recovery.
- [ ] Разделить workflow completion (`processing_stage=completed`, `progress_percent=100`) и outcome (`ready`, actionable review, system failure).
- [ ] `processed_page_count` считать по фактически ready/usable current-source pages; page/unit/document summary должны сходиться.
- [ ] При all/systemic failure выставлять document `error_code` и `error_message_key`; не использовать `needs_review`, если пользователь не может исправить вход.
- [ ] При partial/user-review сохранять точные page actions; system failures не разрешать массовым retry автоматически.
- [ ] PostgreSQL race: reconcile и late unit result не создают impossible state; stale source не меняет current document.

### Задача 6. Безопасный API contract и admin UX

**Backend files:** document detail resource/presenter и `lang/ru/estimate_generation.php`.

**Admin files:**

- Modify: `src/features/estimate-generation/api/estimateGenerationContracts.ts`.
- Modify: `src/features/estimate-generation/api/estimateGenerationDocumentNormalizers.ts`.
- Modify: `src/features/estimate-generation/documents/DocumentDetailsPanel.tsx` и его CSS/theme composition при необходимости.
- Modify: `src/features/estimate-generation/steps/DocumentsStep.tsx` только для summary counts/actions.
- Modify: `src/features/estimate-generation/test/handlers.ts` и fixtures.
- Tests: normalizer + `DocumentDetailsPanel`/DocumentsStep Vitest with MSW.

- [ ] Backend RED: detail contract отдаёт безопасный `processing_outcome`/summary с counts `included`, `needs_user_action`, `system_failed`, `processing`; internal exception/stack отсутствуют.
- [ ] Admin RED normalizer: полный runtime payload нормализуется типобезопасно; missing optional fields имеют безопасный backward-compatible default.
- [ ] UI RED/MSW: 22 одинаковых system failures дают одну document-level причину, `system_failed=22`, `processing=0`, без 22 красных review cards и без массового retry.
- [ ] UI RED: transient сообщает о временной невозможности и допустимом повторе; файл сохранён, повторная загрузка не требуется.
- [ ] UI RED: page-specific user review сохраняет точечные действия; mixed/partial и завершившийся polling не оставляют «2 страницы в обработке».
- [ ] Все строки брать из существующей frontend i18n/translation системы; codes/exception/stack/fallback/legacy/payload не показывать.

### Задача 7. Resource safety и metrics

**Файлы:** `config/horizon.php`, существующие deploy/docker env examples/compose templates только там, где уже задаётся `ESTIMATE_GENERATION_UNITS_MAX_PROCESSES`; existing observability/resource measurement classes/tests.

- [ ] Сопоставить фактический host/process RSS из incident report и успешный локальный adapter peak; не принимать 12 GiB за root fix.
- [ ] RED config/contract: production heavy page-unit concurrency по умолчанию ограничена до 1 (до измеренного canary); env override остаётся bounded безопасным диапазоном, recovery не добавляет параллельное тяжёлое выполнение.
- [ ] Проверить worker memory/timeout против measured peak; не повышать без evidence и не добавлять swap.
- [ ] Добавить per-unit duration/peak memory/outcome/fingerprint category в существующую observability; document aggregate вычислять без новой системы.
- [ ] Отдельно зафиксировать влияние OnlyOffice/ClamAV, не переносить их в этой задаче.

### Gate блока 2

- [ ] Backend DB-free PHPUnit + PostgreSQL outcome/reconcile races, `0 skipped` новых scenarios; Stage 3–7 targeted regression.
- [ ] Backend `php -l`, Pint changed files, минимальный Larastan, UTF-8, `git diff --check`.
- [ ] Admin targeted Vitest+MSW, `npx tsc --noEmit`, ESLint/Prettier changed files, UTF-8, `git diff --check`; build не запускать.
- [ ] Один read-only субагент проверяет завершённый diff блока на canonical state/API/UX/resource safety. Исправления и минимальный rerun выполняет основной агент.

---

## Блок 3. Единый release gate и штатный выпуск

### Задача 8. Финальная проверка и выпуск

- [ ] Собственный независимый correctness/security/UX review полного backend/admin diff: tenant fencing, source-version fencing, locks/transactions, retries, paid-provider suppression, safe messages, responsive/loading/error/empty states.
- [ ] Повторить только затронутые проверки после найденных исправлений; не запускать дублирующие длинные наборы.
- [ ] Проверить чистоту worktree, `git diff --check`, UTF-8, divergence и semantic overlap со свежим `origin/main`.
- [ ] Коммиты на русском Conventional Commits: backend с уместным scope проекта, admin обязательно `fix[admin]: ...`.
- [ ] Push feature branches; создать backend/admin PR; дождаться штатных checks через repository `ci_monitor.cjs`; baseline failures не скрывать.
- [ ] Merge штатно; deploy backend/admin только существующими workflows. CI/secrets/environments не менять; `migrate:safe` только workflow и только если schema change оказался неизбежен.

### Задача 9. Read-only canary и handoff

- [ ] Проверить `/ready`, `/release.json`, admin HTTP, защищённые API routes с ожидаемым `401` без auth.
- [ ] Read-only SSH: свежие Laravel/Horizon/ClamAV/OOM errors и deployed config/SHA; никаких writes/restarts/cache clear/docker control.
- [ ] Не запускать production PDF и paid AI.
- [ ] Подготовить mapping `проблема -> root cause -> исправление -> доказательная регрессия`, resource measurements, PR/merge/deploy SHA, остаточные риски.
- [ ] На основании measured successful peak дать решение: одиночный контролируемый тест на 12 GiB либо до продаж перенести существующие AI-worker containers на второй обычный Docker host.
- [ ] Передать пользователю один контролируемый post-release тест: повторить сохранённый документ без повторной загрузки, наблюдать counts/outcome/resources; выполнять только после явного разрешения пользователя.

## Критерии завершения

- Конкретная первопричина исходного `document_geometry_processing_failed` доказана stack/reproduction/RED.
- Нормальный 22-page PDF не создаёт 66 одинаковых попыток; deterministic systemic failure открывает document breaker, transient остаётся bounded-recoverable.
- После document-wide failure нет последующей платной AI-работы.
- DB/API/UI согласованно показывают честный outcome и полезную русскую причину.
- Одна загрузка не может положить host при штатном concurrency limit; новой инфраструктуры нет.
- Новые PostgreSQL gates реально прошли без skip.
- Backend/admin выпущены стандартно или зафиксирован конкретный release blocker с evidence.
