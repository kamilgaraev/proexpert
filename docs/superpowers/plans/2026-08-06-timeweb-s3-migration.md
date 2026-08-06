# Timeweb S3 Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полностью перевести файловое хранилище МОСТ на один приватный бакет Timeweb Cloud S3 без переноса старых объектов, fallback на Яндекс и сохранения устаревших S3-контрактов.

**Architecture:** `App\Services\Storage\FileService` — единственный прикладной шлюз к одному Laravel-диску `s3`; прикладные сервисы создают неизменяемые ключи `org-{organization_id}/.../user-{user_id}/...`. Существующая multipart-загрузка дизайн-моделей продолжает передавать части через Laravel API, но все S3-операции выполняет `FileService`; после завершения объект потоково перечитывается для проверки размера и вычисления SHA-256. Домены сохраняют ключ, MIME, размер и SHA-256, но не зависят от S3 `VersionId`; старые файловые записи удаляются миграцией, потому что перенос объектов не выполняется.

**Tech Stack:** PHP 8.2+, Laravel 11, Flysystem AWS S3 v3, AWS SDK for PHP, PostgreSQL, PHPUnit/Pest, Larastan, GitHub CLI, существующий GitHub deploy workflow.

## Global Constraints

- Использовать один приватный бакет `prohelper-storage` и endpoint `https://s3.twcstorage.ru`.
- Все актуальные ключи начинаются с `org-{organization_id}/`; персональные — с `org-{organization_id}/personal-files/user-{user_id}/`.
- Не переносить старые объекты; полностью удалить неценные старые файловые записи.
- Не создавать fallback, dual-read, dual-write, отдельные бакеты, CDN или новую CI/CD-инфраструктуру.
- Не рефакторить холдинги, сайты холдингов и CMS.
- Не запускать миграции локально или вручную на production; их применяет только существующий deploy workflow.
- Не выполнять ручной деплой: каждый блок проходит PR, merge в `main`, штатный deploy и read-only production-smoke.
- Не выводить секреты в команды, логи, тесты, PR или документацию.
- Автоматическое удаление отчётов отключить; явное пользовательское/административное удаление сохранить.
- Каждый исполняемый блок выполнять через RED → GREEN → REFACTOR и отдельный PR.

---

## File Structure

### Новые файлы

- `app/Services/Storage/StorageRuntimeConfiguration.php` — валидация обязательной production-конфигурации Timeweb.
- `app/Services/Storage/PersonalFileService.php` — бизнес-операции персональных файлов с organization/user scope.
- `database/migrations/2026_08_06_000050_scope_personal_files_to_organizations.php` — очистка старых personal records и обязательный `organization_id`.
- `database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php` — удаление старых файловых записей и S3 `VersionId` полей.
- `tests/Unit/Config/TimewebS3ConfigurationTest.php` — точный контракт одного диска.
- `tests/Unit/Storage/StorageRuntimeConfigurationTest.php` — обязательность production-параметров.
- `tests/Unit/Storage/StorageArchitectureTest.php` — запрет обхода `FileService`.
- `tests/Unit/Storage/FileServiceCurrentObjectTest.php` — запись/чтение/удаление без VersionId.
- `docs/runbooks/timeweb-s3.md` — безопасный production runbook без секретов.

### Удаляемые файлы

- `app/Services/Storage/OrgBucketService.php`.
- `app/Console/Commands/CleanupPersonalFilesCommand.php`.
- `app/Console/Commands/CleanupReportFilesCommand.php`.
- `app/Console/Commands/SyncReportFilesCommand.php`.
- `app/Console/Commands/SyncOrgBucketUsageCommand.php`.
- `app/BusinessModules/Features/AIAssistant/Services/Rag/YandexRagEmbeddingProvider.php`.
- `tests/Unit/AIAssistant/Rag/YandexRagEmbeddingProviderTest.php`.
- `app/BusinessModules/Core/Reporting/Infrastructure/Exports/S3ReportArtifactVersionInventory.php` после замены актуальным key-based inventory.
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/ExpireReportsCommand.php`.
- `app/BusinessModules/Core/Reporting/Infrastructure/Console/DeleteExpiredReportArtifactsCommand.php`.
- `app/BusinessModules/Core/Reporting/Application/Retention/ExpireReportsService.php`.
- `app/BusinessModules/Core/Reporting/Application/Retention/DeleteExpiredReportArtifactsService.php`.

---

### Task 1: Один рабочий Timeweb S3-диск

**PR:** `feat/timeweb-s3-foundation`

**Files:**
- Modify: `config/filesystems.php`
- Modify: `.env.example`
- Modify: `Dockerfile.prod`
- Modify: `app/Services/Storage/FileService.php`
- Create: `app/Services/Storage/StorageRuntimeConfiguration.php`
- Create: `app/Providers/StorageServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Create: `tests/Unit/Config/TimewebS3ConfigurationTest.php`
- Create: `tests/Unit/Storage/StorageRuntimeConfigurationTest.php`
- Create: `tests/Unit/Storage/StorageServiceProviderTest.php`
- Create: `tests/Unit/Deployment/StorageBuildConfigurationTest.php`
- Verify: `tests/Unit/Services/Storage/FileServiceMultipartTest.php`
- Modify: `tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php`

**Interfaces:**
- Consumes: существующий `Storage::disk('s3')`, Laravel config repository и `Aws\S3\S3ClientInterface`.
- Produces: единственный `filesystems.disks.s3`; `StorageRuntimeConfiguration::fromConfig(array $filesystems, bool $production): self`; production boot через `StorageServiceProvider`; `FileService::reportDisk()` и `reportS3Client()` используют тот же диск/клиент, что обычные операции.

- [x] **Step 1: Написать failing-тест конфигурации**

Проверить, что `config/filesystems.php` содержит только S3-диск `s3`, использует `MOST_S3_*`, `throw=true`, приватную видимость и не содержит строк `storage.yandexcloud.net`, `REPORTS_BUCKET`, `AWS_PERSONALS_BUCKET`, ключей `reports`/`personals`.

- [x] **Step 2: Проверить RED**

Run: `php vendor/bin/phpunit tests/Unit/Config/TimewebS3ConfigurationTest.php --stop-on-failure`

Expected: FAIL, потому что текущий config содержит Yandex, AWS-переменные и три диска.

- [x] **Step 3: Написать failing-тест runtime-валидации**

Проверить два сценария:

```php
$config = StorageRuntimeConfiguration::fromConfig($completeTimewebDisk, true);
self::assertSame('prohelper-storage', $config->bucket);

$this->expectExceptionMessage('storage_configuration_invalid');
StorageRuntimeConfiguration::fromConfig(['disks' => ['s3' => ['driver' => 's3']]], true);
```

- [x] **Step 4: Проверить RED runtime-теста**

Run: `php vendor/bin/phpunit tests/Unit/Storage/StorageRuntimeConfigurationTest.php --stop-on-failure`

Expected: FAIL, класс отсутствует.

- [x] **Step 5: Реализовать единый config**

Использовать точные переменные:

```php
's3' => [
    'driver' => 's3',
    'key' => env('MOST_S3_ACCESS_KEY_ID'),
    'secret' => env('MOST_S3_SECRET_ACCESS_KEY'),
    'region' => env('MOST_S3_REGION', 'ru-1'),
    'bucket' => env('MOST_S3_BUCKET', 'prohelper-storage'),
    'endpoint' => env('MOST_S3_ENDPOINT', 'https://s3.twcstorage.ru'),
    'use_path_style_endpoint' => env('MOST_S3_USE_PATH_STYLE_ENDPOINT', true),
    'visibility' => 'private',
    'throw' => true,
    'report' => false,
],
```

Удалить `reports` и `personals`. В `.env.example` заменить общий S3-блок на `MOST_S3_*` и TTL из спецификации.

- [x] **Step 6: Реализовать runtime-валидацию и объединить клиенты**

`StorageRuntimeConfiguration` проверяет непустые key/secret в production, точные bucket/region/HTTPS endpoint Timeweb, private/path-style/throw и положительные TTL. `StorageServiceProvider` выполняет проверку при boot. В `FileService` `reportDisk()` возвращает `disk()`, а `reportS3Client()` возвращает `s3Client()`.

Docker build выполняет `composer dump-autoload`, `package:discover`, `filament:upgrade` и `view:cache` с `APP_ENV=build`; runtime-контейнеры получают `APP_ENV=production` из штатного `.env` и проходят строгую проверку.

- [x] **Step 7: Проверить GREEN**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Config/TimewebS3ConfigurationTest.php tests/Unit/Storage/StorageRuntimeConfigurationTest.php tests/Unit/Storage/StorageServiceProviderTest.php tests/Unit/Services/Storage/FileServiceMultipartTest.php --stop-on-failure
APP_ENV=testing vendor/bin/phpstan analyse config/filesystems.php app/Services/Storage/FileService.php app/Services/Storage/StorageRuntimeConfiguration.php app/Providers/StorageServiceProvider.php --memory-limit=1G
```

Expected: PASS без предупреждений новой логики.

- [x] **Step 8: Commit, PR, merge и deploy**

```bash
git add -A
git commit -m "feat[backend]: подключено единое хранилище Timeweb S3"
git push -u origin feat/timeweb-s3-foundation
gh pr create -R kamilgaraev/proexpert --base main --head feat/timeweb-s3-foundation --title "feat[backend]: подключено единое хранилище Timeweb S3" --body "Первый deploy-блок полной миграции МОСТ: один приватный Timeweb S3-диск через MOST_S3_*, без Yandex endpoint и отдельных reports/personals дисков."
gh pr checks feat/timeweb-s3-foundation -R kamilgaraev/proexpert --watch
gh pr merge feat/timeweb-s3-foundation -R kamilgaraev/proexpert --merge --delete-branch
```

После merge дождаться deploy workflow и read-only проверить release SHA, health и отсутствие новых `AccessDenied`/`storage_configuration_invalid` в production-логах.

---

### Task 2: Организационные ключи и единый шлюз

**PR:** `refactor/timeweb-s3-gateway`

**Files:**
- Modify: `app/Services/Storage/OrganizationStoragePath.php`
- Create: `app/Services/Storage/DTO/CurrentStoredFile.php`
- Modify: `app/Services/Storage/FileService.php`
- Modify: `tests/Unit/Storage/OrganizationStoragePathTest.php`
- Create: `tests/Unit/Storage/FileServiceCurrentObjectTest.php`

**Interfaces:**
- Consumes: единый Timeweb-диск из Task 1.
- Produces: `OrganizationStoragePath::forDomain(int $organizationId, string $domain, string $scope, string $objectId, string $extension): string`; `OrganizationStoragePath::personal(int $organizationId, int $userId, string $objectId, string $extension): string`; `FileService::putPrivate(string $key, mixed $contents, string $mime, string $sha256): CurrentStoredFile`; `temporaryDownloadUrl(string $key, int $ttlSeconds): string`; `deleteCurrent(string $key): void`.

`CurrentStoredFile` намеренно не содержит S3 `VersionId`. Существующий versioned `StoredFile` остаётся изолированным только в старом контуре отчётов до его полного удаления в Task 6.

- [x] **Step 1: Расширить failing-тест путей**

Добавить проверки точных результатов:

```php
self::assertSame(
    'org-42/personal-files/user-7/018f4a8a-0000-7000-8000-000000000001.pdf',
    $paths->personal(42, 7, '018f4a8a-0000-7000-8000-000000000001', 'pdf'),
);
self::assertSame(
    'org-42/reports/exports/01J4EXPORT/01J4OBJECT.xlsx',
    $paths->forDomain(42, 'reports', 'exports/01J4EXPORT', '01J4OBJECT', 'xlsx'),
);
```

Также отклонить organization/user `0`, `..`, слеш в object id, неизвестный domain и расширение с точкой.

- [x] **Step 2: Проверить RED и реализовать value object**

Run: `php artisan test tests/Unit/Storage/OrganizationStoragePathTest.php --stop-on-failure`

Expected сначала FAIL, после минимальной реализации PASS.

- [x] **Step 3: Написать failing-тест актуального объекта**

Тест записывает объект в fake/recording storage, проверяет SHA-256, MIME, размер, приватность, ссылку без `VersionId` и удаление по ключу. Отдельно проверяет отклонение ключа вне `org-{id}/` и несовпадающий SHA-256.

- [x] **Step 4: Проверить RED, реализовать и проверить GREEN**

Run:

```bash
php artisan test tests/Unit/Storage/FileServiceCurrentObjectTest.php --stop-on-failure
php artisan test tests/Unit/Storage/OrganizationStoragePathTest.php tests/Unit/Storage/FileServiceCurrentObjectTest.php tests/Unit/Storage/FileServicePrivacyTest.php --stop-on-failure
vendor/bin/phpstan analyse app/Services/Storage --memory-limit=1G
```

- [x] **Step 5: Commit, PR, merge и deploy**

Commit: `refactor[backend]: создан единый шлюз файлового хранилища`.

PR #236 merged в `main` (`192958c38615a6da068870fc143a755191b8bfbd`); штатный deploy `31053775102` завершён успешно, release SHA совпал, новых storage-ошибок в production-логе не обнаружено.

---

### Task 3A: Персональные файлы и персональные отчёты

**PR:** `refactor/timeweb-s3-domain-storage`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/PersonalFileController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ActFileController.php`
- Modify: `app/Models/PersonalFile.php`
- Create: `app/Services/Storage/PersonalFileService.php`
- Create: `app/Http/Requests/Api/V1/Admin/File/CreatePersonalFolderRequest.php`
- Create: `app/Http/Requests/Api/V1/Admin/File/UploadPersonalFileRequest.php`
- Create: `database/migrations/2026_08_06_000050_scope_personal_files_to_organizations.php`
- Modify: `app/Services/ActReport/ActReportFileService.php`
- Modify: `app/Services/Export/ExcelExporterService.php`
- Delete: `app/Console/Commands/CleanupPersonalFilesCommand.php`
- Modify: `tests/Feature/Api/V1/Admin/PersonalFileControllerWorkflowTest.php`
- Modify: `tests/Feature/Api/V1/Admin/ReportExportPersonalStorageTest.php`
- Create: `tests/Unit/Storage/PersonalStorageArchitectureTest.php`

**Interfaces:**
- Consumes: `OrganizationStoragePath` и текущие-object методы `FileService` из Task 2.
- Produces: `PersonalFile` всегда содержит `organization_id` и `user_id`; персональные файлы и выгрузки сохраняются по уникальным ключам текущих объектов без отдельного диска.

- [x] **Step 1: Написать failing-тест tenant isolation персональных файлов**

Проверить, что upload создаёт ключ `org-42/personal-files/user-7/{uuid}.{ext}`, а list/download/delete фильтруют одновременно по `organization_id=42` и `user_id=7`.

- [x] **Step 2: Проверить RED**

Run: `php artisan test tests/Feature/Api/V1/Admin/PersonalFileControllerWorkflowTest.php --stop-on-failure`

Expected: FAIL, текущая модель и запросы не используют `organization_id`.

- [x] **Step 3: Перевести персональные файлы через сервисный слой**

Контроллеры оставляют только HTTP-валидацию/ответ. Загрузку, выборку, авторизацию, создание папок и удаление вынести в профильный сервис `app/Services/Storage/PersonalFileService.php`; все запросы ограничить парой organization/user.

- [x] **Step 4: Перевести персональные отчёты и копии актов**

Перевести `ExcelExporterService` и копирование файлов актов на `PersonalFileService`; отчётам установить бессрочное хранение, удалить автоматическую очистку персональных файлов и старый аргумент выбора диска.

- [x] **Step 5: Проверить GREEN**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Storage/PersonalStorageArchitectureTest.php tests/Unit/Storage/FileServiceCurrentObjectTest.php tests/Unit/Storage/OrganizationStoragePathTest.php --stop-on-failure
APP_ENV=testing vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/Admin/PersonalFileController.php app/Http/Controllers/Api/V1/Admin/ActFileController.php app/Services/Storage/PersonalFileService.php app/Services/ActReport/ActReportFileService.php app/Services/Export/ExcelExporterService.php --memory-limit=1G
```

DB-backed feature-тесты обновлены, но локально не запускаются по правилам workspace; их применит штатный CI/deploy. Миграция проверяется только синтаксически и не запускается вручную.

- [x] **Step 6: Commit, PR, merge и deploy**

Commit: `refactor[backend]: файлы привязаны к организации и пользователю`.

После deploy проверить создание/выдачу/удаление нового временного файла через прикладной smoke без сохранения секрета.

Выполнено в PR #238 (`274ced1d8456b9eb787de75f6238f9262ae69496`), штатный deploy `31055973102` завершён успешно. Исправление DI для `ExcelExporterService` доставлено PR #239 (`276ab2a6cefeb95cf5987253dd332716c9a9e951`), deploy `31056538177` успешен; production SHA совпал, профильных ошибок в последних 500 строках лога нет.

---

### Task 3B: Остальные актуальные доменные вызовы

**PR:** `refactor/timeweb-s3-domain-callers`

**Files:**
- Modify: `app/BusinessModules/Features/AIAssistant/Services/Reports/AssistantReportFileService.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Actions/Reports/Tools/Generate*ReportTool.php`
- Modify: `app/BusinessModules/Features/BudgetEstimates/Services/EstimateStructureSnapshotStorage.php`
- Modify: `app/BusinessModules/Features/BudgetEstimates/Services/Import/FileStorageService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/Storage/EstimateSourceStorageService.php`
- Modify: `app/BusinessModules/Features/Procurement/Services/PurchaseOrderPdfService.php`
- Modify: остальные актуальные production-файлы, найденные поиском `Storage::disk|S3Client|OrgBucketService`, кроме слоя `app/Services/Storage` и явно исключённых holding/CMS-файлов.

**Interfaces:**
- Consumes: `OrganizationStoragePath` и текущие-object методы `FileService`.
- Produces: все актуальные домены передают только организационный ключ и не выбирают S3-диск или бакет.

#### Task 3B.1: AI-отчёты

**PR:** `refactor/timeweb-s3-ai-reports`

- [x] **Step 1: Написать failing архитектурные и доменные тесты**
- [x] **Step 2: Проверить RED**
- [x] **Step 3: Перевести AI-отчёты на `PersonalFileService` и текущие-object методы `FileService`**
- [x] **Step 4: Проверить GREEN и отсутствие прямого доступа к S3**
- [x] **Step 5: Commit, PR, merge и deploy**

AI-отчёты сохраняются бессрочно по ключу `org-{organization_id}/personal-files/user-{user_id}/{uuid}.pdf`; временной является только download URL. Прямые вызовы `Storage::disk('s3')`, старый каталог `org-{id}/reports` и выбор диска удалены из генераторов.

Выполнено в PR #241 (`2ced1b81ce3502ef1d6b1f50b66ff5180fcc0fa6`), штатный deploy `31057879434` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках лога нет.

#### Task 3B.2: Остальные доменные вызовы

**PR:** `refactor/timeweb-s3-domain-callers`

##### Task 3B.2a: Снимки структуры и импорт смет

**PR:** `refactor/timeweb-s3-domain-callers-v2`

- [x] **Step 1: Написать failing архитектурные и доменные тесты**
- [x] **Step 2: Проверить RED**
- [x] **Step 3: Перевести снимки структуры и файлы импорта на текущие-object методы `FileService`**
- [x] **Step 4: Проверить GREEN, потоковую передачу и неизменяемые ключи**
- [x] **Step 5: Commit, PR, merge и deploy**

Файлы импорта и снимки структуры получают UUID-ключи внутри `org-{organization_id}`; запись и чтение выполняются потоково через единый приватный бакет без выбора диска или бакета доменным кодом.

Выполнено в PR #242 (`52a4276298f05383b0c69fa165646d190849972d`), штатный deploy `31059543494` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках лога нет.

##### Task 3B.2b: Нормативные источники смет

**PR:** `refactor/timeweb-s3-estimate-sources`

- [x] **Step 1: Написать failing тесты контракта одного бакета и org-ключей**
- [x] **Step 2: Проверить RED**
- [x] **Step 3: Удалить параметр бакета и динамические S3-диски из нормативных источников**
- [x] **Step 4: Проверить GREEN и обратные вызовы импорта/обновления ФГИС ЦС**
- [x] **Step 5: Commit, PR, merge и deploy**

Реализовано в PR #244 (`494f218a9f505447b6b724e6b9e74069eee0ca55`), штатный deploy `31060956039` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках журнала нет.

##### Task 3B.2c: Оставшиеся доменные вызовы

**Подблок 3B.2c.1 — старое обслуживание бакетов и автоудаление отчётов (`refactor/timeweb-s3-domain-callers-v3`):**

- [x] удалить команды сканирования прежних бакетов и общего orphan-cleanup;
- [x] снять с расписания синхронизацию бакетов и автоматическое удаление отчётов;
- [x] сохранить отдельную проверку действующей очистки битых аватаров;
- [x] commit, PR, merge и deploy.

Реализовано в PR #245 (`221589f5675b69151a6027165a18eb9f70186deb`), штатный deploy `31061318729` завершён успешно. Production SHA совпал; старые команды отсутствуют в `schedule:list`, профильных ошибок в последних 500 строках журнала нет.

**Подблок 3B.2c.2 — PDF заказов на поставку (`refactor/timeweb-s3-procurement`):**

- [x] перевести запись и чтение почтового вложения с `OrgBucketService` на `FileService`;
- [x] использовать неизменяемый UUID-ключ внутри `org-{id}/procurement/.../user-{id|system}`;
- [x] сохранять SHA-256, ETag, размер и MIME в metadata заказа;
- [x] удалить публичный URL и хранение истекающей signed-ссылки в БД и очереди;
- [x] удалять новый S3-объект только после подтверждённого rollback, не затрагивая объект при ошибке `afterCommit`;
- [x] проверить сериализацию queued Mailable и чтение вложения через контейнер worker;
- [x] commit, PR, merge и deploy.

Реализовано в PR #248 (`2bd12a29944a85ac3618182fb1e0fdaee055d3b4`), штатный deploy `31062963805` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках журнала нет.

**Подблок 3B.2c.3 — выгрузки складской ответственности (`refactor/timeweb-s3-warehouse`):**

- [x] убрать прямой вызов `FileService->disk()`;
- [x] сохранять XLSX через `putPrivate` с SHA-256 и UUID-ключом;
- [x] изолировать ключ и download URL по `org-{id}` и `user-{id}`;
- [x] commit, PR, merge и deploy.

Реализовано в PR #249 (`8ea158d3358d4ea6f5af7864d381618327ac779e`), штатный deploy `31063720907` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках журнала нет.

**Подблок 3B.2c.4 — чтение acceptance-бенчмарков (`refactor/timeweb-s3-benchmark-store`):**

- [x] убрать последний прикладной вызов `FileService->disk()`;
- [x] читать приватный org-объект через `readCurrent()` с прежним лимитом размера;
- [x] проверить закрытие stream и отказ для пути вне benchmark namespace;
- [x] commit, PR, merge и deploy.

Реализовано в PR #250 (`2a1cbe775d91abf0c3b2bb4f275fdf4f347fc4b6`), штатный deploy `31064219649` завершён успешно. Production SHA совпал, профильных ошибок в последних 500 строках журнала нет.

- [ ] **Step 1: Написать failing архитектурные и доменные тесты**
- [ ] **Step 2: Проверить RED**
- [ ] **Step 3: Перевести production-вызовы на `FileService`**
- [ ] **Step 4: Проверить GREEN и границы исключений holding/CMS**
- [ ] **Step 5: Commit, PR, merge и deploy**

---

### Task 4: Multipart дизайн-моделей через единый FileService

**PR:** `refactor/timeweb-s3-design-multipart`

**Files:**
- Create: `app/Services/Storage/DTO/CurrentMultipartCompletion.php`
- Create: `app/BusinessModules/Features/DesignManagement/Services/Contracts/DesignModelRegistrationService.php`
- Modify: `app/Services/Storage/FileService.php`
- Modify: `app/BusinessModules/Features/DesignManagement/Services/DesignManagementService.php`
- Modify: `app/BusinessModules/Features/DesignManagement/Services/DesignModelMultipartUploadService.php`
- Modify: `app/BusinessModules/Features/DesignManagement/DesignManagementServiceProvider.php`
- Modify: `tests/Unit/DesignManagement/DesignModelMultipartUploadServiceTest.php`
- Modify: `tests/Unit/Storage/FileServiceCurrentObjectTest.php`
- Modify: `tests/Unit/DesignManagement/DesignStoragePathServiceTest.php`

**Interfaces:**
- Consumes: существующий HTTP-контракт multipart-загрузки и единый `FileService`.
- Produces: org/user-scoped ключи, `FileService::startMultipart(...)`, `uploadPart(...)`, `completeCurrentMultipart(...)`, `verifyCurrentMultipart(...)`, `abortMultipart(...)`; повторяемую потоковую проверку готового объекта и SHA-256 без `VersionId`. Receipt завершения хранится в существующей cache-сессии, receipts частей объединяются под cache-lock, а явный abort удаляет уже завершённый объект. При невозможности подтвердить компенсационное удаление сессия сохраняется для повторной очистки.

- [x] **Step 1: Написать failing тесты шлюза и design upload**
- [x] **Step 2: Проверить RED**
- [x] **Step 3: Удалить прямой S3Client из DesignManagement**
- [x] **Step 4: Добавить org/user namespace, SHA-256 и компенсационное удаление**
- [x] **Step 5: Проверить GREEN и Larastan**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Storage/FileServiceCurrentObjectTest.php
php vendor/bin/phpunit tests/Unit/DesignManagement/DesignModelMultipartUploadServiceTest.php
php vendor/bin/phpunit tests/Unit/DesignManagement/DesignStoragePathServiceTest.php
vendor/bin/phpstan analyse app/Services/Storage/FileService.php app/BusinessModules/Features/DesignManagement --memory-limit=1G
```

- [x] **Step 6: Независимое review, commit, PR, merge и deploy**

---

### Task 5: Удалить старую инфраструктуру и автоматическое удаление отчётов

**PR:** `refactor/remove-legacy-s3-runtime`

**Files:**
- Delete: `app/Services/Storage/OrgBucketService.php`
- Delete: четыре legacy storage command из раздела File Structure
- Modify: `routes/console.php`
- Modify: `app/Http/Controllers/Api/V1/Landing/OrganizationController.php`
- Modify: `app/Services/Landing/MultiOrganizationService.php`
- Modify: `app/BusinessModules/Features/Procurement/Services/PurchaseOrderPdfService.php`
- Delete: reporting retention command/service files из раздела File Structure
- Modify: `app/BusinessModules/Core/Reporting/ReportingExecutionServiceProvider.php`
- Delete: `app/BusinessModules/Features/AIAssistant/Services/Rag/YandexRagEmbeddingProvider.php`
- Modify: `app/BusinessModules/Features/AIAssistant/config/ai-assistant.php`
- Modify: `tests/Unit/AIAssistant/Rag/AIAssistantRagContainerTest.php`
- Modify: `tests/Feature/Console/StorageCleanupCommandsTest.php`
- Create: `tests/Unit/Storage/LegacyStorageRuntimeRemovalTest.php`

**Interfaces:**
- Consumes: все домены уже работают через единый gateway.
- Produces: runtime без Yandex storage/AI provider, org bucket sync, legacy report sync/cleanup и report retention deletion.

- [x] **Step 1: Написать failing архитектурный тест**

Сканировать `app/**/*.php` и падать при `Storage::disk('s3'|'reports'|'personals')`, `new S3Client`, `OrgBucketService`, `YANDEX_API_KEY`, `storage.yandexcloud.net`, `reports:cleanup`, `personals:cleanup`, `reports:retention:expire`, `reports:retention:delete-artifacts` вне разрешённого storage adapter и holding/CMS исключений.

- [x] **Step 2: Проверить RED**

Run: `php artisan test tests/Unit/Storage/StorageArchitectureTest.php --stop-on-failure`

Expected: FAIL со списком оставшихся legacy-файлов.

- [x] **Step 3: Удалить legacy runtime**

Удалить классы, container bindings и schedule-записи. Создание организации больше не создаёт и не синхронизирует бакет. Удалить Yandex RAG provider и его config-ветку; Timeweb остаётся единственным AI/RAG provider. Геокодер Яндекса не затрагивать.

- [x] **Step 4: Проверить отсутствие команд и GREEN**

Run:

```bash
php artisan test tests/Unit/Storage/StorageArchitectureTest.php tests/Feature/Console/StorageCleanupCommandsTest.php tests/Unit/AIAssistant/Rag/AIAssistantRagContainerTest.php --stop-on-failure
php artisan list --raw | rg "reports:cleanup|personals:cleanup|reports:retention:expire|reports:retention:delete-artifacts|org:sync-bucket-usage|reports:sync"
vendor/bin/phpstan analyse app/Services/Storage app/Console/Commands app/BusinessModules/Core/Reporting app/BusinessModules/Features/AIAssistant --memory-limit=1G
```

Expected: тесты PASS; `rg` не возвращает удалённые команды.

- [ ] **Step 5: Commit, PR, merge и deploy**

Commit: `refactor[backend]: удалена устаревшая S3-инфраструктура`.

После deploy read-only проверить scheduler и отсутствие попыток запуска удалённых команд.

---

### Task 6: Очистить старые записи и убрать зависимость от S3 VersionId

**PR:** `refactor/remove-s3-version-contracts`

**Files:**
- Create: `database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php`
- Modify: `app/Services/Storage/FileService.php`
- Delete: `app/BusinessModules/Core/Reporting/Infrastructure/Exports/S3ReportArtifactVersionInventory.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Exports/ReportArtifactVersionInventory.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Exports/ReconcileCompletedReportArtifacts.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Exports/ReportExportExecutionService.php`
- Modify: `app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportDownloadLinkHandler.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportStore.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportExportHydrator.php`
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Audit/OutboxReportTransitionAudit.php`
- Modify: актуальные legal/quality/estimate storage DTO и persistence-файлы, найденные точным поиском `artifact_version_id|storage_version_id`.
- Modify/Delete: unit/feature-тесты, утверждающие S3 `VersionId` как бизнес-идентичность.

**Interfaces:**
- Consumes: обновлённый `StoredFile(organizationPath, etag, sizeBytes, sha256, mime)` из gateway.
- Produces: отчёты и остальные домены идентифицируют объект по уникальному key + SHA-256; таблицы не содержат S3 `VersionId`.

- [ ] **Step 1: Написать failing контрактные тесты**

Проверить, что объект отчёта содержит `storage_key`, `etag`, `sha256`, `size_bytes`, `mime_type`, но не `version_id`; download handler подписывает текущий уникальный key; reconciliation перечисляет ключи под точным export prefix без `ListObjectVersions`.

- [ ] **Step 2: Проверить RED**

Run: `php artisan test tests/Unit/Reporting/Exports --stop-on-failure`

Expected: FAIL на текущем VersionId-контракте.

- [ ] **Step 3: Переписать прикладные контракты**

Заменить `versionId` на `storageKey`/`sha256`, `ListObjectVersions` на `ListObjectsV2` или gateway-list текущих ключей, presigned download без `VersionId`, delete по актуальному ключу. Не трогать доменные `document_version_id` и прочие бизнес-версии.

- [ ] **Step 4: Создать destructive reset migration**

`up()` в транзакционно допустимом порядке удаляет строки таблиц, чьи объекты не переносятся (старые `report_files`, report export artifacts, storage cleanup debts, незавершённые multipart/file records), очищает nullable storage-ссылки в сохраняемых бизнес-таблицах и затем удаляет только S3-поля/индексы/constraints. `personal_files` уже очищается и получает organization scope в Task 3. `down()` восстанавливает структуру колонок, но не данные, и это явно отражается именем/описанием миграции.

- [ ] **Step 5: Статически проверить миграцию и GREEN**

Run:

```bash
php -l database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php
php artisan test tests/Unit/Reporting/Exports tests/Unit/Services/Storage tests/Unit/Storage --stop-on-failure
vendor/bin/phpstan analyse app/Services/Storage app/BusinessModules/Core/Reporting app/Services/LegalArchive app/BusinessModules/Features/QualityControl app/BusinessModules/Addons/EstimateGeneration --memory-limit=1G
rg -n "artifact_version_id|storage_version_id|VersionId|ListObjectVersions" app config routes
```

Expected: остаются только явно документированные инфраструктурные/исторические упоминания вне прикладного runtime; production-контракты не зависят от VersionId.

- [ ] **Step 6: Commit, PR, merge и deploy**

Commit: `refactor[backend]: удалена зависимость от версий объектов S3`.

После checks merge в `main`; штатный deploy применяет миграцию. Read-only проверить нулевые старые файловые записи, наличие новой схемы и отсутствие ошибок очередей/отчётов.

---

### Task 7: Финальная документация, внешний Timeweb checklist и сквозная проверка

**PR:** `docs/timeweb-s3-runbook`

**Files:**
- Create: `docs/runbooks/timeweb-s3.md`
- Modify: `docs/superpowers/specs/2026-08-06-timeweb-s3-migration-design.md`
- Modify: `docs/superpowers/plans/2026-08-06-timeweb-s3-migration.md`

**Interfaces:**
- Consumes: завершённый runtime Tasks 1–6.
- Produces: проверяемый runbook для CORS/lifecycle/key rotation и финальная evidence-сводка.

- [ ] **Step 1: Зафиксировать внешний checklist Timeweb**

Runbook содержит без секретов:

```text
[ ] bucket private
[ ] versioning enabled
[ ] runtime user limited to prohelper-storage Read+Write
[ ] CORS exact HTTPS origins, PUT/POST/GET/HEAD, ExposeHeaders=ETag
[ ] abort incomplete multipart after 1 day
[ ] expire noncurrent versions after 30 days
[ ] no current-object expiration
[ ] no CDN/public domain
[ ] rotate temporary runtime key after acceptance
```

- [ ] **Step 2: Выполнить финальные проверки без дублирования уже пройденных наборов**

Run:

```bash
php artisan test tests/Unit/Config/TimewebS3ConfigurationTest.php tests/Unit/Storage tests/Unit/Services/Storage tests/Unit/DesignManagement/DesignModelMultipartUploadServiceTest.php --stop-on-failure
vendor/bin/phpstan analyse app/Services/Storage app/BusinessModules/Core/Reporting app/BusinessModules/Features/DesignManagement --memory-limit=1G
vendor/bin/pint --test app/Services/Storage app/BusinessModules/Core/Reporting app/BusinessModules/Features/DesignManagement config/filesystems.php database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php
rg -n "storage\.yandexcloud\.net|REPORTS_BUCKET|AWS_PERSONALS_BUCKET|OrgBucketService|reports:cleanup|personals:cleanup" app config routes .env.example
```

Expected: тесты/Larastan/Pint PASS; legacy search не возвращает production runtime.

- [ ] **Step 3: Независимое review и исправления**

Применить `superpowers:requesting-code-review`; исправлять только доказанные замечания, повторяя минимальные затронутые тесты.

- [ ] **Step 4: Commit, PR, merge и финальный deploy-smoke**

Commit: `docs[backend]: добавлен runbook Timeweb S3`.

После merge дождаться штатного deploy. Read-only подтвердить release SHA, health, отсутствие S3/queue/scheduler ошибок и успешный прикладной Put/Head/Get/Delete smoke в `org-{id}/temporary/smoke/`.

- [ ] **Step 5: Завершить цель**

Обновить этот план фактическими PR, SHA и результатами проверок; отметить цель complete только после production-smoke и перечислить единственные внешние действия Timeweb, если они ещё не подтверждены владельцем.
