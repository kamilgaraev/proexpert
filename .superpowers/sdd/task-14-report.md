# Отчёт по задаче 14: эксплуатационная готовность AI-сметчика

Статус: **DONE_WITH_DEPLOYMENT_GATE**

## Реализовано

- Дополнен сквозной пользовательский workflow: маршруты рабочего места, семь шагов, асинхронный прогресс, проверка геометрии/масштаба/объёмов/evidence, нормативная проверка, readiness и идемпотентный `apply` в новую обычную смету.
- Дополнена матрица ролей и статусов шестью отдельными правами Filament и least-privilege ролями `support_operator`, `qa_engineer`, `security_auditor`, `super_admin`.
- Создано описание Filament UX: дашборд и фильтры, timeline сессии, usage/cost, failures, queues/checkpoints, datasets, benchmark, settings/audit, permission denied и privacy rules.
- Созданы operational и cost/errors runbooks: daily ops, retry/cancel/stuck, provider outage, benchmark regression, escalation, бюджеты, приватность, retention, S3/queue/scheduler зависимости.
- Подготовлен GStack post-deploy gate с fail-closed проверкой атомарного root-owned manifest пары backend/admin, обезличенными PDF/JPEG/PNG fixtures, пользовательским E2E, Filament smoke, console/network/privacy assertions и обязательным evidence.
- Общие Filament navigation/auth тесты переведены с `Tests\TestCase::RefreshDatabase` на DB-less PHPUnit container и расширены контрактами AI-сметчика.
- Обычные сметы, их ресурсы, модели, таблицы и UI не изменялись.

## TDD evidence

- Review-fix RED: `tests/Architecture/ai-estimator-release-gate.sh` завершился exit 1, потому что доверенный verifier отсутствовал, а runbook принимал операторские deployed SHA.
- Review-fix GREEN: тот же тест проверил `bash -n`, fail-closed негативные fixtures и положительный full-SHA fixture без сети и браузера: **PASS**.
- Re-review RED: source production verifier завершил тест через старый безусловный entrypoint, а две независимые аттестации не обеспечивали атомарную пару и invalidation перед активацией.
- Re-review GREEN: behavioral test вызывает тот же production main через source-only seam и проверяет exact exit `78`, строгую pair schema, generation, mismatch, owner/mode/symlink, запрет env path override, положительную пару и fail-before-GStack sentinel: **PASS**.
- Third review RED: browser flow имел только pre-check и не закреплял generation до завершения user/Filament evidence; новый тест завершился из-за отсутствующего stable-release finalizer.
- Third review GREEN: behavioral flow проверяет pre/post output одного production verifier. Смена generation, удаление manifest и rollback SHA-пары дают nonzero без `PASS`; неизменная точная пара создаёт атомарный `PASS`: **PASS**.
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

Live GStack smoke не выполнялся и не засчитывается. Проверка текущих workflows показала: backend deployment сохраняет только короткий image/Sentry tag, admin deployment не публикует release SHA. Доверенного источника полной идентичности обоих активных компонентов сейчас нет, поэтому статус браузерного gate — **BLOCKED_BY_DEPLOYMENT**.

После re-review две независимо обновляемые аттестации заменены единым `/var/lib/most-active-release/smoke-ready.manifest`. Добавлены:

- строгая четырёхстрочная схема manifest с версией, положительным generation и обоими полными lowercase SHA;
- production constants путей без переопределения через env и source-only seam для behavioral проверки того же main;
- общий root-owned `flock` для backend/admin deploy и rollback;
- обязательное удаление public manifest и active SHA изменяемого компонента до любой activation;
- обновление active SHA только после доказанной связи фактического artifact с SHA и public readiness/propagation;
- атомарная публикация общей пары только при двух валидных active SHA; любой сбой после invalidation оставляет gate закрытым;
- монотонный generation под общим lock без сброса или повторного использования при rollback;
- exit code `78` при отсутствующей/небезопасной библиотеке, отсутствующем, небезопасном, некорректном или несовпадающем manifest до любого запуска GStack;
- обязательные pre/post captures нормализованного `generation/backend/admin`, byte-for-byte finalization после последнего browser evidence и удаление stale `PASS` до pre-check;
- атомарное создание `PASS` summary только при неизменных generation и reviewed SHA-паре; before/after outputs и reviewed SHA сохраняются как evidence;
- безопасный fallback `${GSTACK_BROWSE:-$HOME/.codex/skills/gstack/browse/dist/browse}` при `set -u`;
- исполняемый behavioral test `tests/Architecture/ai-estimator-release-gate.sh` без сети и браузера.

Описанный coordinator hook ещё не установлен. В частности, admin pipeline пока не связывает полный SHA с активным bundle и не подтверждает его через public propagation/readiness. До внедрения обоих activation hooks pair manifest публиковать нельзя и gate остаётся закрытым. После успешной аттестации выполнить `docs/runbooks/ai-estimator-operations.md`, сохранить SHA, generation, session ID, созданный estimate ID, annotated screenshots, console/network output и результат повторного apply. Отдельно подтвердить workers четырёх очередей, scheduler recovery/finalization, приватный S3, provider readiness, миграции PostgreSQL и отсутствие чувствительных данных.

## Ограничения

- Production behavior, очередь, scheduler, provider, S3 и PostgreSQL DDL не заявляются как проверенные этой локальной задачей.
- Скрипт GStack является готовым post-deploy checklist, а не свидетельством уже выполненного smoke; текущий deployment намеренно не проходит новый fail-closed gate до настройки аттестаций.
- Новые markdown-файлы игнорируются глобальным `*.md` и должны быть добавлены в commit только точечным `git add -f`; protected untracked `.cbmignore`, `.codebase-memory/` и `tmp/` не входят в задачу.
