# Отчёт по задаче 14: эксплуатационная готовность AI-сметчика

Статус: **DONE_WITH_DEPLOYMENT_GATE**

## Реализовано

- Дополнен сквозной пользовательский workflow: маршруты рабочего места, семь шагов, асинхронный прогресс, проверка геометрии/масштаба/объёмов/evidence, нормативная проверка, readiness и идемпотентный `apply` в новую обычную смету.
- Дополнена матрица ролей и статусов шестью отдельными правами Filament и least-privilege ролями `support_operator`, `qa_engineer`, `security_auditor`, `super_admin`.
- Создано описание Filament UX: дашборд и фильтры, timeline сессии, usage/cost, failures, queues/checkpoints, datasets, benchmark, settings/audit, permission denied и privacy rules.
- Созданы operational и cost/errors runbooks: daily ops, retry/cancel/stuck, provider outage, benchmark regression, escalation, бюджеты, приватность, retention, S3/queue/scheduler зависимости.
- Подготовлен точный GStack post-deploy gate с SHA-проверкой, обезличенными PDF/JPEG/PNG fixtures, пользовательским E2E, Filament smoke, console/network/privacy assertions и обязательным evidence.
- Общие Filament navigation/auth тесты переведены с `Tests\TestCase::RefreshDatabase` на DB-less PHPUnit container и расширены контрактами AI-сметчика.
- Обычные сметы, их ресурсы, модели, таблицы и UI не изменялись.

## TDD evidence

- Первый DB-less RED после изменения тестов выявил неполную test container wiring. Контракт explicit authorization для общих ресурсов оставлен в прежней области, а AI-specific permissions проверяются отдельной least-privilege матрицей и прямыми `canAccess`/`canViewAny` assertions.
- После container wiring: 12 passed, 382 assertions.
- После Pint: повторно 12 passed, 382 assertions.

До DB-less конверсии один baseline-запуск старых common-тестов через `php artisan test` неожиданно активировал `Tests\TestCase::RefreshDatabase` и начал миграции in-memory SQLite. Процесс остановлен после обнаружения. Внешняя, локальная постоянная и production БД не использовались. Все последующие тесты этой задачи DB-less.

## Финальные проверки backend

- `php artisan test tests/Feature/Filament/EstimateGeneration tests/Feature/Filament/SystemAdminNavigationTest.php tests/Feature/Filament/SystemAdminResourceAuthorizationTest.php`: **133 passed, 1325 assertions**.
- `vendor/bin/phpstan analyse app/Filament/Resources/EstimateGeneration app/Filament/Pages/EstimateGeneration app/Filament/Widgets/EstimateGeneration app/BusinessModules/Addons/EstimateGeneration/Settings --memory-limit=1G --no-progress`: **OK, no errors**.
- `vendor/bin/pint --test` по двум изменённым common-тестам: **PASS** после форматирования.
- `php -l` по двум тестам: **PASS**.
- Миграции проекта, seeders, tinker и DB-команды для проверки функциональности не запускались; исключение первого непреднамеренного in-memory SQLite baseline описано выше.

## Финальные проверки admin frontend

- `npx vitest run src/features/estimate-generation src/components/layout/SidebarMenu.test.tsx`: **16 files, 207 tests passed**.
- `npx tsc --noEmit --pretty false -p tsconfig.estimate-generation.json`: **PASS**.
- `npx eslint src/features/estimate-generation src/components/layout/SidebarMenu.tsx src/components/layout/SidebarMenu.test.tsx`: **PASS**, только информационное предупреждение об устаревшем `caniuse-lite`.
- Полный `tsc`, build и dev server не запускались.

## Legacy paths

Удаление проверено по точным путям и ссылкам, а не по общему имени нового feature page:

- admin: старые `src/pages/Estimates/EstimateGenerationWorkspacePage.tsx`, `estimateGenerationPresentation.ts`, test и `src/services/estimateGenerationService.ts` отсутствуют, не tracked; поиск точных старых ссылок завершился exit 1;
- backend: старый `app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php` и три legacy pages отсутствуют, не tracked; поиск старого namespace завершился exit 1.

Общий шаблон `EstimateGenerationWorkspacePage|...` закономерно находит актуальный `src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`, поэтому не является корректной проверкой удаления legacy path.

## Deployment gate

Live GStack smoke не выполнялся и не засчитывается: доступный production release не подтверждён как совпадающий с локальными backend/admin SHA. До совпадения SHA статус браузерного gate — **BLOCKED_BY_DEPLOYMENT**.

После развёртывания выполнить `docs/runbooks/ai-estimator-operations.md`, сохранить SHA, session ID, созданный estimate ID, annotated screenshots, console/network output и результат повторного apply. Отдельно подтвердить workers четырёх очередей, scheduler recovery/finalization, приватный S3, provider readiness, миграции PostgreSQL и отсутствие чувствительных данных.

## Ограничения

- Production behavior, очередь, scheduler, provider, S3 и PostgreSQL DDL не заявляются как проверенные этой локальной задачей.
- Скрипт GStack является готовым post-deploy checklist, а не свидетельством уже выполненного smoke.
- Новые markdown-файлы игнорируются глобальным `*.md` и должны быть добавлены в commit только точечным `git add -f`; protected untracked `.cbmignore`, `.codebase-memory/` и `tmp/` не входят в задачу.
