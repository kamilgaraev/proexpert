# Контракт идентичности снимка отчёта

## Решение

`report_query_identity` хранит минимальную каноническую проекцию `ReportQuery` и закрепляется `report_query_hash`. Материализованный источник хранит независимый `materialized_source_hash`; канонический хэш отчёта формируется только после получения результата из этой проекции, свойств снимка и хэшей сырьевых source refs.

`canonical_report_hash` представлен в Core API как `ReportSnapshotRef::canonicalReportHash` (тот же закреплённый `sourceHash` run/provenance). `ReportSnapshotRef::materializedSourceHash` отделяет его от исходного снимка. Канонический builder не включает ни собственный канонический хэш снимка, ни provenance aggregate hash, поэтому не образует криптографическую неподвижную точку.

## Хранение и отказ

Миграция добавляет проекцию запроса, её хэш и явный хэш материализованного источника. Триггер не разрешает перевести новый снимок в `ready` без всех трёх значений; immutable-триггеры сохраняют их после готовности. Существующие строки не дополняются идентичностью запроса: `ReportSourceSnapshotIntegrity` возвращает `REPORT_SNAPSHOT_NOT_READY`, поэтому они не участвуют в чтении, conformance или публикации.

## R15

R15 сначала создаёт локальный снимок сырого источника, вычисляет canonical hash по result/provenance source ref, затем возвращает итоговый `ReportSnapshotRef` с обоими явно размеченными хэшами. Harness и job требуют совпадения canonical hash со снимком и provenance.
