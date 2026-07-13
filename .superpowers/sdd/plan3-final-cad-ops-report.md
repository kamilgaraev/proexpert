# Plan 3 — F1 CAD/benchmark final fixes

## RED

- `php artisan test tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php --no-coverage`: старый production test ожидал запрет acceptance; после введения обязательного output contract получил `production_output_locator_invalid` вместо прежнего кода. Контракт теста обновлён на fail-closed production store policy.
- `php artisan test tests/Architecture/EstimateGenerationProductionReadinessTest.php --no-coverage`: новый Docker ownership contract обнаружил слишком широкий текстовый matcher; отдельно также выявлен чужой stale список migration `001000/001100`. Matcher исправлен, migration expectation не включён в F1.
- `vendor/bin/phpstan analyse ...`: обнаружил замыкание provider с недоступной переменной `$app`; factory переписан без вложенного static closure.
- Первый GREEN локального output store дал PHPUnit warning на ожидаемой коллизии `fopen(..., x)`; scoped error handler сохранил exclusive-create семантику без диагностического шума.

## GREEN

- CAD readiness tests: проверяют fail-before-input, точную версию, symlink/junction, повторное хеширование и отказ после мутации.
- Benchmark command tests: production repository/local/generic paths закрыты до corpus/adapter; immutable capability store получает отчёт; local CLI сохранён.
- Docker contract: application и CAD runtime root-owned/read-only, writable только runtime state; image создаёт root-owned SHA-256 manifest для всех исполняемых CAD-компонентов.
- PHPStan и Pint выполняются на F1 scope. Полный architecture suite может падать только на чужом stale migration expectation; Docker test запускается отдельно.
- Финальные результаты: CAD `6 passed (10 assertions)`, benchmark command `9 passed (27 assertions)`, Docker ownership/hash-manifest focused gate проходит без предупреждений.

## Verified execution follow-up

- RED/design gap: runtime-level readiness не мог доказать целостность в окне между возвратом inspector и фактическим стартом Symfony Process.
- GREEN: `VerifiedCadExecution` переносит fingerprint capability в `GeometryProcessRunner::runVerified()`, который повторно хеширует все artifacts непосредственно перед process start.
- `php artisan test tests/Unit/EstimateGeneration/Vision/VerifiedCadExecutionTest.php --no-coverage`: `6 passed (13 assertions)`. Отдельные data sets: python, dwgread, sandbox, worker, requirements; после мутации стабильный `cad_runtime_artifact_integrity_mismatch`, marker процесса отсутствует. Дополнительный сценарий: первый старт разрешён, после post-verification mutation второй старт отклонён, счётчик остаётся равен одному.
- `vendor/bin/phpstan analyse --memory-limit=1G ...`: `[OK] No errors`; Pint исправил два style issue, после чего focused suite повторён.
