# Reports Catalog and Rows Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить `REPORT_INTERNAL_ERROR` пустого attendance-отчёта и повторные ABAC-проверки каталога.

**Architecture:** Drill-down mapping остаётся валидным только для опубликованных колонок конкретного definition. Каталожная авторизация получает request-scoped memoization по permission и каноническим фактам без межзапросного кеша.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, Larastan.

## Global Constraints

- Не ослаблять RBAC/ABAC и scope-проверки.
- Не менять HTTP payload и маршруты.
- Не добавлять миграции и долгоживущий кеш прав.

---

### Task 1: Пустые workforce rows

**Files:**
- Modify: `app/BusinessModules/Features/WorkforceManagement/Reporting/WorkforceReportQueryService.php`
- Test: `tests/Unit/Reporting/Actions/ReportReadHandlersTest.php`

- [ ] Добавить тест полного `GetReportRowsHandler`, в котором definition не содержит `drill`, provider возвращает пустую страницу, а mapping `drill => source_refs` не должен вызывать `REPORT_INTERNAL_ERROR`.
- [ ] Запустить целевой тест и подтвердить RED на валидации output-column.
- [ ] Сделать mapping зависимым от опубликованного контракта либо убрать ложное объявление из общего workforce provider минимальным изменением.
- [ ] Запустить тест и подтвердить GREEN.

### Task 2: Request-scoped memoization каталога

**Files:**
- Modify: `app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelCurrentReportScopeAuthorizer.php`
- Test: `tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizerTest.php`

- [ ] Добавить тест: две definitions с одинаковой permission и scope вызывают ABAC evaluate один раз на каждый уникальный факт; отличающийся permission проверяется отдельно.
- [ ] Запустить тест и подтвердить RED по лишнему числу evaluate.
- [ ] Передать request-local memo в `permissionVector`/`grantedForEveryFact`, ключ формировать из permission и канонических facts; кешировать и разрешения, и отказы.
- [ ] Запустить тест и подтвердить GREEN.

### Task 3: Проверка и доставка

- [ ] Выполнить `php -l` изменённых PHP-файлов, целевые PHPUnit и Larastan изменённых файлов.
- [ ] Выполнить `git diff --check`, личный review-pass и commit `fix[reports]: ускорен каталог и исправлены пустые строки`.
- [ ] Push, PR, merge в `main`, дождаться успешного production workflow.
