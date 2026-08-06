# Task 6 — отчёт о выполнении

Статус: `DONE_WITH_CONCERNS`.

## Результат

- `FileService` и его DTO переведены с S3 `VersionId` на текущий объект, определяемый уникальным ключом, ETag, SHA-256, размером и MIME-типом.
- Отчёты загружают и подписывают текущий ключ, а reconciliation перечисляет `ListObjectsV2` только под точным export-prefix. Старый `S3ReportArtifactVersionInventory` удалён, вместо него добавлен `S3ReportArtifactInventory`.
- Reporting persistence, download DTO/resource, audit и authorizers больше не хранят и не передают provider version.
- Legal Archive, Quality Control и Estimate Generation переведены на current-key + SHA-256. Доменные версии документов, нормативов, схем и алгоритмов сохранены.
- Pipeline artifact envelope больше не содержит provider version. Миграция удаляет поле из старых JSON-конвертов и переводит затронутые completed checkpoints в `invalidated`, чтобы они были безопасно пересчитаны.
- Обновлены unit, feature, architecture и условные integration-контракты, которые утверждали S3 `VersionId` как бизнес-идентичность.

## Destructive reset migration

Добавлена `database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php`.

`up()`:

- очищает `report_files`, `report_exports`, legal signature artifacts и cleanup debts;
- очищает nullable storage identity у quality photos;
- удаляет S3-version поля и пересобирает соответствующие constraints/indexes/triggers;
- удаляет provider-version locator из estimate processing units;
- удаляет provider-version поля benchmark outputs;
- инвалидирует старые pipeline outputs после удаления вложенного `artifact.version_id`;
- пересобирает legal artifact guard без зависимости от provider version.

`down()` восстанавливает структуру удалённых колонок, constraints, indexes и triggers, но намеренно не восстанавливает удалённые данные.

## TDD и проверки

- RED: `tests/Unit/Reporting/Exports` падал на `ListObjectVersions`; report download test — на version-pinned temporary link; estimate storage test — на обязательном `version_id`.
- RED для сохранённых pipeline outputs: новый persistence-contract падал до добавления их invalidation/reset в миграцию.
- GREEN: обязательный набор `tests/Unit/Reporting/Exports tests/Unit/Services/Storage tests/Unit/Storage` — **143 passed, 797 assertions**.
- GREEN: pipeline artifact envelope/store — **7 passed**; pipeline persistence — **2 passed**; targeted estimate document/storage/raster/DWG и legal/report contracts прошли.
- GREEN: architecture + raster result — **5 passed, 1080 assertions**.
- `php -l` — **86 изменённых PHP-файлов без синтаксических ошибок**.
- Pint — изменённые PHP-файлы отформатированы.
- PHPStan по 52 изменённым runtime-файлам завершился без диагностик; отдельная повторная проверка ключевых `FileService`, document locator и report inventory — `[OK] No errors`.
- `git diff --check` — успешно.
- Уточнённый scan runtime-контрактов `artifact_version_id|storage_version_id|'VersionId'|ListObjectVersions` вне исторических migrations — чисто. В трёх старых module migrations ссылки сохранены как история схемы; rollback-ссылки новой миграции также намеренны.

## Границы проверки и замечания

- Целевая destructive migration не запускалась вручную; команды к PostgreSQL/production DB не выполнялись.
- Условный disposable-S3 integration scenario не дошёл до S3: общий Laravel test bootstrap начал эфемерные SQLite migrations и упал на существующей SQLite-несовместимой миграции с `BTRIM`. Сам сценарий переведён на current-object contract, но требует CI-окружение `REPORTS_S3_INTEGRATION=1`.
- Полный PHPStan по всем указанным каталогам блокируется существующим несвязанным конфликтом сигнатур `QualityDefectFlowDrillDownProvider::drillDown(ReportDrillDownRequest)` и `ReportDrillDownProvider::drillDown(ReportDrillDownInput)`. Изменённые runtime-файлы проходят анализ.
- Полный `DwgDxfGeometryProviderTest` требует отсутствующий `LIBREDWG_DWGREAD_BINARY`; current-object ветви provider проверены целевым фильтром.
- Read-only production tinker не смог загрузить `bootstrap/cache/services.php` из-за серверных прав. Риск старых pipeline-конвертов закрыт миграционной invalidation без зависимости от фактического количества записей.
