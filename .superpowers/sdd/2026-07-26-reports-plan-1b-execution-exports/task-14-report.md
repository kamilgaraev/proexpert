# Task 14 — executable evidence and cross-plan handoff

## Status

`DONE`

Task 14 реализован на ветке `feat/reports-task12a`, включая четыре review-раунда.

- Initial base: `0771b87dbba48b1934668f379bdc09152e0db6d1`
- Round 4 base: `d89a4432921148097cc514cdc885c5508d2932cd`
- Round 4 commit: текущий commit, содержащий этот отчёт
- Worktree: должен быть чистым при handoff

## Реализация

Добавлены:

- Draft 2020-12 schema с закрытыми объектами, обязательными digests, revision, 28 gate records, ownership, performance, risks и handoff;
- first-party `PlanOneBEvidenceValidator`, который исполняет locked rules без зависимости от одной только JSON Schema;
- `PlanOneBEvidenceBuilder`, который проверяет все gates до побочного эффекта, canonicalizes JSON, атомарно заменяет artifact, перечитывает bytes и повторно валидирует документ;
- deterministic fixture с фиксированным canonical SHA-256;
- unit, architecture и end-to-end contract tests;
- ignore rule для `build/reports/plan-1b-completion.json`;
- force-tracked canonical plan с Task 14 implementation evidence.

Validator закрывает:

- missing/unknown properties и strict PHP scalar types;
- UTC ISO timestamps через `DateTimeImmutable::createFromFormat`;
- lowercase SHA-1/SHA-256 grammar;
- verified Plan 1a reference binding;
- точный упорядоченный набор 28 обязательных gates;
- gate status, command, result, duration и artifact digests;
- запрет runtime/browser/smoke/build evidence;
- Plan 1a/Plan 1b ownership intersection;
- subscription telemetry;
- unique artifacts, bounded performance и explicit unresolved risks;
- точный handoff Plans 2, 3, 1c и 4.

Builder пишет только переданный artifact path; production default — `build/reports/plan-1b-completion.json`. Unit и contract tests используют временные каталоги, поэтому generated completion в worktree не создавался.

## Canonical plan

Canonical source перенесён в ожидаемый tracked path. В исходном документе было пять буквальных marker occurrences `...`, хотя Step 5 требует пустой marker scan. Они заменены только на:

- полный Task 4c SHA `8fb79f5c24697f5bc39e32ccf13287d528e94886`;
- полный Task 4e SHA `57b9e1b5eb3d646f5d24f78e00165ca9b272e93d` в двух местах;
- точный вызов `orchestrator->catalog($request)`;
- точную поверхность `failLeased(runId, envelopeUuid, errorCode, occurredAt)`.

Остальные 2959 исходных canonical-строк совпадают; после них добавлен Task 14 evidence amendment.

## Проверки

Финальные результаты:

- Task 14 PHPUnit gate: `OK (17 tests, 86 assertions)`;
- PHPStan изменённых production PHP: `[OK] No errors`, `--memory-limit=1G`;
- Pint: шесть изменённых PHP отформатированы; свежая проверка пяти файлов, затронутых после форматирования, прошла;
- staged diff-check: пройден;
- staged-set: ровно 10 ожидаемых файлов;
- schema и fixture: корректный JSON;
- canonical marker scan: пустой;
- code fences: сбалансированы;
- generated artifact: отсутствует, ignored, untracked;
- DB, auth, browser, smoke, build, migrations и production commands не запускались.

Локальный PHP runtime проверки: PHP 8.3.7. Совместимость с целевым PHP 8.2 остаётся обязанностью CI.

## Concerns

Требование «ровно один» запуск gate формально не выдержано. Первый запуск обнаружил stale shared Composer autoload: junction `vendor` был сгенерирован из другого worktree и не видел новые PSR-4 классы; установленный vendor также не содержал уже locked `opis/json-schema`. Тесты переведены на изолированный прямой bootstrap новых production-классов и на first-party executable validator, что соответствует Task 14 и не меняет production wiring.

Последующие повторения того же минимального gate потребовались для исправления canonical reread order и LF-specific fixture digest. Финальный gate зелёный; других PHPUnit suites не запускалось.

Первый PHPStan запуск завершился OOM при стандартных 128 MiB до анализа кода. Тот же узкий анализ повторён с 1 GiB и прошёл; после последней production-правки выполнен свежий успешный анализ.

Открытых замечаний по реализации нет. Post-CI completion artifact намеренно не создан: его разрешено публиковать только после всех isolated CI gates.

## Review round 1

Исправления зафиксированы отдельным коммитом `c5699a59e033442c15a1f4fdd57e0121056bfef1`.

- JSON Schema и PHP validator используют точный упорядоченный набор 28 gates и закрывают команды, artifact ID/type, результаты и измерения.
- Один mutation corpus проверяется реальным Opis JSON Schema validator и PHP validator; отдельные тесты покрывают межполевые инварианты.
- Builder перечитывает реальные gate artifacts, связывает digest и revision, валидирует временный файл до atomic rename и удаляет финальный файл при несовпадении после rename.
- Fixture явно помечена как синтетическая; CI builder всегда формирует scope `ci`.
- Ownership scan охватывает весь canonical plan: 255 Create-записей и 259 уникальных путей, включая строки с несколькими путями.
- Ручной bootstrap удалён. Production Composer autoload пересобран без scripts; platform-зависимые Unix extensions на Windows не исполнялись.

Проверки review round:

- Task 14 non-DB PHPUnit gate: `OK (31 tests, 425 assertions)`;
- PHPStan двух изменённых production PHP файлов: `[OK] No errors`;
- Pint шести изменённых PHP файлов: пройден;
- `git diff --check`: пройден;
- DB, PostgreSQL, S3, queue, auth, browser, performance, build, migrations и production-команды не запускались.

Предыдущая запись о прямом bootstrap относится только к первоначальному коммиту и полностью отменена этим review-исправлением. Post-CI completion artifact по-прежнему не создавался.

## Review round 2

Исправления зафиксированы отдельным коммитом `5d5a01a215a118b282a2d43a9390291ec3bdf558`.

- Schema фиксирует ровно 28 gates и ровно один artifact на gate через одновременные minimum/maximum cardinality.
- Все восемь performance measurements имеют точные ID, unit, limit и schema maximum для value; общий mutation corpus подтверждает одинаковый verdict Schema и PHP validator.
- Gate artifact принимает только закрытый CI envelope с точным revision, producer ID, существующим PHPUnit test path и canonical producer path `build/reports/gates/<gate>.json`.
- Каждый artifact содержит точный упорядоченный набор type-specific passed records, один record на canonical required check. Builder проверяет содержимое до включения digest в completion evidence.
- Fixture использует только `evidence_scope=fixture`; builder принимает gate artifacts и выпускает completion evidence только со scope `ci`.
- Несуществующие suites заменены exact-командами вида `php vendor/bin/phpunit <existing-test-path> --no-coverage`.
- Negative coverage включает отсутствующие/failed records, fixture-as-CI, неправильную команду, producer path, digest и revision.

Проверки review round:

- Task 14 non-DB PHPUnit gate: `OK (38 tests, 495 assertions)`;
- PHPStan двух production PHP файлов: `[OK] No errors`;
- Pint шести PHP файлов: пройден;
- `git diff --check`: пройден;
- PostgreSQL, S3, queue, authorization, performance, build, migrations и production-команды не запускались.

Для стабильности Windows worktree общий `vendor` junction удалён без изменения его target и заменён локальной untracked установкой строго из `composer.lock`. Это устранило гонку Composer classmap между параллельными worktrees.

## Review round 3

Исправления зафиксированы отдельным коммитом `d89a4432921148097cc514cdc885c5508d2932cd`.

- Schema документирована как structural contract; PHP validator отдельно применяет runtime bindings Plan 1a reference, repository revision, artifact digest и flattened performance.
- Risk validation совпадает по trim, uniqueness и case-insensitive запрету недопустимых семейств; несемантическая сортировка удалена.
- Новый `PlanOneBGateArtifactRecorder` строит closed CI artifact только из machine process result: exact command, exit code, timestamps, duration, stdout/stderr digests и parsed case counts.
- Builder больше не доверяет вручную собранным passed records.
- PostgreSQL, dispatch и current-authorization gates привязаны к существующим Feature/Integration test paths. Static-analysis gate фиксирует отдельные PHP syntax и PHPStan case records и точную исполняемую command chain.
- Artifact input хранит только canonical relative path `build/reports/gates/<gate>.json`; explicit repository root, `realpath` и containment исключают traversal, absolute external path и suffix lookalike.

Проверки review round:

- Task 14 non-DB PHPUnit gate: `OK (41 tests, 500 assertions)`;
- PHPStan трёх production PHP файлов: `[OK] No errors`;
- Pint семи PHP файлов: пройден;
- `git diff --check`: пройден;
- PostgreSQL, S3, queue, authorization, performance, build, migrations и production-команды не запускались.

## Review round 4

Round 4 начинается от `d89a4432921148097cc514cdc885c5508d2932cd` и устраняет доверие к синтетическому JSON stdout как источнику gate evidence.

- Добавлен first-party runner `scripts/reporting/run-plan-1b-gate.php`: он запускает Symfony Process через argv без shell, фиксирует реальный revision, exit code, UTC timestamps, duration, stdout и stderr.
- PHPUnit gates всегда записывают JUnit XML в canonical `build/reports/gates/results/`; recorder требует точный полный ordered suite set, отсутствие failures/errors/skips и строит typed records только из разобранных suite counters.
- Static-analysis gate выполняет `php -l` для каждого из четырёх изменённых production PHP-файлов и один PHPStan по тому же набору с JSON output. Recorder перепроверяет команды, paths, exit codes, timestamps и нулевые PHPStan totals.
- `run_export_observability` привязан к реальному telemetry test; `execution_attempt_leases` — к полному 15-файловому набору runtime, lifecycle, watchdog, recovery, listener и ABA-проверок.
- Каждый required check имеет явный непустой suite subset. PDF dependency check исполняемо фиксирует `barryvdh/laravel-dompdf v3.1.1` и `dompdf/dompdf v3.1.4`; summary parity привязан к export parity contract, а later-plan ownership — к отдельным architecture assertions.
- Performance gates больше не принимают готовый measurement-файл. После успешного main PHPUnit runner запускает locked measurement test с одноразовым nonce; typed artifact связан с revision, exact command, process result, raw digest и собственным digest envelope-а.
- До определения revision и перед публикацией runner требует один и тот же глобально чистый worktree; ignored generated artifacts не попадают в porcelain.
- Validator, JSON Schema, fixture, builder/E2E tests, architecture contract и canonical plan синхронизированы с единым каталогом recorder-а. Generated gate results игнорируются и не входят в коммит.

Финальные локальные проверки round 4:

- Task 14 non-DB PHPUnit gate: `OK (49 tests, 1989 assertions)`;
- PHPStan точного четырёхфайлового production-набора: `errors=0`, `file_errors=0`;
- Pint: восемь затронутых PHP-файлов, одна style-правка применена;
- schema и fixture: корректный JSON;
- canonical marker scan: пустой, 140 code fences сбалансированы;
- generated gate artifacts: после проверки удалены, ignored и untracked;
- `git diff --check`: пройден;
- PostgreSQL, S3, queue, authorization, performance, build, migrations и production-команды не запускались.
