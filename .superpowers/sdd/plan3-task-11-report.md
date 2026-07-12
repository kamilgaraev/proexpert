# Plan 3 — Task 11: промежуточный отчёт

Статус: **INTERMEDIATE — benchmark gate открыт, Task 11 не завершена**.

## Безопасно реализовано

- Введён единый неизменяемый `ReadinessResult` с `can_generate`, `can_apply`, блокирующими причинами, предупреждениями, метриками и следующим действием.
- `DraftReadinessInspector` используется snapshot и границей применения черновика; старые AI-черновики без подтверждённой production-модели не получают новый допуск.
- Удалены rule-based drawing runtime, его interface/binding и устаревшие тесты. Production bindings используют `VisionProvider` и `CadGeometryProvider`.
- Benchmark runner выполняет prediction до чтения expected labels; отдельный regression test фиксирует разделение портов.

## Проверки безопасной части

```text
vendor/bin/phpunit tests/Unit/EstimateGeneration/Quality/ProductionReadinessGateTest.php tests/Architecture/EstimateGenerationNoPlaceholderTest.php tests/Unit/EstimateGeneration/EstimatorReadinessServiceTest.php
OK (27 tests, 124 assertions) — совместно с focused benchmark tests в последнем прогоне.

vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Services/Quality app/BusinessModules/Addons/EstimateGeneration/Services/EstimateDraftPersistenceService.php app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionSnapshot.php app/BusinessModules/Addons/EstimateGeneration/Benchmark/CurrentBaselineBenchmarkAdapter.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php --memory-limit=1G --no-progress
[OK] No errors
```

Расширенный SQLite-набор содержит независимую инфраструктурную ошибку `no such function: BTRIM` в warehouse migration и не используется как доказательство готовности Task 11.

## Открытый benchmark gate

Свежий честный regression benchmark после удаления недопустимых full-prediction artifacts:

```json
{
  "attempted_count": 4,
  "succeeded_count": 0,
  "failed_count": 4,
  "skipped_count": 0,
  "work_recall": 0,
  "normative_top3": 0,
  "evidenced_applicable_items": 0,
  "technical_success_rate": 0,
  "deterministic_fingerprint": "90b3892d9b06dd1830fa79b92e4c33236832dfe815da7d1b05bad0570024fd14"
}
```

Пороги не снижались. Для завершения необходимы отдельные закрытые recorded envelopes только для недетерминированных external/AI ports, offline immutable catalog ports и production replay через DTO validators, `BuildingModelAssembler`, quantities, normative hard gates, pricing snapshots и readiness. Expected labels должны загружаться только после prediction. Regression-корпус требуется расширить минимум до восьми независимых разнообразных случаев с disjoint acceptance corpus.

Полный Plan 3 gate пока также имеет два failure и 18 skips: worker-contract ожидает отсутствующий успешный replay, а LibreDWG runtime gate не настроен через `LIBREDWG_DWGREAD_BINARY`.
