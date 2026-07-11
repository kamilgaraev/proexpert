# AI-сметчик МОСТ: Master Roadmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полностью заменить внутренний контур AI-сметчика МОСТ четырьмя последовательно поставляемыми программами, не изменяя существующие обычные сметы.

**Architecture:** `EstimateGeneration` остается единственным модулем AI-сметчика и последовательно получает новый workflow, возобновляемый pipeline, vision/CAD и quality-контур, затем новый React workspace и Filament command center. Каждый план удаляет замененный legacy-код и оставляет `main` в рабочем состоянии без runtime feature flags и fallback-путей.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, Redis/Horizon, S3, Filament, React/Vite/TypeScript, MUI, Vitest, MSW, PHPUnit, Larastan, Python runtime для геометрии PDF/CAD.

## Global Constraints

- Работать непосредственно в `main` короткими атомарными Conventional Commits на русском языке.
- Не запускать миграции локально или на production; создавать и статически проверять их, а применение выполнять отдельным согласованным deployment-шагом.
- Не запускать `npm run build` для `prohelper_admin`.
- Не изменять, не мигрировать и не удалять существующие обычные сметы, их позиции, версии и расчеты.
- Единственная запись AI-модуля в обычные сметы выполняется через идемпотентный `ApplyGeneratedEstimate`.
- Не сохранять legacy API, feature flags, runtime fallback и параллельные реализации после завершения соответствующего плана.
- Все пользовательские PHP-сообщения возвращать через `trans_message(...)` и сохранять русские строки в UTF-8.
- Все новые PHP-файлы должны содержать `declare(strict_types=1);` и соответствовать PSR-12.
- Все файлы AI-сметчика хранить через `FileService` в `org-{organization_id}/...` на S3.
- Перед любым обращением к внешней библиотеке, SDK, API или CLI на этапе реализации использовать Context7.
- После backend-изменений выполнять `php -l`, затронутые PHPUnit-тесты и Larastan.
- После frontend-изменений выполнять `npx tsc --noEmit`, целевые Vitest-тесты и gstack smoke, если URL доступен без запуска запрещенных серверов.

---

## Программа и обязательный порядок

| Порядок | План | Независимый результат | Gate перехода |
| --- | --- | --- | --- |
| 1 | [Workflow и RBAC](2026-07-11-ai-estimator-plan-1-workflow-rbac.md) | Единый status machine, права, snapshot и безопасный apply | Все transition/RBAC/tenant/apply тесты зеленые |
| 2 | [Backend pipeline](2026-07-11-ai-estimator-plan-2-backend-pipeline.md) | Возобновляемые стадии, evidence, usage/error ledger и компактный API | Повторная доставка и частичные ошибки не создают дублей |
| 3 | [AI, чертежи и качество](2026-07-11-ai-estimator-plan-3-ai-drawings-quality.md) | Vision/CAD/sketch, нормативы, цены, benchmark и quality gates | Acceptance-набор достигает зафиксированных порогов |
| 4 | [Frontend и Filament](2026-07-11-ai-estimator-plan-4-frontend-filament.md) | Пошаговый workspace и полный command center | E2E, gstack и операторская приемка зеленые |

Запрещено начинать следующий план до прохождения gate предыдущего. Исключение — только документационная подготовка без изменения runtime-кода.

### Task 1: Зафиксировать baseline перед Plan 1

**Files:**
- Reference: `docs/superpowers/specs/2026-07-11-ai-estimator-complete-rework-design.md`
- Create: `docs/ai-estimator/baselines/2026-07-11-production-baseline.md`
- Create: `tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

**Interfaces:**
- Consumes: текущие маршруты и зависимости `EstimateGeneration`.
- Produces: документированный baseline и автоматический boundary test, запрещающий AI-коду произвольные записи в обычные сметы.

- [ ] **Step 1: Написать падающий architecture test**

```php
<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EstimateGenerationOrdinaryEstimateBoundaryTest extends TestCase
{
    #[Test]
    public function only_apply_use_case_may_reference_ordinary_estimate_models(): void
    {
        $root = app_path('BusinessModules/Addons/EstimateGeneration');
        $allowed = str_replace('\\', '/', $root . '/Application/Apply/ApplyGeneratedEstimate.php');
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $source = (string) file_get_contents($file->getPathname());

            if ($path !== $allowed && preg_match('/App\\\\Models\\\\(Estimate|EstimateItem|EstimateSection)\\b/', $source) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }
}
```

- [ ] **Step 2: Запустить тест и подтвердить исходные нарушения**

Run: `php artisan test tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

Expected: FAIL со списком текущих классов, напрямую использующих модели обычных смет. Этот красный тест остается контрольной целью Plan 1 и не отключается.

- [ ] **Step 3: Снять read-only baseline production**

Run только через разрешенный wrapper `codex-tinker`: подсчитать сессии по статусам, документы, packages, package items, feedback, learning examples, usage events и ошибки без чтения содержимого документов.

Expected: JSON с агрегатами и timestamp выборки; никаких записей в БД.

- [ ] **Step 4: Записать baseline**

Создать `docs/ai-estimator/baselines/2026-07-11-production-baseline.md` со структурой:

```markdown
# AI-сметчик: production baseline

## Объем использования
## Статусы сессий
## Документы и позиции
## Ошибки и очереди
## Обратная связь и обучение
## Нормативы и цены
## Известные ограничения
## Метрики, которые пока не собираются
```

- [ ] **Step 5: Зафиксировать baseline и красный boundary test**

```bash
git add tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php docs/ai-estimator/baselines/2026-07-11-production-baseline.md
git commit -m "test[lk]: зафиксированы границы и baseline AI-сметчика"
```

### Task 2: Выполнить Plan 1 и принять gate

**Files:**
- Execute: `docs/superpowers/plans/2026-07-11-ai-estimator-plan-1-workflow-rbac.md`
- Verify: `tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

**Interfaces:**
- Consumes: утвержденный design spec и baseline.
- Produces: workflow API и permission contract, обязательные для Plan 2–4.

- [ ] **Step 1: Выполнить все checkbox Plan 1 в порядке документа**

Run: применять `superpowers:subagent-driven-development` либо `superpowers:executing-plans`.

Expected: каждый task имеет красный тест, минимальную реализацию, зеленую проверку и отдельный коммит.

- [ ] **Step 2: Выполнить gate-команды Plan 1**

```bash
php artisan test tests/Unit/EstimateGeneration/Workflow tests/Feature/EstimateGeneration/EstimateGenerationWorkflowApiTest.php tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Application app/BusinessModules/Addons/EstimateGeneration/Domain app/BusinessModules/Addons/EstimateGeneration/Http --memory-limit=1G
```

Expected: PHPUnit `0 failures`; PHPStan `No errors`.

- [ ] **Step 3: Обновить roadmap checkpoint**

Добавить в конец этого файла дату, commit SHA, результаты проверок и замечания Plan 1.

- [ ] **Step 4: Зафиксировать checkpoint**

```bash
git add docs/superpowers/plans/2026-07-11-ai-estimator-master-roadmap.md
git commit -m "docs[lk]: закрыт этап workflow AI-сметчика"
```

### Task 3: Выполнить Plan 2 и принять gate

**Files:**
- Execute: `docs/superpowers/plans/2026-07-11-ai-estimator-plan-2-backend-pipeline.md`
- Verify: `tests/Feature/EstimateGeneration/Pipeline`

**Interfaces:**
- Consumes: `EstimateGenerationWorkflow`, `SessionSnapshotData`, `AvailableAction` из Plan 1.
- Produces: `PipelineRunner`, checkpoints, evidence graph, usage/error ledger и v2 snapshot.

- [ ] **Step 1: Выполнить Plan 2 полностью**

Expected: старый orchestrator удален только после переноса всех вызываемых стадий.

- [ ] **Step 2: Выполнить gate-команды Plan 2**

```bash
php artisan test tests/Unit/EstimateGeneration/Pipeline tests/Feature/EstimateGeneration/Pipeline tests/Feature/EstimateGeneration/EstimateGenerationUsageLedgerTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Application app/BusinessModules/Addons/EstimateGeneration/Pipeline app/BusinessModules/Addons/EstimateGeneration/Observability --memory-limit=1G
```

Expected: `0 failures`; повторная доставка job и повторный запуск stage не изменяют количество результатов.

- [ ] **Step 3: Записать checkpoint и commit SHA**

```bash
git add docs/superpowers/plans/2026-07-11-ai-estimator-master-roadmap.md
git commit -m "docs[lk]: закрыт этап backend pipeline AI-сметчика"
```

### Task 4: Выполнить Plan 3 и принять quality gate

**Files:**
- Execute: `docs/superpowers/plans/2026-07-11-ai-estimator-plan-3-ai-drawings-quality.md`
- Create during execution: `tests/Fixtures/EstimateGeneration/benchmarks/manifest.json`
- Verify: `tests/Benchmark/EstimateGeneration`

**Interfaces:**
- Consumes: stage contracts, evidence и usage ledger Plan 2.
- Produces: normalized building model, vision/CAD/sketch pipeline, normative decisions, benchmark reports и readiness.

- [ ] **Step 1: Выполнить Plan 3 полностью**

Expected: `cad_placeholder_v1`, `RuleBasedDrawingAnalysisProvider` и постоянный rule-based reranker удалены после появления проверенной замены.

- [ ] **Step 2: Запустить закрытый acceptance benchmark**

Run: `php artisan estimate-generation:benchmark --dataset=acceptance --format=json --output=storage/app/benchmarks/acceptance.json`

Expected:

```text
work_recall >= 0.90
normative_top3 >= 0.95
evidenced_applicable_items = 1.00
technical_success_rate >= 0.98
```

- [ ] **Step 3: Выполнить unit/feature/static gates**

```bash
php artisan test tests/Unit/EstimateGeneration/Vision tests/Unit/EstimateGeneration/Normatives tests/Unit/EstimateGeneration/Quality tests/Feature/EstimateGeneration/Benchmark
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Vision app/BusinessModules/Addons/EstimateGeneration/Normatives app/BusinessModules/Addons/EstimateGeneration/Quality --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

- [ ] **Step 4: Записать benchmark SHA и checkpoint**

```bash
git add docs/superpowers/plans/2026-07-11-ai-estimator-master-roadmap.md
git commit -m "docs[lk]: закрыт этап качества AI-сметчика"
```

### Task 5: Выполнить Plan 4 и принять пользовательский gate

**Files:**
- Execute backend: `docs/superpowers/plans/2026-07-11-ai-estimator-plan-4-frontend-filament.md`
- Execute frontend: `../prohelper_admin/src/features/estimate-generation`

**Interfaces:**
- Consumes: окончательный v2 API, building model, review и observability contracts.
- Produces: новый workspace и Filament command center; старый monolith удален.

- [ ] **Step 1: Выполнить frontend tasks Plan 4**

Expected: `EstimateGenerationWorkspacePage.tsx` заменен route shell; старые presentation helpers удалены после миграции тестов.

- [ ] **Step 2: Выполнить frontend gate**

```bash
cd ../prohelper_admin
npx tsc --noEmit
npx vitest run src/features/estimate-generation
```

Expected: TypeScript `0 errors`; Vitest `0 failed`.

- [ ] **Step 3: Выполнить Filament gate**

```bash
cd ../prohelper
php artisan test tests/Feature/Filament/EstimateGeneration
vendor/bin/phpstan analyse app/Filament/Resources/EstimateGeneration app/Filament/Pages/EstimateGeneration --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

- [ ] **Step 4: Выполнить browser smoke**

Проверить через gstack без запуска запрещенных серверов:

1. создание сессии;
2. загрузку изображения и PDF;
3. подтверждение масштаба;
4. проверку геометрии;
5. review нормативов;
6. применение;
7. Filament dashboard, usage, errors и dataset.

Expected: нет console errors и неожиданных 4xx/5xx; обычные сметы до apply не меняются.

- [ ] **Step 5: Зафиксировать checkpoint**

```bash
git add docs/superpowers/plans/2026-07-11-ai-estimator-master-roadmap.md
git commit -m "docs[lk]: завершена программа переработки AI-сметчика"
```

## Финальная приемка программы

- [ ] Все четыре плана выполнены и имеют checkpoint SHA.
- [ ] Legacy AI routes/classes/UI отсутствуют.
- [ ] Обычные сметы не меняются никакими AI-endpoints, кроме создания через `ApplyGeneratedEstimate`.
- [ ] Повторный apply возвращает тот же `estimate_id` и не создает записи.
- [ ] Acceptance benchmark проходит пороги.
- [ ] Filament показывает токены, стоимость, ошибки, очереди, datasets и benchmark.
- [ ] Документация workflow, ролей, статусов, UX и поддержки актуализирована.

## Checkpoints

| План | Commit SHA | Проверки | Дата |
| --- | --- | --- | --- |
| Plan 1 | — | — | — |
| Plan 2 | — | — | — |
| Plan 3 | — | — | — |
| Plan 4 | — | — | — |
