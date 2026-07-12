# Plan 3 — Task 10: версионирование learning datasets и benchmark runs

## Результат

Реализованы закрытые versioned datasets типов `development`, `regression`, `acceptance` и immutable benchmark runs. Acceptance и regression допускаются только к benchmark; обучение, prompt/rule/threshold tuning разрешены только для approved development dataset. Обычные сметы и их таблицы не изменялись.

## Контракты доверия

- Статусы dataset закрыты: `draft`, `processing`, `review_required`, `approved`, `rejected`, `archived`.
- Dataset identity: organization scope, `dataset_key`, append-only `version`; новая версия создаётся отдельной строкой под тем же tenant/key.
- Approved/archived datasets и их examples неизменяемы; позднее добавление example запрещено.
- Accepted/indexed example требует `reviewed_by` и `reviewed_at`.
- Старые dataset явно классифицируются как organization-scoped development v1; старые unreviewed accepted/indexed rows возвращаются в pending review.
- Старые unversioned статусы блокируются CHECK-ограничением после migration.

## Benchmark persistence

Run хранит UUID, tenant, точный dataset/version composite FK, pipeline/model/normative/price versions, закрытые bounded metrics, bounded inline case results либо S3 reference, duration, decimal cost/currency, status и timestamps. Repository обеспечивает idempotent tenant-scoped start с row lock и единственный переход `running -> completed|failed`; terminal manifest и результат неизменяемы.

Файл migration из brief `2026_07_11_001200...` конфликтовал с уже существующей migration Task 9. Использовано следующее свободное упорядоченное имя `2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php`.

## TDD и проверки

- RED trust policy: 3 ожидаемые ошибки отсутствующего класса.
- GREEN DB-less Training/Benchmark gate: 14 tests / 59 assertions до дополнительного closed-metrics regression.
- Closed metrics RED: unknown acceptance-derived metric дошёл до DB facade; GREEN: отклоняется policy до persistence.
- Disposable PostgreSQL `_contract`: реальная migration, constraints, FKs и triggers; два последовательных чистых прогона на одной базе, каждый 1 test / 12 assertions, PASS. Production не использовался, migrations production не запускались.
- PHPStan по изменённым production-классам: PASS, no errors.
- `php -l`, Pint и `git diff --check`: PASS после финального gate.

## Ограничения хранения

Локальное хранение case results не добавлено. Внешний результат допускается только как organization-scoped S3 path; inline JSON и metrics ограничены 1 MiB на application и PostgreSQL уровнях.
