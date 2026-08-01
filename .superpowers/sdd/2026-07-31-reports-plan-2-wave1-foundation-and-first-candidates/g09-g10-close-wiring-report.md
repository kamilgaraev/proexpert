# G09/G10: wiring закрытия источников

## Статус

Закрытый контракт источников подключён только к pre-admission writers G09 и G10. Кандидаты по-прежнему имеют `blocked_by_source_readiness` и `provider: null`.

## Выполнено

- Request DTO требуют `close_id` и точную identity закрытия, согласованную с организацией, периодом, сценарием и версией плана фильтров.
- Writers валидируют close через `BudgetingReportSourceCloseService` до обращения к live scoped report/drill services.
- Source hash и watermarks включают close ID, approval/retention timestamps, close content hash, formula/source manifest и отсортированные per-source cutoff, watermark и source-schema version.
- Unit-тесты используют in-memory close store и покрывают approved, expired, restated и wrong-organization close до материализации; пустой `ReportScope` остаётся пустым.

## Оставшиеся admission gates

1. PostgreSQL CI evidence для миграций и ограничений close storage.
2. Replay-after-upstream-mutation acceptance test на реальных источниках.
3. Runtime provider conformance.

Ни runtime provider, ни admission, manifest, routes и UI в этой задаче не добавлялись.
