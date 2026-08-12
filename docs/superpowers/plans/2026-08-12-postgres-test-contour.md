# PostgreSQL Test Contour Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить безопасный воспроизводимый запуск одного Laravel backend-теста МОСТ на PostgreSQL 16 в Docker.

**Architecture:** Отдельный Docker Compose-файл предоставляет локальную тестовую БД на loopback-порту. Отдельная PHPUnit-конфигурация принудительно выбирает `pgsql`, а PowerShell launcher проверяет тестовое имя базы, поднимает контейнер и запускает выбранный тест. Текущий SQLite-контур остаётся без изменений.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit 11, PostgreSQL 16 с pgvector 0.8.5, Docker Compose v2, PowerShell.

## Global Constraints

- Не запускать миграции вручную; подготовку схемы выполняет Laravel `RefreshDatabase` внутри тестового процесса.
- Не подключаться к development или production-базам.
- Публиковать PostgreSQL только на `127.0.0.1:55433`.
- Имя тестовой базы должно оканчиваться на `_testing`.
- Не изменять существующий `phpunit.xml` с SQLite.
- Не включать параллельный запуск в этот этап.

---

### Task 1: Зафиксировать PostgreSQL smoke-контракт

**Files:**
- Create: `tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php`

**Interfaces:**
- Consumes: базовый `Tests\TestCase` с `RefreshDatabase`.
- Produces: тест `test_laravel_uses_isolated_postgresql_database`, проверяющий драйвер, точное имя базы и версию PostgreSQL через реальное Laravel-соединение.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

final class PostgresDatabaseSmokeTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_laravel_uses_isolated_postgresql_database(): void
    {
        $connection = DB::connection();
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertSame('most_backend_testing', $connection->getDatabaseName());
        $version = DB::selectOne('SELECT version() AS version');
        self::assertIsObject($version);
        self::assertStringStartsWith('PostgreSQL 16.', (string) $version->version);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php`

Expected: FAIL because the current default test driver is `sqlite`, proving the new test distinguishes the desired contour.

- [ ] **Step 3: Commit the red contract**

```powershell
git add tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php
git commit -m "test[backend]: добавлен контракт PostgreSQL-контура"
```

### Task 2: Добавить воспроизводимую PostgreSQL-инфраструктуру

**Files:**
- Create: `compose.testing.yml`
- Create: `docker/postgres-testing/init.sql`
- Create: `phpunit.postgres.xml`
- Create: `tests/Runtime/run-postgres-tests.ps1`
- Modify: `.gitignore`
- Modify: `composer.json`

**Interfaces:**
- Consumes: test path в параметре PowerShell `TestPath`.
- Produces: команда `composer test:postgres` и launcher, возвращающий exit code PHPUnit.

- [ ] **Step 1: Update ignore rules**

Добавить после широких правил соответствующего типа:

```gitignore
/.env.testing.local
!phpunit.postgres.xml
```

- [ ] **Step 2: Add Docker Compose service**

Создать `compose.testing.yml` с `pgvector/pgvector:0.8.5-pg16-bookworm`, тестовыми credentials, портом `127.0.0.1:55433:5432`, именованным volume и healthcheck `pg_isready -U most_testing -d most_backend_testing`. Подключить `docker/postgres-testing/init.sql` в `/docker-entrypoint-initdb.d/10-enable-vector.sql` для выполнения `CREATE EXTENSION IF NOT EXISTS vector` при создании disposable-базы.

- [ ] **Step 3: Add forced PHPUnit PostgreSQL environment**

Создать `phpunit.postgres.xml` на основе основного набора suites, указав `force="true"` для `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55433`, `DB_DATABASE=most_backend_testing`, `DB_USERNAME=most_testing`, `DB_PASSWORD=most_testing_password`.

- [ ] **Step 4: Add guarded launcher**

Создать `tests/Runtime/run-postgres-tests.ps1`, который:

```powershell
param([string] $TestPath = 'tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php')
```

Проверяет существование test path внутри `tests`, читает PHPUnit XML, требует `pgsql`, loopback host и имя базы по regex `_testing$`, проверяет Docker daemon, выполняет `docker compose -p most-postgres-tests -f compose.testing.yml up -d --wait --wait-timeout 60`, затем запускает `php vendor/bin/phpunit -c phpunit.postgres.xml $TestPath` и возвращает его exit code.

- [ ] **Step 5: Add Composer command**

Добавить в `composer.json`:

```json
"test:postgres": "powershell -NoProfile -ExecutionPolicy Bypass -File tests/Runtime/run-postgres-tests.ps1"
```

- [ ] **Step 6: Validate static configuration**

Run: `docker compose -p most-postgres-tests -f compose.testing.yml config --quiet`

Expected: exit code 0.

- [ ] **Step 7: Commit infrastructure**

```powershell
git add .gitignore composer.json compose.testing.yml docker/postgres-testing/init.sql phpunit.postgres.xml tests/Runtime/run-postgres-tests.ps1
git commit -m "chore[backend]: добавлен PostgreSQL-контур тестов"
```

### Task 3: Выполнить green-проверку на PostgreSQL 16

**Files:**
- Verify: `tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php`
- Verify: `compose.testing.yml`
- Verify: `phpunit.postgres.xml`
- Verify: `tests/Runtime/run-postgres-tests.ps1`

**Interfaces:**
- Consumes: Docker daemon и файлы Task 2.
- Produces: свежий успешный результат одного теста либо точная диагностическая граница несовместимости миграций.

- [ ] **Step 1: Start Docker Desktop if the daemon is unavailable**

Запустить Docker Desktop штатным приложением и дождаться доступности `docker info`; не создавать другой PostgreSQL-сервис.

- [ ] **Step 2: Run exactly one smoke test through the launcher**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/Runtime/run-postgres-tests.ps1 -TestPath tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php`

Expected: PHPUnit reports exactly one passing test; assertions confirm `pgsql`, exact database `most_backend_testing`, and PostgreSQL major version 16.

- [ ] **Step 3: Inspect effective container state**

Run: `docker compose -p most-postgres-tests -f compose.testing.yml ps`

Expected: PostgreSQL service is healthy and published only on `127.0.0.1:55433`.

- [ ] **Step 4: Run final repository checks**

Run: `git diff origin/main...HEAD --check`

Expected: exit code 0 with no whitespace errors.

Run: `git status --short`

Expected: empty output.

- [ ] **Step 5: Record any migration incompatibility without broad fixes**

Если smoke-тест выявит несовместимую миграцию, зафиксировать точный файл и PostgreSQL error в результате задачи. Не расширять задачу на массовое исправление миграций без отдельного согласования.
