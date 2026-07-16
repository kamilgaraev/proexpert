# Geometry Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Вынести обработку PDF/DWG МОСТ в отдельный ограниченный контейнер и устранить несовместимый с production вложенный `bubblewrap`.

**Architecture:** Задание обработки документа направляется в отдельную Redis-очередь, которую потребляет только `geometry-worker`. Локальные парсеры запускаются через статический Landlock/seccomp launcher, а workflow блокирует выкладку при неуспешном runtime smoke.

**Tech Stack:** Laravel 11, Horizon/Redis queues, Docker Compose, Alpine Linux, Landlock ABI 3+, seccomp BPF, PHPUnit 11, GitHub Actions.

## Global Constraints

- Не запускать миграции, локальную БД и frontend build.
- Production меняется только штатным GitHub Actions workflow.
- Контейнер не получает дополнительные Linux capabilities и не подключает Docker socket.
- API и пользовательские статусы документов остаются совместимыми.

---

### Task 1: Контракт очереди и контейнера

**Files:**
- Modify: `tests/Feature/EstimateGeneration/EstimateGenerationDocumentUploadTest.php`
- Modify: `tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php`
- Modify: `tests/Architecture/EstimateGenerationProductionReadinessTest.php`

**Interfaces:**
- Produces: очередь `ProcessEstimateGenerationDocumentJob::QUEUE = 'estimate-generation-documents'` и Compose service `geometry-worker`.

- [ ] Добавить утверждения об отдельной очереди, единственном потребителе, `read_only`, `tmpfs`, `cap_drop` и отсутствии `bubblewrap`.
- [ ] Запустить целевые тесты и подтвердить ожидаемое падение на старой конфигурации.

### Task 2: Process sandbox без пользовательских namespaces

**Files:**
- Create: `docker/geometry/landlock-sandbox.c`
- Modify: `docker/geometry/network-deny.c`
- Modify: `docker/geometry/geometry-sandbox.sh`
- Modify: `docker/geometry/geometry-runtime-smoke.sh`
- Modify: `Dockerfile.prod`

**Interfaces:**
- Produces: `/usr/local/bin/geometry-landlock-sandbox <workspace> <bpf> <command...>`.
- Consumes: существующие параметры лимитов `geometry-sandbox`.

- [ ] Скомпилировать статический launcher, который разрешает чтение runtime, запись только в workspace, затем применяет seccomp и выполняет команду.
- [ ] Удалить пакет и вызовы `bubblewrap`.
- [ ] Расширить smoke проверкой PDF runtime, LibreDWG, запрета сети и внешней записи.
- [ ] Запустить контрактный тест и shell syntax check.

### Task 3: Отдельный worker и очередь

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationDocumentJob.php`
- Modify: `docker-compose.yml`
- Modify: `.github/workflows/deploy-backend.yml`

**Interfaces:**
- Consumes: Redis connection `redis_estimate_generation`.
- Produces: service `geometry-worker`, queue `estimate-generation-documents`.

- [ ] Изменить только очередь document job, сохранив connection, retry и блокировки.
- [ ] Добавить `geometry-worker` с `queue:work`, healthcheck, resource/security limits и writable runtime mounts.
- [ ] Перевести preflight/post-deploy smoke и диагностические логи на `geometry-worker`.
- [ ] Запустить тесты очереди и production readiness.

### Task 4: Проверка и выпуск

**Files:**
- Verify all files above.

**Interfaces:**
- Produces: неизменяемый production image и подтверждённый работающий `geometry-worker`.

- [ ] Запустить PHPUnit для document upload, PDF worker, CAD runtime и architecture contracts.
- [ ] Запустить `phpstan analyse` для изменённого PHP и `pint --test` для изменённых PHP-файлов.
- [ ] Проверить YAML, shell, C compilation contract и `git diff --check`.
- [ ] Закоммитить Conventional Commit на русском и отправить в `main`.
- [ ] Дождаться успешных preflight, deployment и post-deploy smoke.
- [ ] После повтора документов проверить production-журнал и статусы PDF/DWG.
