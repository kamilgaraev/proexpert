# Stage 6 AI-сметчика МОСТ — матрица инвариантов и review

## До реализации

| Граница | Инвариант | Безопасный отказ |
|---|---|---|
| Snapshot | Только current Stage 4 facts/evidence и current Stage 5 planning/completeness одного organization/project/session/source version | Typed blocking item; quantity и стоимость не публикуются |
| Quantity | Decimal-строки, совместимые units, одна заявленная точка округления, полная operand lineage | Typed unresolved input; никаких `0` и приблизительных значений |
| Geometry | Проёмы принадлежат конкретной стене; скаты — конкретной кровле; дубликаты/пересечения не суммируются молча | Blocking geometry question |
| Technology | Work package связан с current пользовательским Decision и applicable current recommendation | Blocking technology decision item |
| Normative | AI ранжирует только прошедших hard gates кандидатов | Missing/ambiguous norm review item |
| Price | Region/catalog/version/unit/date совпадают; money считается без float | Missing/stale/incompatible price review item |
| Persistence | Exact artifact/input hash проверяется под блокировкой; один hash/ключ создаёт одну обычную Estimate | Replay возвращает существующий ID; payload mismatch/stale fail-fast |
| Ownership | Organization/project/actor проверяются через существующий ABAC-контур | Unauthorized/cross-tenant отказ до записи |
| Human edits | Ранее применённая и изменённая человеком Estimate не перезаписывается | Новая версия/новая Estimate по существующему контракту |
| Bounds | Ограничены facts/evidence/formulas/rows/metadata; bulk-read/bulk-write без N+1 | Typed budget blocker |

## Correctness/security review

- Найдено: новые поля `source_version/source_date/unit/source_status` расширяли `price_snapshot`, хотя production CHECK допускает точный старый allowlist. Исправлено без миграции: snapshot-контракт сохранён, provenance выводится из `version_id`, `captured_at`, current regional pin и unit строки; добавлена проверка `pricing_finalized_at`.
- Найдено: writer проверял только Stage 4 token, но мог пропустить смену current Stage 5 run при том же наборе фактов. Исправлено: перед записью повторно сверяются current technology/completeness, input fingerprint, source version, catalog/rule version+hash+run id; отсутствие repository для Stage 6 теперь fail-closed.
- Найдено: прямой read `Estimate` из application service расширял запрещённую ORM-границу. Исправлено: replay metadata читается через существующий `GeneratedEstimateWriter`; architecture boundary снова зелёный.
- Найдено: exact decimal total и budget scope первоначально меняли legacy return contract. Исправлено: exact string применяется только `most_ordinary_estimate:v1`, прежние черновики сохраняют float-контракт и claim semantics.
- Найдено: fact IDs Stage 5 ошибочно маркировались как `evidence_id`. Исправлено: сохраняются как `fact_id`, а доказательная lineage остаётся в quantity operands/evidence.
- Найдено: разные roof facets с одинаковой geometry/evidence могли удвоить площадь. Исправлено: geometry identity и evidence coordinates дедуплицируются для скатов и проёмов; добавлена регрессия.
- Повторно проверено: cross-tenant/project/session/source scope, user-assumption Decision, stale/rejected/candidate facts, unit gates, positive decimal, rounding boundary, artifact replay и same-key/different-artifact отказ.

## Architecture/production-sized review

- Найдено: projection стен/кровель повторно сканировал все entities и давал O(n²). Исправлено: один bounded индексирующий проход и детерминированная сортировка children; запросы формул формируются O(n).
- Найдено: production roof projection не передавал roof opening areas, хотя factory их поддерживал. Исправлено: current Stage 4→6 projection включает scoped openings; wiring-test проверяет итог `13.75`.
- Проверено production wiring: pipeline `ExtractQuantities → PlanWorkItems → MatchNormatives → ResolvePrices → BuildDraft → Validate/Apply`; Stage 5 packages становятся обычными work items, а финал пишется в `Estimate/EstimateSection/EstimateItem`.
- Проверено bounded processing: 10 000 facts, 2 000 formulas, 200 packages, 500 package rows, 5 000 draft rows, 32 KiB provenance на строку, bounded refs/candidates; quantity repository использует batch reads и append.
- Проверено recovery: один session lock охватывает create rows + workflow marker; внешних AI/normative вызовов внутри apply transaction нет; failure откатывает обычную смету и session state; replay читает source artifact metadata.
- Проверено отсутствие параллельной финансовой модели, новых migrations/infra/UI/Stage 7; существующая пользовательская Estimate не обновляется и новый analysis создаёт отдельный обычный draft.
