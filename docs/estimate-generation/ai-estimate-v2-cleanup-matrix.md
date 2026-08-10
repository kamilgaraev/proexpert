# Матрица очистки runtime AI-сметчика v2

## Назначение

Матрица является блокирующим контрактом Этапа 1 плана `2026-08-10-ai-estimate-assistant-v2.md`. Статус `DELETE` считается закрытым только после удаления production-кода, container bindings, routes, schedules, jobs, frontend callers, тестов удалённой механики и production-схемы через новую forward-only migration.

Исторические миграции не переписываются и не удаляются. Субагенты не используются.

## Runtime-контуры

| Контур | Решение | Целевой владелец | Условие завершения |
|---|---|---|---|
| Пользовательская квота AI-смет | KEEP | `AiEstimateQuotaService` | Одна сессия резервирует максимум одну из 10 + купленных генераций |
| `estimate_generation_ai_estimate_quota_reservations` | KEEP | `AiEstimateQuotaService` | Идемпотентность по организации и сессии |
| Журнал фактического AI-usage | KEEP | `AiUsageStore` | Одна запись фактической попытки, токены, стоимость, длительность и результат |
| Каталог AI-цен и расчёт фактической стоимости | KEEP | `AiPricingCatalog`, `AiCostCalculator` | Используется только для наблюдаемости и экономики |
| `estimate_generation_ai_operations` | KEEP | `EloquentEffectiveSettingsOperationStore` | Хранит неизменяемый snapshot эффективных настроек операции; не участвует в reserve/claim/settle |
| `estimate_generation_ai_budget_reservations` | DELETE | — | Нет runtime-callers; таблица удаляется forward-only migration |
| SQL-функции `eg_*_ai_budget` | DELETE | — | Нет runtime-callers; функции удаляются forward-only migration |
| `AiAttemptBudgetAuthorizer`, `AiBudgetGuard` | DELETE | — | Классы и container bindings отсутствуют |
| `ReconcileAiBudgetReservationsJob` | DELETE | — | Job, schedule, binding и тесты удалены |
| Безопасная запись технической ошибки | KEEP | `FailureRecorder` | Ошибка нормализуется и не содержит чувствительных данных |
| Штатный queue retry/backoff | KEEP | Laravel queue + один checkpoint path | Повтор не уничтожает подтверждённый результат |
| `AdminFailureResolution*` | DELETE | — | Нет mutating endpoints, actions, bindings и registry claims |
| `FailureWorkflowFence`, изменяющий workflow | DELETE | — | Terminal handling идемпотентен через состояние сессии и quota service |
| Read-only история сбоев | KEEP | `FailureRecorder` / read model | Не изменяет pipeline или сессию |
| Доверенный evaluation corpus | KEEP | `EvaluationCorpus` | Только reviewed examples участвуют в release gate |
| Версии AI-контрактов, моделей и промптов | KEEP | Evaluation metadata | Версия входит в benchmark result |
| Training lease recovery | DELETE | — | Scheduled recovery и lease processor отсутствуют |
| Training online migration runtime | DELETE | — | Нет runtime migration orchestration |
| Автоматическое признание исправления разметкой | DELETE | — | Исправление создаёт только candidate example |
| Канонический `DocumentUnitAdapter` | KEEP | `Application/Documents` | Определяет units и единый `DocumentRepresentation` |
| Native CAD/XLSX readers | REPLACE | `CadStructureExtractor`, `SpreadsheetStructureExtractor` | Не публикуют второй document contract |
| `PipelineRunner` | KEEP | `Pipeline` | Единственный исполняемый pipeline |
| `EloquentPipelineCheckpointStore` | KEEP | `Pipeline` | Единственный durable checkpoint store |
| Finalization outbox/delivery abstractions | DELETE | — | Заменены одним идемпотентным `PublishDraftOnce` |
| Обычный writer сметы МОСТ | KEEP | `GeneratedEstimateWriter` | Единственная граница записи обычной сметы |
| Correction chain projector | DELETE | — | Текущее состояние читается напрямую, история — append-only decisions |
| Журнал решений и отмена | REPLACE | `EstimateDecisionRepository` | Apply/revert используют optimistic version и idempotency key |

## Защищаемые пользовательские возможности

- создание и повторный вход в сессию;
- загрузка и дозагрузка документов;
- чтение snapshot и прогресса;
- просмотр очереди исключений;
- запуск и безопасный повтор генерации;
- запись результата в обычную смету МОСТ;
- квота 10 + купленные генерации;
- ABAC по организации и проекту;
- прослеживаемость источников, формул, норм и цен.

## Порядок удаления production-схемы

1. Удалить все runtime-callers и background schedules.
2. Выпустить код без обращений к legacy-таблицам и функциям.
3. Проверить read-only production logs/metrics на отсутствие обращений.
4. Выполнить forward-only cleanup migration в контролируемом deploy.
5. Не восстанавливать удалённую архитектуру через `down()`.
