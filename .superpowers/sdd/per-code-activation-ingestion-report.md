# Независимое ревью приёма публикаций по коду

## Вердикт: REJECT

Проверен только коммит `a0353bd0ec6ad66e188a4211a202afb755094851` вместе с необходимым контекстом вызываемых контрактов. Локальные изменения рабочего дерева в область ревью не включались. Запускались только `php -l` для пяти изменённых PHP-файлов; миграции, обращения к БД, сервер и полный набор тестов не запускались.

## Findings

### [H] Production DI делает продвижение через новый ingestion-путь невозможным

**Файл:** `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php:70-75`

В production-binding `EloquentReportPublicationRegistry` создаётся с `eligibility = null`. Однако `EloquentReportPublicationRegistry::promote()` немедленно отклоняет такой вызов (`report_publication_promotion_unavailable`). Новый `ReportPublicationReleaseIngestionService` на строках 40-55 всегда вызывает этот метод. Сам ingestion-service также не зарегистрирован в контейнере и не имеет production entry point.

Следствие: корректная каноническая пара не может быть promoted через предоставленный путь; требование «promotion до canary/on» не реализовано работоспособно. Не следует обходить это прямой конфигурацией feature-store: это разрушит единственный безопасный порядок переходов.

**Исправление:** зарегистрировать единый production admission/ingestion use-case, создать registry с тем же `ReportPublicationEligibilityService`, который использует ingestion, и покрыть container-level тестом успешный `OFF`, `CANARY` и `ON` сценарии.

### [H] Нет привязки к SHA текущего checkout, поэтому replay старого подписанного выпуска принимается

**Файл:** `app/BusinessModules/Core/Reporting/Application/Publication/ReportPublicationReleaseIngestionService.php:67-97`

Проверки сопоставляют SHA только между proof, subject и CI evidence. В контракт не передаётся trusted current commit, и ни одна строка не сравнивает `release.git_sha`/`ci.commit_sha` с SHA checkout, из которого запускается приём. Проверка времени в eligibility допускает любой прошлый выпуск.

Злоумышленник или ошибочный release job, имеющий доступ к trusted directory, может положить ранее валидную подписанную пару для ещё не опубликованного кода с неизменившимися admission-входами. Подпись, provenance и все внутренние хэши останутся корректными, но будет promoted устаревший commit. Это нарушает требование current commit и fail-closed replay protection.

**Исправление:** передавать в ingestion неизменяемый trusted expected SHA (полученный из CI runtime, а не из bundle) и требовать точного совпадения с provenance, evidence, subject и proof до `promote`; добавить регрессионный тест на исторический корректно подписанный bundle.

### [H] Новый DB-digest несовместим с оставшимися YAML runtime registries и блокирует runtime после публикации

**Файлы:** `app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php:79-87`; `app/BusinessModules/Core/Reporting/Infrastructure/Catalog/DatabasePublishedReportDefinitionRegistry.php:54-67`

`ReportDefinitionRegistry` уже выдаёт DB-derived digest, но provider продолжает регистрировать `ManifestReportCatalogMetadataRegistry` и `ManifestReportSchedulingCapabilityRegistry`. Их конструкторы сравнивают hash и набор кодов с YAML manifest. Для любой DB promotion digest из строк 59-64 никогда не равен хэшу YAML-байтов; а для нового DB-only кода YAML также не содержит metadata/scheduling capability.

Следствие: разрешённая DB publication с `mode=on` не materializes в полноценный runtime catalog: при разрешении metadata/scheduling registry будет `report_manifest_hash_mismatch` либо mismatch набора кодов. Это противоречит требованию, что runtime registry должен быть DB-authoritative и не расходиться с promoted publication; YAML всё ещё является обязательным runtime dependency.

**Исправление:** заменить оба manifest-backed runtime registry DB-backed проекциями опубликованного определения (либо включить нужные metadata/scheduling данные в подписанный promoted record), убрать сравнение с YAML и добавить интеграционный тест container resolution для DB-only публикации `ON`.

### [M] Тесты не покрывают обязательные негативные инварианты ingestion

**Файл:** `tests/Unit/Reporting/Publication/ReportPublicationReleaseIngestionServiceTest.php:29-58`

Добавлен только тест несовпадающего commit между proof и artifact. Нет тестов на replay исторического валидного bundle, отсутствие/неизвестность/перестановку required checks, issuer provenance/ref/environment, tamper signature, отказ feature configuration после promotion и фактическое DI-разрешение production пути.

Это позволило пропустить оба блокирующих разрыва выше и не доказывает перечисленные в задаче fail-closed гарантии.

## Что подтверждено

- Loader ограничивает оба имени канонической парой внутри указанного каталога и отвергает symlink в момент проверки.
- Artifact verifier проверяет Ed25519, authority provenance и целостность subject/evidence.
- В ingestion `configure(CANARY|ON)` вызывается только после `promote`; bulk API в данном коммите не добавлен.
- Runtime registry сверяет `publication_id`, `proof_sha256` и режим `ON`; digest строится детерминированно по упорядоченным DB-кодам.

Эти свойства недостаточны для approval из-за трёх High findings.
