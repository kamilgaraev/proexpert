# Plan 3: исправление финальных gate-блокеров

Дата: 2026-07-13
Репозиторий: `C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper`

## Ограничения

- Обычные сметы и production-данные не изменялись.
- Миграции и команды с подключением к БД не запускались.
- `.cbmignore` и `.codebase-memory/` не изменялись.
- Production benchmark повторно не запускался: production-логика не менялась, изменения ограничены тестовыми контрактами и форматированием Pint.

## 1. Fresh-install PostgreSQL contract

### Причина

`TrainingBenchmarkFreshInstallPostgresTest` загружал Laravel до проверки opt-in и сразу проверял `DB::getDriverName()`. В DB-less suite приложение использовало SQLite, поэтому тест падал вместо безопасного пропуска.

Рабочий контракт соседних PostgreSQL-тестов модуля использует `RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT=1`, драйвер `pgsql` и disposable database с суффиксом `_contract`.

### RED

```text
vendor\bin\phpunit tests\Feature\EstimateGeneration\Benchmark\TrainingBenchmarkFreshInstallPostgresTest.php --no-coverage
```

Результат: `FAIL`, `1 test`, `1 assertion`; ожидался `pgsql`, получен `sqlite`.

### Исправление

Fail-closed guard перенесён в `setUp()` до загрузки Laravel. Bootstrap разрешён только при одновременном выполнении трёх условий:

- `RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT=1`;
- `DB_CONNECTION=pgsql`;
- `DB_DATABASE` заканчивается на `_contract`.

Проверки фактического драйвера, имени базы, таблиц, индексов и constraints внутри opt-in сценария сохранены без ослабления.

### GREEN

```text
vendor\bin\phpunit tests\Feature\EstimateGeneration\Benchmark\TrainingBenchmarkFreshInstallPostgresTest.php --no-coverage
```

Результат: `OK`, `1 skipped`; приложение и БД до skip не загружались.

## 2. Canonical migration inventory

### Причина

`EstimateGenerationProductionReadinessTest` дублировал отдельный статический список миграций `2026_07_11_*`. После регистрации миграций `001000` и `001100` в каноническом sealed inventory тестовый список устарел.

Авторитетный источник для production fresh-install/contract provisioner — `EstimateGenerationContractDatabaseProvisioner::completeInventory()`. Он содержит полный упорядоченный реестр модуля, а отдельный contract test сверяет его с фактическим каталогом и контролирует digest.

### RED

```text
vendor\bin\phpunit tests\Architecture\EstimateGenerationProductionReadinessTest.php --filter plan_migrations_are_uniquely_ordered_and_reversible_in_reverse_dependency_order --no-coverage
```

Результат: `FAIL`; фактический каталог дополнительно содержал `001000` и `001100`.

### Исправление

Architecture test теперь извлекает ожидаемый `2026_07_11_*` subset из канонического `completeInventory()` и сравнивает его с отсортированным фактическим каталогом. Новый или удалённый, но не зарегистрированный файл по-прежнему приводит к падению; отдельный второй список больше не может молча устареть.

### GREEN

```text
vendor\bin\phpunit tests\Architecture\EstimateGenerationProductionReadinessTest.php --filter plan_migrations_are_uniquely_ordered_and_reversible_in_reverse_dependency_order --no-coverage
```

Результат: `OK (1 test, 30 assertions)`.

## 3. Pint exact scope

Pint применён только к 32 файлам, перечисленным в `plan3-final-verification-report.md`.

```text
FIXED 32 files, 32 style issues fixed
```

Единственный глобальный файл — `routes/console.php`; он входит в Plan 3 из-за расписаний AI-модуля. Его diff ограничен правилами Pint: порядок imports, круглые скобки у `new`, concat spacing и whitespace пустой строки. Остальные изменения Pint также являются форматированием. Сгенерированные бинарные артефакты не изменялись.

Финальный `Pint --test` выполнен по полному scope из 407 существующих PHP-файлов после baseline `4aa59020`, с прежним исключением отдельного финансового fix:

```text
PINT_SCOPE=407
PINT_EXACT_SCOPE=PASS
```

## 4. Итоговая верификация

### Полный Plan 3 DB-less gate

```powershell
$env:LIBREDWG_DWGREAD_BINARY = (& '.\tests\Runtime\bootstrap-libredwg-runtime.ps1')
php artisan test tests/Unit/EstimateGeneration/Benchmark tests/Unit/EstimateGeneration/BuildingModel tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/Quantities tests/Unit/EstimateGeneration/Normatives tests/Unit/EstimateGeneration/Pricing tests/Unit/EstimateGeneration/Quality tests/Feature/EstimateGeneration/Geometry tests/Feature/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Pricing tests/Architecture/EstimateGenerationNoPlaceholderTest.php --no-coverage
```

Результат: `PASS`, `511 passed`, `5119 assertions`, `34 skipped`, duration `190.73s`. Все skips относятся к явно opt-in PostgreSQL suites; fresh-install test даёт один из этих корректных skips.

### Focused F1

```text
php artisan test tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php tests/Unit/EstimateGeneration/Vision/VerifiedCadExecutionTest.php tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php tests/Architecture/EstimateGenerationProductionReadinessTest.php --no-coverage
```

Результат: `PASS`, `24 passed`, `151 assertions`.

### PHPStan

```text
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration --memory-limit=2G --no-progress
```

Результат: exit `0`, ошибок нет.

### Синтаксис и рабочее дерево

```text
PHP_L_SCOPE=34
PHP_L_FAILED=0
PYCACHE_REMOVED=1
PYCACHE_REMAINING=0
git diff --check: PASS
```

## Остаточное ограничение

Реальный opt-in PostgreSQL fresh-install сценарий не запускался без disposable contract environment. Его защищённое поведение сохранено существующими runtime assertions; DB-less ветка и полный локальный gate проверены.
