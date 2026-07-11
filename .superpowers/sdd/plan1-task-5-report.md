# Plan 1 — Task 5: единый snapshot сессии AI-сметчика

## Статус

Реализовано.

## Изменения

- Добавлен неизменяемый `SessionSnapshotData` со стабильным v2-контрактом.
- Добавлен `BuildSessionSnapshot` с явной status-action policy.
- Действия фильтруются по точным permissions, без определения ролей.
- Для каждого доступного действия backend возвращает `action`, `label`, `method`, `endpoint`, `requires_confirmation`.
- `next_action` выбирается только из фактически доступных пользователю действий.
- Для `applied`, `cancelled`, `archived` действия и `next_action` отсутствуют.
- Resource и status endpoint переведены на единый snapshot; `AdminResponse` оборачивает его ровно один раз.
- Добавлены русские подписи действий через `trans_message`.
- Проверка permissions кешируется в рамках HTTP-запроса, чтобы список сессий не выполнял её повторно для каждой строки.
- После ревью list и detail разведены по разным ресурсам: `EstimateGenerationSessionListResource` строит только лёгкий snapshot и конструктивно не зависит от readiness-сервисов; `EstimateGenerationSessionResource` используется для show/status и mutation-ответов.
- `index` больше не загружает документы и их вложенные счётчики; packages/items также не загружаются.
- Действие `review` проверяет `estimate_generation.view`, как и фактический GET-маршрут `/review-items`.
- Удалено повторное вычисление `documents_summary` в detail-ответе.
- После повторного ревью в контракт добавлен `readiness_evaluated`: lightweight list возвращает `false`, поэтому пустые blockers/warnings не трактуются как успешная проверка готовности.
- При `readiness_evaluated=false` действие `apply` и соответствующий `next_action` никогда не публикуются; доступный GET `review` сохраняется.

## Проверки

- RED: тесты сначала завершились отсутствием новых классов после исправления имени fixture-метода.
- GREEN после повторного исправления ревью: `vendor/bin/phpunit` — 12 тестов, 47 утверждений.
- `php -l` для изменённых PHP-файлов — без ошибок.
- PHPStan/Larastan для изменённого backend-модуля — без ошибок.
- `git diff --check` — без ошибок.
- UTF-8 новых русских переводов проверен программно.

## Ограничения проверки

Полный HTTP Feature bootstrap через общий `Tests\\TestCase` не использовался, потому что он автоматически запускает SQLite-миграции. Контракт проверен через Laravel application bootstrap без `RefreshDatabase`, реальный HTTP-маршрут на `EstimateGenerationController::show`, безопасный model binding несохранённых моделей, реальный `JsonResource` и реальный `AdminResponse`. Отдельная collection-проверка доказывает, что list resource не вызывает readiness-сервисы.

## Риски

Snapshot намеренно больше не отдаёт старые тяжёлые поля `input`, `analysis`, `documents` и вычисляемый клиентский блок `progress`. Потребителям следует использовать `processing_stage`, `processing_progress`, summary-блоки и серверные `available_actions`.
