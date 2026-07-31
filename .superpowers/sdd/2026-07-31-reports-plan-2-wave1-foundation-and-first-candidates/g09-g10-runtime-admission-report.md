# G09/G10: pre-admission runtime-adapter slice

## Статус

`IMPLEMENTED_PRE_ADMISSION`.

Добавлены не зарегистрированные адаптеры snapshot-источника для G09 `project_margin` и G10 `budget_plan_fact`. Они материализуют только проверенное approved close через существующие writers, а `result`, `page`, `cursor` и `drillDown` читают только `ReportSourceSnapshotStore`.

## Границы

Не изменялись manifest, candidate bindings, service providers, runtime registration, routes и UI. По результату проверки G09 и G10 сохраняют `source readiness required`; их binding остаётся `blocked_by_source_readiness` с `provider: null`.

## Conformance evidence

DB-free unit test с in-memory `ReportSourceSnapshotStore` покрывает оба адаптера:

- materialize только для approved close и прогресс до 100%;
- snapshot replay для result, page, cursor и drill-down без повторного обращения к live source;
- пагинацию, snapshot/source hash и redaction полей источника;
- отклонение изменённого source hash;
- безопасную версию схемы provenance `v1_0_0`.

## Проверки

- `php -l` для трёх адаптеров и unit test — PASS.
- `vendor/bin/phpunit tests/Unit/Budgeting/BudgetingReportSourceSnapshotAdapterTest.php --no-coverage` — PASS, 4 tests / 42 assertions.
- `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` для тех же файлов — PASS, no errors.
- Финальный `git diff --check` — PASS.

## Оставшиеся ограничения admission

Срез не является admission. До подключения provider нужны CI PostgreSQL evidence для хранения/ограничений и acceptance-проверка воспроизведения после изменения upstream данных. До этого G09/G10 не должны регистрироваться в runtime или продвигаться в manifest.
