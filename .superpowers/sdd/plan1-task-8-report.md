# Plan 1 / Task 8 — отчёт исполнителя

## Результат

- Удалены публичные permission-алиасы `ai_estimates.*`; манифест публикует ровно восемь `estimate_generation.*` прав.
- Начальная стадия сессии заменена с `created` на `draft`.
- Добавлены явные workflow-действия `confirm_input`, `retry`, `cancel`, `archive`: typed requests, application use case, маршруты, RBAC, snapshot actions и русские переводы.
- Read endpoints обоих контроллеров делегируют безопасному responder: HTTP 403/404 сохраняются, неожиданные ошибки логируются с контекстом и возвращаются через `AdminResponse`.
- Постоянный legacy-gate стал session-aware: ловит точные старые значения в create/factory/query/whereIn/match/variable/stage сценариях и не путает их со статусами документов, пакетов, качества и обучения либо с `estimate_review_required`.
- Граница обычных смет закрыта: внутри AI-модуля единственным импортёром writable ordinary-моделей остаётся `Application/Apply/LaravelGeneratedEstimateWriter`; read/raw allow-list пуст. Неиспользуемые Eloquent relations удалены. Обучение, экспорт и поиск номера вынесены в новые integration adapters вне AI-модуля через нейтральные контракты.
- Существующие файлы обычных смет не изменены; diff в `Features/BudgetEstimates` состоит только из новых файлов `Integrations/EstimateGeneration`.
- Добавлен прямой тест терминальности: только `applied`, `cancelled`, `archived`.
- Созданы русские эксплуатационные документы с участниками, условиями, happy path, работой с чертежами/рисунками, invalidation, review/retry/cancel/archive/apply, версиями/попытками, всеми правами, endpoint actions, матрицей переходов и границей обычных смет.

## TDD

RED подтверждён до реализации:

- module registration: manifest возвращал восемь `ai_estimates.*` вместо нового контракта;
- legacy gate: обнаружил `processing_stage=created`;
- snapshot/actions: отсутствовали confirm/retry/cancel/archive;
- route RBAC: отсутствовали четыре endpoint;
- application transition use case: класс отсутствовал;
- ordinary estimate boundary: шесть прямых read dependencies, один raw table lookup и virtual ordinary-model construction;
- controller safety: read endpoints не использовали safe responder.

После минимальных реализаций каждый набор был переведён в GREEN.

## Проверки

- DB-less Plan 1 gate после review-fix: `111 passed`, `637 assertions`.
- PHPStan/Larastan по Domain/Application/Http/Learning/Training, composition root и integration adapters: `[OK] No errors`, 80 файлов, `--memory-limit=1G`.
- Pint: 36 файлов, 16 style issues исправлены; повторный тестовый gate GREEN.
- `php -l`: все 36 изменённых/новых PHP-файлов без синтаксических ошибок.
- `git diff --check`: чисто.
- UTF-8 strict decode: оба workflow-документа, русские переводы и manifest валидны.
- JSON manifest: валиден, ровно восемь актуальных permissions.
- Existing ordinary-estimate files: diff отсутствует; только новые integration adapter files.
- Миграции вручную не запускались. Feature-сценарии, которым нужна БД, сохранены/статически проверены, но в финальный gate не включались.

## Особое замечание окружения

Один ранний запуск существующего model unit test через базовый проектный `Tests\\TestCase` неожиданно активировал migration hooks тестового окружения. После обнаружения такие тесты исключены; финальные проверки выполнялись только подтверждённо DB-less наборами. Команды `artisan migrate`, DB artisan и production-записи не выполнялись.

## Исправления после code review

- Tenant guard для confirm/retry/cancel/archive проверен через реальные временные HTTP routes: чужая организация получает стандартизированный `403`, а не `500`; 404/HTTP semantics не поглощаются generic error handler.
- `/retry` выделен в authoritative application use case со scoped `organization_id`/`project_id`, транзакционной блокировкой и CAS.
- Повтор документов ставит после commit только pending/failed IDs, не считает `ignored` блокером и переводит `needs_review` в ручную проверку.
- Повтор генерации ротирует attempt token и ставит ровно одну job после commit; stale version не создаёт dispatch.
- Повтор применения возвращает `failed(applying)` в `ready_to_apply`, не оставляя сессию зависшей.
- AI-модуль зависит только от собственных портов. Конкретные export/learning/number/training adapters перенесены в `App\\Integrations\\EstimateGeneration`, а bindings находятся в отдельном composition-root provider.
- Исправлена подпись manifest для `estimate_generation.select_normative`: «Выбор нормативов AI-сметчика».
