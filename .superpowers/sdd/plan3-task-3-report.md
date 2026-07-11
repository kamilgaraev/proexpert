# Plan 3 / Task 3 — raster preprocessing и vision provider

## Статус

DONE. Реализован отдельный production-контур raster/vision AI-сметчика МОСТ без подключения к обычным сметам и без изменения shared AI Assistant.

## Raster library и API

- Перед реализацией через Context7 проверена закреплённая зависимость `intervention/image` `3.11.6`, драйвер GD.
- Локальный source установленной версии дополнительно подтвердил фактические API: `ImageManager::withDriver(GdDriver::class, autoOrientation: false)`, `greyscale()`, `contrast()`, `scaleDown()`, `toPng()`.
- Предварительный brief содержал неточные имена `usingDriver()` и `grayscale()`; они не существуют в установленной `3.11.6`, поэтому использованы фактические API exact locked version.
- Новые raster/CAD зависимости не добавлялись.

## Preprocessing

- Source допускается только из tenant-prefixed S3 key `org-{organization}/...`; path traversal и cross-tenant key отклоняются.
- До decode проверяются source SHA-256 version, размер, magic/MIME, dimensions, pixel budget и animation markers APNG/WebP.
- EXIF orientations 1–8 разбираются bounded TIFF parser без зависимости от отсутствующего в runtime `ext-exif`; auto-orientation библиотеки отключена, операция применяется ровно один раз.
- Pipeline: orientation → bounded pre-scale → real projective rectification → greyscale/contrast → final aspect-preserving scale → deterministic PNG re-encode.
- Perspective correction использует решённую 8-параметрическую homography 3×3 и inverse. Singular, repeated, self-crossing, non-convex и out-of-bounds quadrilateral fail closed. PHP resampling ограничен 4 млн output pixels.
- При требуемой перспективе без trusted corners возвращается `perspective_confirmation_required`; corrected status не выставляется.
- Composed source↔derivative matrix хранит determinant/condition и используется provider для возврата polygons в source space.
- Quality result содержит dimensions, sharpness proxy, dynamic range, blank/clipping ratios, skew, perspective status, warnings, hash/version.
- Derivative сохраняется через `FileService` private, content-addressed и immutable по пути `org-{organization}/estimate-generation/{session}/vision/v1/{sha256}.png`; existing key проверяется по hash, tamper/collision отклоняется.
- Локальные постоянные файлы не создаются; metadata удаляется re-encode.

## Vision contract

- `VisionProvider::analyze(VisionDocumentInput): VisionAnalysisData` и real binding `TimewebVisionProvider`.
- Input связывает tenant/project/session/document/page, exact checkpoint operation context, source/derivative hashes, magic-validated raster bytes и composed transform.
- Closed schema: sheet type, element type, label, warnings, scale source/detail, evidence locator; unknown/missing keys fail closed независимо от порядка JSON keys.
- Polygons finite и normalized `[0,1]`, bounded, non-degenerate/simple, без repeated points; stable element/evidence keys и dangling/duplicate evidence guards.
- Provider polygons переводятся из derivative space обратно в source space до возврата DTO.
- Scale candidates остаются candidates; несколько значений требуют `scale_conflict`, автоматического confirmation нет.
- Provider/requested/reported model/model version/usage status explicit; model mismatch, non-`stop` finish, partial/markdown/bad JSON/oversized/deep/unbounded output — malformed response.
- Fixed system instruction запрещает следовать embedded image instructions; prompt/body/image/path/filename не попадают в usage/report/log.

## Timeweb wire и usage

- Использован существующий repository endpoint `/chat/completions`; endpoint/request syntax не менялись.
- Нет `Http::retry` и model fallback. Явный цикл повторяет только connection/408/429/5xx, terminal 400/401/403/422 не повторяются.
- Каждая physical request получает отдельный attempt UUID, сохраняет исходную correlation/checkpoint scope, HTTP/status/duration/model/image_count/detail/tokens.
- Отдельные provider invocations не дедуплицируют реальные wire calls; retries внутри invocation имеют distinct IDs.
- Usage absence остаётся `unavailable` с unknown cost. Полный env-backed pricing snapshot включает обязательный image tariff; неполный/невалидный snapshot деградирует только в unavailable pricing и не теряет usage row.
- Recorder/pricing logging failure не маскирует provider success/error; лог содержит только exception class.

## Дополнительный privacy gate

- Расширенный regression обнаружил сырой `Throwable::getMessage()` в Plan 3 Task 1 benchmark runner/command.
- Добавлены typed `BenchmarkManifestException`, `BenchmarkContractException`, `BenchmarkCommandException` с закрытым `reason`; raw throwable diagnostics больше не читаются и не попадают в report/console.

## TDD и проверки

- RED сначала зафиксировал отсутствие Vision contracts/preprocessor/provider.
- Focused Vision: `22 tests / 88 assertions`.
- Combined Benchmark + Vision + Plan 2 Observability + BuildingModel + benchmark command: `220 tests / 1283 assertions`, PASS.
- Проверены EXIF 1–8 на уровне matrix round-trip и фактического raster pixel, perspective/round-trip, transparency, deterministic hash, tamper, animation, invalid magic/MIME, pixel bomb, quality warnings, strict DTO/schema matrix, source mapping, retry/status matrix, usage unknown/pricing/recorder/no-fallback.
- Migrations/DB-команды не запускались; тесты DB-less.
- Larastan touched scope: PASS, no errors. Pint: PASS, 25 files. `php -l`: 25 files PASS. `git diff --check`: PASS.

## Corrective review cycle

- S3 source/derivative reads больше не используют unbounded `get()`: metadata `size()` проверяется до `readStream()`, поток читается chunks не более `max+1`, фактическая длина сверяется с metadata. Spy-тесты доказывают отсутствие content read при oversized metadata и отсутствие `get()`.
- Alpha и semi-transparent pixels детерминированно компонуются на белый opaque canvas до grayscale/contrast; derivative проверен с alpha `0` и реальным RGB composition.
- По source установленного Laravel/Guzzle подтверждены `withOptions(['stream' => true])`, PSR-7 body/resource APIs. Non-2xx классифицируется и stream закрывается без чтения body; bounded body reader вызывается только для 2xx. Oversized/malformed 429/503 остаются retryable HTTP attempts.
- Prompt `vision-contract:v1` теперь полностью перечисляет schema version, exact fields, effective element cap, closed enums, geometry/confidence/provenance/scale invariants. Canonical system+user-template hash вычисляется из эффективного validated лимита `1..500`, передаётся в запрос, входит в identity usage-attempt и возвращаемую model-version provenance; stale hardcoded hash отсутствует.
- Provenance включает page id/number, processing unit, source version и coordinate space. Provider обязан точно вернуть derivative locator; после polygon mapping locator становится `normalized_source_v1`. Mismatch, unknown keys и null bypass fail closed.
- Scale policy: zero candidates ↔ `scale_missing`; materially distinct values свыше `max(1e-9, 2%)` ↔ `scale_conflict`; один и near-equal candidates запрещают ложный conflict.
- PNG chunk walker валидирует signature/chunk length/CRC/order/IEND и APNG chunks; WebP walker валидирует RIFF/chunks и animation flags/ANIM/ANMF; GIF parser структурно считает image descriptors/sub-blocks. Trailing data и malformed containers не принимаются.
- Behavioral raster proof включает colored trapezoid/grid pixels/corners/aspect, EXIF+pre-scale+perspective+final-scale round trip, blank/blur/low-contrast fixtures и no-op rejection.
- Near-production projective case: 2200×1800 = 3.96M output pixels, 13.34s isolated, PHPUnit peak 152MB; hard cap сохранён на 4,000,000 pixels.
- `RasterPreprocessResult` закрывает status/warnings/hash/path/version/dimensions/finite metrics/skew invariants.
- Corrective focused gate: `41 tests / 156 assertions`, PASS. Combined Benchmark/Vision/Observability/BuildingModel/command: `239 tests / 1355 assertions`, PASS.
- Corrective final gates: Larastan touched scope PASS, Pint 26 files PASS, `php -l` 26 files PASS, privacy scan and `git diff --check` PASS.

## Final narrow review cycle

- `max_elements` больше не clamp-ится молча: значения вне `1..500` отклоняются до wire call. Эффективные значения `1`, `100`, `500` дословно отображаются в system contract, меняют prompt hash и тем же значением ограничивают runtime parser.
- Двухточечная геометрия разрешена только для `dimension`, `axis`, `engineering_element`, `text`, требует distinct endpoints и ненулевую длину. `room`, `wall`, `opening` требуют ring из 3+ точек; любой ring обязан иметь ненулевую площадь и быть simple.
- Prompt и runtime используют одну формулу scale conflict: `abs(a-b) > max(1e-9, 0.02 * min(a,b))`; `meters_per_unit` ограничен `(0, 1_000_000]`.
- Final narrow gate: `48 tests / 175 assertions` PASS; Larastan no errors; Pint 27 files PASS; `php -l` 26 files PASS; `git diff --check` PASS.
