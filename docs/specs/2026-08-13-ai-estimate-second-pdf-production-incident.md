# Второй production-инцидент обработки PDF AI-сметчиком МОСТ

## Цель

Доказать точную причину одинакового отказа всех 22 страниц документа 168 после явного повтора, исправить только подтверждённый дефект до AI boundary, восстановить безопасную диагностику, document-scoped circuit breaker и terminal lifecycle явного повтора, выпустить штатно и остановиться до следующего production retry.

## Подтверждённые факты

- Scope: organization 38, project 52, session 66, document 168, 22 страницы.
- Explicit retry принят 2026-08-13 01:42:33 UTC; lineage `d173fcc2-5f5c-44b1-91f1-94034f1b0bb5`.
- Все 22 unit физически исполнились один раз и завершились terminal failure с кодом `document_unit_processing_failed` и fingerprint `c5c07e93136d7913eb44102a8ac2d0ce08c11bb41d3c01d82411f9c5ab0d3dd4`.
- Vision physical attempts и AI usage после 01:42 UTC отсутствуют: ошибка находится до provider wire boundary.
- Resource measurements исключают OOM; host после прогона стабилен.
- Failure ledger сохранил только общий safe code, поэтому точный previous-chain нельзя восстановить из текущей persistence.
- Document завершился как `needs_review`, а `explicit_document_retry.status` остался `processing` — состояния противоречат друг другу.
- Локальный исходный PDF корректно проходит реальный PyMuPDF worker: 22 full-page PNG render, 2382×1684 каждая страница.
- Fingerprint `c5c07e…` точно соответствует цепочке `DocumentUnitProcessingException → QueryException → PDOException` на `SheetAnalysisOperationJournal::ensure()`.
- Production-колонка `estimate_generation_sheet_analysis_operations.source_version` имеет `CHAR(64)`, а канонический source version `sha256:` + 64 hex имеет длину 71; PostgreSQL отклоняет INSERT с `22001` до Vision.
- После расширения колонки тот же реальный journal path выявляет второй скрытый schema-contract дефект: пустые PHP-массивы сериализуются как JSON arrays, тогда как constraint требует JSON objects. Канонические object-shaped pending payloads устраняют следующий pre-provider отказ.

## Root-cause gate

Исполняемый root-cause fix запрещён до одновременного выполнения условий:

1. production path воспроизведён на локальном `ar (1).pdf` с реальными adapter/representation/raster/processor компонентами;
2. S3 boundary заменён только детерминированным fixture reader;
3. Vision provider заменён spy без HTTP;
4. получена безопасная class chain без message/path/content;
5. SHA-256 от class chain и `document_unit_processing_failed` равен `c5c07e…`;
6. указан конкретный метод и выражение, которое выбрасывает root exception;
7. следующий statement после устранения первого blocker также выполняется на актуальной PostgreSQL-схеме, чтобы не оставить каскадно скрытый pre-provider дефект.

Если fingerprint не совпадает, гипотеза отвергается и код не исправляется.

## Архитектурный контракт PDF → Vision

Канонический вход AI — bounded full-page render каждой страницы. Text layer, vectors, geometry и metadata являются вспомогательными источниками и не вправе блокировать Vision при локальной ошибке контракта или парсинга. В этом случае processor продолжает с raster fallback и отмечает auxiliary source как unavailable.

Fail-closed сохраняется для tenant/source-version mismatch, неподтверждённой целостности исходного render, security violations, corrupt source и hard resource limits. Provider HTTP локально и в тестах не вызывается.

## Безопасная диагностика

Для одной canonical failure chain сохраняются только:

- allowlisted class slug верхнего исключения;
- fingerprint последовательности previous-классов;
- execution boundary;
- deterministic diagnostic fingerprint;
- закрытый typed context из scalar enum/count values.

Запрещены message, stack, пути, имя документа, содержимое, prompt/response, URL, credentials, idempotency key и токены. Разные sensitive messages одного класса дают одинаковый diagnostic fingerprint; разные root classes — разные fingerprints. Ledger и GlitchTip не создают дублирующие canonical события.

## Circuit breaker

Threshold равен 3 фактическим исполнениям одной unexpected terminal system cause. Атомарная scope identity включает tenant, project, session, document, source version, explicit attempt lineage и root diagnostic fingerprint, но исключает page/unit identity.

После третьего committed failure pending units той же scope переводятся в terminal systemic без processor/provider call. Уже running units не переписываются. Другие tenant/document/source/lineage не затрагиваются. Новый explicit retry получает чистую scope identity.

## Terminal lifecycle явного повтора

Retry audit entry является отдельной durable operation. При terminal reconciliation операция переходит из `processing` в существующий канонический terminal status, получает `completed_at`, честные execution/system-failure counts и безопасный root fingerprint/reason.

Exact replay исходного idempotency key возвращает сохранённый terminal result без dispatch. Новый retry создаётся только по backend capability/policy с новым key и lineage. Document, session, readiness и capability получают один согласованный snapshot.

## API и UX

Document-wide system failure отображается одной понятной причиной. Постраничные retry/action-required карточки для systemic failure отсутствуют. Counts различают фактические исполнения и units, остановленные breaker; внутреннее `attempt_count=MAX_ATTEMPTS` не выдаётся как число фактических запусков.

Текст не сообщает «требуется ваше решение» и не предлагает page retry. Кнопка document retry строится только по backend capability. Admin меняется только если текущий canonical payload недостаточен или существующая нормализация нарушает контракт.

## Проверки

- DB-free RED→GREEN на реальном PDF или минимальном extracted fixture и deterministic Vision spy.
- Все 22 full-page inputs проходят bounded harness либо subset доказывает root cause, а отдельный 22-page boundary test доказывает полноту.
- Safe diagnostic fingerprint regression.
- Реальный isolated PostgreSQL wrapper: concurrency threshold, running-unit preservation, cross-scope isolation, terminal retry lifecycle и replay; новые сценарии — 0 skip.
- Регрессии первого инцидента: JSONB key order, auxiliary fallback, terminal без трёх исполнений, canonical outcome/counts, explicit retry ABAC/idempotency.
- `php -l`, Pint, минимальный Larastan/PHPStan, UTF-8 и `git diff --check`.
- Admin при доказанной необходимости: Vitest/MSW, `tsc --noEmit`, ESLint/Prettier changed files; build не запускается.

## Выпуск и stop condition

Backend и, только при необходимости, admin проходят один независимый read-only review, PR, squash merge и существующие deploy workflows. Canary ограничен `/ready`, release metadata, ожидаемым 401 защищённого endpoint, свежими GlitchTip/logs и read-only snapshot документа 168.

Production retry документа 168 и любой платный AI-вызов запрещены без нового явного разрешения пользователя.
