# Plan 1 / Task 7 — отчет реализации

## Результат

- Все runtime-изменения `EstimateGenerationSession.status`, `state_version` и `resume_status` централизованы в `EstimateGenerationWorkflow` и `EloquentSessionStateStore`.
- Начальное состояние `draft` создается только отдельным `CreateEstimateGenerationSession`; архитектурный тест отдельно фиксирует узкие права этой фабрики.
- Контроллеры, orchestrator, job, document reconciliation, нормативный выбор и обратная связь переведены на application-команды и CAS.
- Добавлены явные события `documents_changed`, `review_updated`, `review_reopened` и полный жизненный цикл:
  `draft -> processing_documents -> ready_to_generate -> generating -> estimate_review_required -> ready_to_apply -> applying -> applied`.
- Upload/retry сначала изменяют агрегат сессии, затем отправляют document job `afterCommit`; завершение, ошибка, ручное игнорирование и повторная обработка документа повторно согласуют агрегат.
- Generation job принимает версию и уникальный идентификатор попытки. Запоздалые handle/failed не меняют новую или терминальную сессию и не отправляют ошибочное уведомление.
- Сохранена единственная точка записи в обычную смету из Task 6; обычные сметы не изменялись.

## TDD

### RED

`php artisan test tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php`

Архитектурный тест обнаружил 11 прямых нарушений в controller/job/orchestrator/workflow/normative/document status services.

`vendor/bin/phpunit tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php`

Контракт событий упал до фиксации новых явных document/review edges.

`vendor/bin/phpunit tests/Unit/EstimateGeneration/Workflow/GenerationAttemptGuardTest.php`

Два теста упали с отсутствующим `GenerationAttemptGuard`.

### GREEN

`vendor/bin/phpunit tests/Unit/EstimateGeneration/Workflow/GenerationAttemptGuardTest.php tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php tests/Unit/EstimateGeneration/Workflow/EstimateGenerationWorkflowTest.php tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php`

Результат: `17 tests, 63 assertions`, PASS.

Архитектурный тест построен на `nikic/php-parser` + `NameResolver`: различает модель сессии и статусы документов/пакетов/справочников, отслеживает типизированные параметры, алиасы переменных, импортированные и FQCN-классы, query chains, property writes, fill/forceFill/update/upsert/create и relation accessors. Миграции, сериализация и query predicates не считаются изменениями состояния.

## Проверки

- `vendor/bin/phpstan analyse ...` для Application, Workflow, двух контроллеров, generation job и измененных сервисов: PASS, no errors.
- `vendor/bin/pint` по всем измененным PHP-файлам: PASS.
- `php -l` по всем измененным PHP-файлам: PASS.
- `git diff --check`: PASS.
- Полный scan модуля: старых runtime-статусов сессии и прямых managed-field writes вне store/factory не осталось. Совпадения `queued/processing/review_required` относятся к документам, пакетам, качеству и нормативным наборам, а не к состоянию сессии.

## DB caveat

Feature-сценарии `EstimateGenerationFlowTest` и `EstimateGenerationQueueTest` обновлены под новый контракт, включая persisted exact lifecycle и stale failed-attempt notification guard, но локально не запускались: они требуют тестовой БД/миграционного bootstrap. Миграции приложения вручную не запускались. Проверка исполняемого workflow выполнена DB-less in-memory store тестами.

## Исправления после code review

- Версия generation attempt стала нижней границей: собственные progress-CAS больше не блокируют retry того же job; иной token, версия ниже стартовой и не-`generating` состояние отклоняются.
- `documents_changed` теперь явно переводит `input_review_required` и `generating` в `processing_documents`, атомарно отзывает attempt token; терминальные document mutations отклоняются до файловых и пакетных side effects.
- Все POST-mutations существующей сессии требуют `state_version`; analyze/generate получили отдельные FormRequest.
- Unrestricted attributes update удален: каждый вызов передает явный набор допустимых исходных состояний, terminal state всегда запрещен.
- Selection и feedback вынесены в application use cases: scoped `lockForUpdate`, version/state policy до первой записи, все package/learning/feedback/CAS записи в одной короткой транзакции.
- Генерация и rebuild резервируют attempt CAS до вычислений; публикация draft/packages/audit выполняется короткой транзакцией только после повторной owner-проверки. Устаревший результат не публикуется.
- Architecture boundary расширен на все управляемые поля сессии; adversarial AST fixtures проверяют aliases, variable attribute arrays, relation chains, dynamic properties, array access, increment/unset и `setAttribute`.

Повторная DB-less проверка: `27 tests, 94 assertions`, PASS. Targeted PHPStan: PASS, no errors. Pint, `php -l`, `git diff --check`: PASS.

## Финальное исправление HTTP/application boundary

- Upload, analyze, generation request, document retry и document ignore вынесены в отдельные application use cases с типизированными result DTO. Контроллеры больше не содержат readiness, workflow, queue dispatch, document mutation или transaction logic.
- Повторный generation POST для активного `generating` attempt идемпотентен: допускает исходную версию запроса после собственных progress-CAS, возвращает текущий snapshot `202` и не создает второй job. `generating` без attempt token отклоняется конфликтом.
- Все прямые Feature-вызовы typed analyze/generate requests и document/review mutations обновлены: передают `state_version`, используют соответствующий FormRequest, container и redirector. DB Feature tests статически проверены (`php -l`), но не запускались согласно DB caveat.
- Добавлен architecture gate на тонкие mutation controllers и отсутствие plain Request consumers для typed endpoints.

Финальная DB-less проверка: `31 tests, 145 assertions`, PASS. Targeted PHPStan: PASS, no errors. Pint, `php -l`, `git diff --check`: PASS.

## Финальный lifecycle cleanup

- Manual ignore теперь выполняется в короткой транзакции: session lock и exact version/state check происходят до document side effect; затем `changed()` отзывает активный generation attempt и переводит агрегат в `processing_documents`, после чего `reconcile()` рассчитывает готовность уже на новом состоянии.
- DB-less workflow tests покрывают ignore/change из `generating`, `input_review_required`, `ready_to_generate`, `estimate_review_required`, `ready_to_apply`; старый attempt token после изменения больше не владеет job.
- Удалены оставшиеся legacy session statuses из Feature fixtures/assertions, E2E job создается с актуальными `state_version` и `generation_attempt_id`.
- Добавлен статический gate, различающий session lifecycle от document/package/quality statuses и запрещающий активные one-argument generation jobs.

Итоговая DB-less проверка: `34 tests, 159 assertions`, PASS. Targeted PHPStan: PASS. Pint, `php -l`, `git diff --check`: PASS. DB Feature tests не запускались.
