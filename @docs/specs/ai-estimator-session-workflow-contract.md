# Согласованный workflow snapshot AI-сметы

## Контекст

После остановки обработки документа 174 operational projector корректно переводит
устаревшее состояние session 72 из `processing_documents` в
`input_review_required`. Документ при этом имеет terminal partial outcome:
22 страницы завершили execution-контур, 2 страницы сохранили полезный результат,
а сам документ требует проверки.

До исправления канонический snapshot независимо вычислял:

- `recommended_step` по отсутствию pending-документов;
- доступность `workflow_steps` по покрытию всех документов пригодным или
  явно проигнорированным результатом.

Для `action_required=1` первая формула выбирала `draft`, а вторая оставляла
`draft.available=false`. Строгий normalizer админки обоснованно отклонял такой
контракт.

## Канонический контракт

При `input_review_required`, `next_action=review_documents` и наличии
action-required документа snapshot обязан вернуть:

- `recommended_step=documents`;
- `documents.available=true` и `documents.recommended=true`;
- `draft.available=false` и `draft.recommended=false`;
- ровно один рекомендованный шаг, который доступен и совпадает с
  `recommended_step`.

Availability и recommendation формируются одной серверной проекцией. Клиент не
исправляет и не ослабляет этот контракт.

## Регрессионные границы

- Unit-тест канонической проекции проверяет полный список workflow steps.
- PostgreSQL contract test воспроизводит production-shaped stop/partial session,
  вызывает `SessionSnapshotData::toArray()` и проверяет top-level shape,
  readiness navigation, document summary и recommendation invariant.
- Admin Vitest проверяет полный production-shaped fixture реальным
  `normalizeSessionSnapshot`.
- Admin MSW-тест проводит тот же payload через настоящий API client.
- После deploy live snapshot session 72 передаётся в этот же Vitest normalizer
  через `MOST_AI_ESTIMATOR_SNAPSHOT_CANARY_JSON`.

Production canary остаётся read-only: без retry, новой сметы, AI-вызовов и записи
данных.
