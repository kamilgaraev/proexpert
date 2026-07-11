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
