# Task 2 — RBAC и жизненный цикл договоров

Статус: DONE

## Коммиты

- Backend implementation source before report squash: `39803492` — `fix[lk]: закрыты права и жизненный цикл договоров`; итоговый hash объединённого backend-коммита указан в handoff.
- Admin: `9b179a99` — `fix[lk]: синхронизирован жизненный цикл договоров`

## RED evidence

- Исходный backend-тест подтверждал `403` для пользователя без `contracts.create`.
- Исходный backend-набор не мог пройти до появления lifecycle service; маршруты не были закрыты `contracts.view`, а legacy `DELETE` вызывал физическое удаление.
- Исходный frontend-тест падал из-за отсутствующего `contractPermissions`.
- Дополнительный RED после восстановления задачи: `contractPermissions.test.ts` — 7 падений `getContractLifecycleActions is not a function`, что подтвердило отсутствие матрицы доступных UI-действий.

## Реализация

- CRUD и transition routes закрыты каноническими правами в глобальном и project-based API.
- Дублирующий resource route из `catalogs.php` удалён.
- Добавлено право `contracts.archive` в ModuleList, перевод и все роли, ранее имевшие `contracts.delete`.
- Добавлен статус `archived` и транзакционный `ContractLifecycleService` с `lockForUpdate`, полной матрицей переходов и записью события состояния.
- Legacy `DELETE` безопасно отвечает `409`; архивирование выполняется через `POST .../archive`.
- `UpdateContractRequest` запрещает поле `status`; DTO сохраняет текущий статус. FormRequest дополнительно проверяют канонические права.
- Admin использует типизированный lifecycle API, показывает только допустимые действия для текущего статуса и отдельное подтверждение с основанием.
- Архивирование в таблице и карточке отображается только для `draft`, `completed` и `terminated`.
- Общая форма и быстрое редактирование больше не меняют `status`; смена статуса выполняется только lifecycle actions.
- Права актов заменены на `contracts.performance_acts.*` через единый typed mapping.

## GREEN evidence

### Backend

- `APP_ENV=testing php artisan test tests/Feature/Api/V1/Admin/ContractPermissionAndLifecycleTest.php` — 6 passed, 52 assertions, 0 warnings.
- `vendor/bin/phpstan analyse ... --memory-limit=1G --no-progress` — 0 errors.
- `php -l` для всех затронутых PHP-файлов — syntax OK.
- `vendor/bin/pint --test ...` — 12 files PASS.
- `git diff --check` — clean.
- JSON parsing для ModuleList и RoleDefinitions — OK.

Для чистого PHPUnit-прогона временно создавался игнорируемый `.env` только с `APP_ENV=testing`; после проверки файл удалён.

### Admin

- `npx vitest run src/pages/Projects/contractPermissions.test.ts src/services/contractService.test.ts` — 2 files, 13 tests passed.
- `npx tsc --noEmit` — exit 0.
- `npx eslint <changed files>` — exit 0.
- `npx prettier --check ...` — all matched files formatted.
- `git diff --check` — clean.

## Self-review

- Проверены обе группы маршрутов и отсутствие второго resource catalog route.
- Проверены права archive во всех ролях, содержащих delete.
- Проверено отсутствие literals `contracts.update` и `contracts.acts.*` в изменяемом потоке.
- Проверено, что generic update не отправляет и не принимает `status`.
- Проверено, что архивированный договор не получает новых lifecycle actions.
- Миграции, подключения к БД, build, dev server и browser не запускались согласно ограничениям задачи.

## Concerns

Нет блокирующих замечаний. Визуальный browser smoke не выполнялся по прямому запрету задачи; перед релизом остаётся стандартная ручная проверка диалогов и адаптивной раскладки в staging.

## Review fixes

- Project-based lifecycle transition использует раздельные параметры `project` и `contract`; HTTP-регрессия подтверждает отсутствие подмены идентификаторов и проверку принадлежности договора проекту.
- Создание договора игнорирует входной `status` и всегда сохраняет `draft`; форма и тип запроса создания больше не отправляют статус.
- Маршруты актов закрыты каноническими правами `contracts.performance_acts.*`, а FormRequest передают в `AuthorizationService` контекст организации и проекта.
- Право `contracts.archive` синхронизировано между модулем и JSON-конфигурацией.
- Backend GREEN: `ContractPermissionAndLifecycleTest` — 12 тестов, 100 assertions; PHPStan — 0 ошибок; Pint и `php -l` — PASS; JSON-конфигурации корректны.
- Admin GREEN: 3 файла Vitest, 27 тестов; TypeScript — exit 0; ESLint — 0 ошибок, 4 ранее существовавших предупреждения в `ProjectContractForm.tsx`; форматирование и `git diff --check` — PASS.
- Хеши отдельных review-fix коммитов backend и admin указаны в итоговом handoff.
