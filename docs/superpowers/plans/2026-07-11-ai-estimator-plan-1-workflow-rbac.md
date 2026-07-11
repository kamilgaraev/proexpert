# AI-сметчик МОСТ: Workflow и RBAC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить текущие разрозненные статусы AI-сметчика единым конечным автоматом, разделить права, обеспечить tenant isolation и создать единственную идемпотентную точку применения результата в новую обычную смету.

**Architecture:** Доменный workflow принимает команды и выполняет только разрешенные переходы с optimistic locking. HTTP-слой получает `SessionSnapshotData` с доступными действиями, а `ApplyGeneratedEstimate` под транзакционной блокировкой делегирует существующему persistence-коду создание новой обычной сметы и фиксирует связь в AI-сессии.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, JWT admin guard, `AuthorizationService`, `AdminResponse`, PHPUnit, Larastan.

## Global Constraints

- Работать непосредственно в `main`; один task — один атомарный commit.
- Не запускать миграции; только создавать и статически проверять.
- Не изменять существующие обычные сметы, позиции, версии или их данные.
- Обычная смета создается только через `Application/Apply/ApplyGeneratedEstimate.php`.
- Не сохранять старые статусы, legacy routes, runtime fallback или feature flags после завершения плана.
- Все новые PHP-файлы содержат `declare(strict_types=1);` и соответствуют PSR-12.
- Все пользовательские сообщения возвращаются через `trans_message(...)`.
- Все organization/project/session связи проверяются на backend независимо от route model binding.
- Миграция может удалить тестовые данные только из таблиц `estimate_generation_*`; таблицы обычных смет запрещено очищать или изменять.

---

## Структура файлов

Новые доменные файлы:

```text
app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/
  EstimateGenerationStatus.php
  EstimateGenerationAction.php
  EstimateGenerationEvent.php
  InvalidEstimateGenerationTransition.php
  EstimateGenerationTransitionMap.php
  EstimateGenerationWorkflow.php

app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/
  SessionSnapshotData.php
  BuildSessionSnapshot.php

app/BusinessModules/Addons/EstimateGeneration/Application/Apply/
  GeneratedEstimateWriter.php
  LaravelGeneratedEstimateWriter.php
  ApplyGeneratedEstimate.php
  ApplyGeneratedEstimateResult.php
```

Контроллеры только авторизуют, вызывают application use case и возвращают `AdminResponse`.

### Task 1: Ввести enum статусов, пользовательских действий и доменных событий

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/EstimateGenerationStatus.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/EstimateGenerationAction.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/EstimateGenerationEvent.php`
- Create: `tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php`

**Interfaces:**
- Consumes: строковое поле `estimate_generation_sessions.status`.
- Produces: `EstimateGenerationStatus::from(string)` и `EstimateGenerationAction` для всех следующих tasks.

- [ ] **Step 1: Написать падающий enum contract test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationAction;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationStatusTest extends TestCase
{
    #[Test]
    public function it_exposes_the_complete_clean_cut_status_contract(): void
    {
        self::assertSame([
            'draft',
            'processing_documents',
            'input_review_required',
            'ready_to_generate',
            'generating',
            'estimate_review_required',
            'ready_to_apply',
            'applying',
            'applied',
            'failed',
            'cancelled',
            'archived',
        ], array_column(EstimateGenerationStatus::cases(), 'value'));

        self::assertSame([
            'upload_documents',
            'start_document_processing',
            'confirm_input',
            'generate',
            'review',
            'apply',
            'retry',
            'cancel',
            'archive',
        ], array_column(EstimateGenerationAction::cases(), 'value'));

        self::assertSame([
            'start_document_processing',
            'documents_ready',
            'documents_need_review',
            'input_confirmed',
            'generation_started',
            'generation_needs_review',
            'generation_ready',
            'apply_started',
            'apply_completed',
            'failed',
            'retried',
            'cancelled',
            'archived',
        ], array_column(EstimateGenerationEvent::cases(), 'value'));
    }
}
```

- [ ] **Step 2: Запустить тест и подтвердить красное состояние**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php`

Expected: FAIL с `Class "...EstimateGenerationStatus" not found`.

- [ ] **Step 3: Реализовать enums**

```php
<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

enum EstimateGenerationStatus: string
{
    case Draft = 'draft';
    case ProcessingDocuments = 'processing_documents';
    case InputReviewRequired = 'input_review_required';
    case ReadyToGenerate = 'ready_to_generate';
    case Generating = 'generating';
    case EstimateReviewRequired = 'estimate_review_required';
    case ReadyToApply = 'ready_to_apply';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Applied, self::Cancelled, self::Archived], true);
    }
}
```

Создать `EstimateGenerationAction` с перечисленными тестом cases. Создать отдельный `EstimateGenerationEvent` с перечисленными внутренними событиями. HTTP actions преобразуются application use cases в events; jobs публикуют только events и никогда не имитируют пользовательское действие.

- [ ] **Step 4: Запустить тесты**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow tests/Unit/EstimateGeneration/Workflow/EstimateGenerationStatusTest.php
git commit -m "feat[lk]: введены статусы workflow AI-сметчика"
```

### Task 2: Реализовать карту переходов и optimistic workflow

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/InvalidEstimateGenerationTransition.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/EstimateGenerationTransitionMap.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow/EstimateGenerationWorkflow.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationSession.php`
- Create: `tests/Unit/EstimateGeneration/Workflow/EstimateGenerationWorkflowTest.php`

**Interfaces:**
- Consumes: `EstimateGenerationStatus`, `EstimateGenerationEvent`, persisted `state_version`.
- Produces: `transition(EstimateGenerationSession $session, EstimateGenerationEvent $event, array $attributes = []): EstimateGenerationSession`.

- [ ] **Step 1: Написать тест разрешенного и запрещенного перехода**

```php
#[Test]
public function generate_moves_ready_session_to_generating_and_increments_version(): void
{
    $session = new EstimateGenerationSession([
        'status' => EstimateGenerationStatus::ReadyToGenerate,
        'state_version' => 4,
    ]);
    $session->exists = true;

    $updated = app(EstimateGenerationWorkflow::class)->transition(
        $session,
        EstimateGenerationEvent::GenerationStarted,
    );

    self::assertSame(EstimateGenerationStatus::Generating, $updated->status);
    self::assertSame(5, $updated->state_version);
}

#[Test]
public function apply_is_rejected_before_ready_to_apply(): void
{
    $this->expectException(InvalidEstimateGenerationTransition::class);

    app(EstimateGenerationWorkflow::class)->transition(
        new EstimateGenerationSession(['status' => EstimateGenerationStatus::Draft]),
        EstimateGenerationEvent::ApplyStarted,
    );
}
```

Для unit-теста замокать repository update через `EstimateGenerationSession::setConnectionResolver(...)` либо выделить `SessionStateStore` с in-memory fake. Предпочтительный production interface:

```php
interface SessionStateStore
{
    public function compareAndSet(
        int $sessionId,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession;
}
```

- [ ] **Step 2: Запустить тест**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/EstimateGenerationWorkflowTest.php`

Expected: FAIL из-за отсутствующих workflow-классов.

- [ ] **Step 3: Реализовать точную карту переходов**

```php
private const TRANSITIONS = [
    'draft' => [
        'start_document_processing' => 'processing_documents',
        'cancelled' => 'cancelled',
    ],
    'processing_documents' => [
        'documents_ready' => 'ready_to_generate',
        'documents_need_review' => 'input_review_required',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ],
    'input_review_required' => [
        'input_confirmed' => 'ready_to_generate',
        'retried' => 'processing_documents',
        'cancelled' => 'cancelled',
    ],
    'ready_to_generate' => [
        'generation_started' => 'generating',
        'cancelled' => 'cancelled',
    ],
    'generating' => [
        'generation_needs_review' => 'estimate_review_required',
        'generation_ready' => 'ready_to_apply',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ],
    'estimate_review_required' => [
        'generation_ready' => 'ready_to_apply',
        'generation_started' => 'generating',
        'cancelled' => 'cancelled',
    ],
    'ready_to_apply' => [
        'apply_started' => 'applying',
        'generation_started' => 'generating',
        'cancelled' => 'cancelled',
    ],
    'applying' => [
        'apply_completed' => 'applied',
        'failed' => 'failed',
    ],
    'failed' => [
        'retried' => '@resume_status',
        'cancelled' => 'cancelled',
        'archived' => 'archived',
    ],
    'cancelled' => ['archived' => 'archived'],
    'applied' => ['archived' => 'archived'],
];
```

`EstimateGenerationWorkflow` обязан использовать `SessionStateStore::compareAndSet`; update с несовпавшим `state_version` выбрасывает `StaleEstimateGenerationState` и ничего не изменяет. При событии `Failed` workflow сохраняет предыдущий активный статус в `resume_status`. Событие `Retried` разрешает возврат только в `processing_documents`, `generating` или `applying`, затем очищает `resume_status`; произвольное значение отклоняется.

- [ ] **Step 4: Добавить casts модели**

```php
protected $casts = [
    // существующие casts сохранить
    'status' => EstimateGenerationStatus::class,
    'state_version' => 'integer',
    'applied_estimate_id' => 'integer',
    'applied_at' => 'immutable_datetime',
    'resume_status' => EstimateGenerationStatus::class,
];
```

- [ ] **Step 5: Запустить workflow tests**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow`

Expected: PASS, включая stale-version test.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Domain/Workflow app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationSession.php tests/Unit/EstimateGeneration/Workflow
git commit -m "feat[lk]: добавлен конечный автомат AI-сметчика"
```

### Task 3: Подготовить clean-cut migration AI-сессий

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php`
- Create: `tests/Unit/EstimateGeneration/Migrations/EstimateGenerationWorkflowMigrationTest.php`

**Interfaces:**
- Consumes: существующую `estimate_generation_sessions`.
- Produces: `state_version`, `applied_estimate_id`, `applied_at`, `state_changed_at`, `failure_code`, `resume_status`; удаляет только AI test rows перед введением нового status check.

- [ ] **Step 1: Написать статический migration test**

```php
#[Test]
public function migration_never_mutates_ordinary_estimate_tables(): void
{
    $source = (string) file_get_contents($this->migrationPath());

    self::assertStringContainsString("Schema::table('estimate_generation_sessions'", $source);
    self::assertStringNotContainsString("Schema::table('estimates'", $source);
    self::assertStringNotContainsString("DB::table('estimates'", $source);
    self::assertStringNotContainsString("DB::table('estimate_items'", $source);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Migrations/EstimateGenerationWorkflowMigrationTest.php`

Expected: FAIL, migration отсутствует.

- [ ] **Step 3: Создать migration**

Migration `up()` должна:

```php
DB::table('estimate_generation_feedback')->delete();
DB::table('estimate_generation_audit_events')->delete();
DB::table('estimate_generation_package_items')->delete();
DB::table('estimate_generation_packages')->delete();
DB::table('estimate_generation_document_facts')->delete();
DB::table('estimate_generation_document_pages')->delete();
DB::table('estimate_generation_documents')->delete();
DB::table('estimate_generation_sessions')->delete();

Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
    $table->unsignedBigInteger('state_version')->default(0);
    $table->foreignId('applied_estimate_id')->nullable()->constrained('estimates')->nullOnDelete();
    $table->timestampTz('applied_at')->nullable();
    $table->timestampTz('state_changed_at')->nullable();
    $table->string('failure_code', 100)->nullable();
    $table->string('resume_status', 40)->nullable();
    $table->unique('applied_estimate_id');
});
```

До реализации migration сверить реальные FK и порядок таблиц. Добавить удаления для всех `estimate_generation_*`, ссылающихся на session, но не удалять normative/price/training datasets и не обращаться к обычным сметам.

- [ ] **Step 4: Проверить syntax и static test**

```bash
php -l app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php
php artisan test tests/Unit/EstimateGeneration/Migrations/EstimateGenerationWorkflowMigrationTest.php
```

Expected: `No syntax errors`, PASS. Миграцию не запускать.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php tests/Unit/EstimateGeneration/Migrations/EstimateGenerationWorkflowMigrationTest.php
git commit -m "feat[lk]: подготовлена новая схема workflow AI-сметчика"
```

### Task 4: Разделить пользовательские permissions

**Files:**
- Modify: `config/RoleDefinitions/lk/organization_admin.json`
- Modify: `config/RoleDefinitions/project/parent_administrator.json`
- Modify: `lang/ru/permissions.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/routes.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Requests/*.php`
- Create: `tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php`
- Modify: `tests/Unit/Authorization/PermissionTranslatorTest.php` or nearest existing permission translation contract test

**Interfaces:**
- Consumes: `RoleMiddleware`, `AuthorizationService` and JSON role definitions.
- Produces: eight user permissions and explicit authorization per endpoint.

- [ ] **Step 1: Написать parameterized RBAC test**

```php
public static function protectedActions(): array
{
    return [
        'view' => ['GET', '/api/v1/admin/projects/%d/estimate-generation/sessions', 'estimate_generation.view'],
        'create' => ['POST', '/api/v1/admin/projects/%d/estimate-generation/sessions', 'estimate_generation.create'],
        'upload' => ['POST', '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/documents', 'estimate_generation.upload_documents'],
        'generate' => ['POST', '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/generate', 'estimate_generation.generate'],
        'review' => ['POST', '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/review-decisions', 'estimate_generation.review'],
        'export' => ['GET', '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/export', 'estimate_generation.export'],
        'apply' => ['POST', '/api/v1/admin/projects/%d/estimate-generation/sessions/%d/apply', 'estimate_generation.apply'],
    ];
}
```

Каждый dataset case выполняется пользователем без permission и ожидает 403, затем с permission и ожидает не 403.

- [ ] **Step 2: Запустить RBAC test**

Run: `php artisan test tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php`

Expected: FAIL, текущий `admin.access` пропускает операции без доменных прав.

- [ ] **Step 3: Добавить permissions и русские названия**

```php
'estimate_generation' => 'AI-сметчик',
'estimate_generation.view' => 'Просмотр AI-смет',
'estimate_generation.create' => 'Создание AI-смет',
'estimate_generation.upload_documents' => 'Загрузка документов AI-сметы',
'estimate_generation.generate' => 'Запуск генерации AI-сметы',
'estimate_generation.review' => 'Проверка решений AI-сметчика',
'estimate_generation.select_normative' => 'Выбор нормативов AI-сметы',
'estimate_generation.export' => 'Экспорт AI-сметы',
'estimate_generation.apply' => 'Создание обычной сметы из AI-черновика',
```

Роли получают только необходимые permissions. Право `apply` не выдавать viewer-ролям.

- [ ] **Step 4: Заменить route middleware**

Каждая route либо группа получает точный `authorize:<permission>`. FormRequest `authorize()` дополнительно вызывает `AuthorizationService` для permission-sensitive mutation и возвращает `false` при отсутствии organization/project context.

- [ ] **Step 5: Запустить RBAC и translation tests**

```bash
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php
php artisan test --filter=PermissionTranslator
```

Expected: PASS; API available permissions не содержит технические ключи в пользовательском label.

- [ ] **Step 6: Commit**

```bash
git add config/RoleDefinitions lang/ru/permissions.php app/BusinessModules/Addons/EstimateGeneration/routes.php app/BusinessModules/Addons/EstimateGeneration/Http/Requests tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php tests/Unit/Authorization
git commit -m "feat[lk]: разделены права AI-сметчика"
```

### Task 5: Добавить единый session snapshot и available actions

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/SessionSnapshotData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionSnapshot.php`
- Replace: `app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationSessionResource.php`
- Create: `tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php`
- Create: `tests/Feature/EstimateGeneration/EstimateGenerationWorkflowApiTest.php`

**Interfaces:**
- Consumes: workflow status, current permission set and readiness summary.
- Produces: `SessionSnapshotData::toArray(): array` with stable v2 shape.

- [ ] **Step 1: Написать snapshot contract test**

```php
#[Test]
public function ready_session_exposes_only_permitted_actions(): void
{
    $snapshot = app(BuildSessionSnapshot::class)->handle(
        session: $this->session(EstimateGenerationStatus::ReadyToApply),
        permissions: ['estimate_generation.view', 'estimate_generation.apply'],
    );

    self::assertSame('ready_to_apply', $snapshot->status->value);
    self::assertSame(['apply'], array_column($snapshot->availableActions, 'action'));
    self::assertSame([], $snapshot->blockingIssues);
    self::assertSame('apply', $snapshot->nextAction);
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php`

Expected: FAIL, classes отсутствуют.

- [ ] **Step 3: Реализовать immutable DTO**

```php
final readonly class SessionSnapshotData
{
    public function __construct(
        public int $id,
        public EstimateGenerationStatus $status,
        public string $processingStage,
        public int $processingProgress,
        public int $stateVersion,
        public array $availableActions,
        public array $blockingIssues,
        public array $warnings,
        public ?string $nextAction,
        public array $documentsSummary,
        public array $estimateSummary,
        public array $reviewSummary,
        public ?int $appliedEstimateId,
        public string $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'processing_stage' => $this->processingStage,
            'processing_progress' => $this->processingProgress,
            'state_version' => $this->stateVersion,
            'available_actions' => $this->availableActions,
            'blocking_issues' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'next_action' => $this->nextAction,
            'documents_summary' => $this->documentsSummary,
            'estimate_summary' => $this->estimateSummary,
            'review_summary' => $this->reviewSummary,
            'applied_estimate_id' => $this->appliedEstimateId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
```

- [ ] **Step 4: Реализовать builder и заменить resource**

`BuildSessionSnapshot` фильтрует разрешенные status actions по permissions. Не вычислять UI labels на frontend; вернуть `label`, `method`, `endpoint` и `requires_confirmation` для каждого action.

- [ ] **Step 5: Проверить unit и API contract**

```bash
php artisan test tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationWorkflowApiTest.php
```

Expected: PASS; `AdminResponse` содержит snapshot ровно один раз под `data`.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Application/Sessions app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationSessionResource.php tests/Unit/EstimateGeneration/Workflow tests/Feature/EstimateGeneration/EstimateGenerationWorkflowApiTest.php
git commit -m "feat[lk]: унифицирован snapshot AI-сметы"
```

### Task 6: Создать единственный идемпотентный apply use case

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/GeneratedEstimateWriter.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/ApplyGeneratedEstimate.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/ApplyGeneratedEstimateResult.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateDraftPersistenceService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Create: `tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php`
- Modify: `tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

**Interfaces:**
- Consumes: session ID, organization ID, project ID and expected state version.
- Produces: `ApplyGeneratedEstimateResult(int $estimateId, bool $created)`.

- [ ] **Step 1: Написать idempotency и isolation tests**

```php
#[Test]
public function repeated_apply_returns_the_same_estimate_without_new_rows(): void
{
    $first = app(ApplyGeneratedEstimate::class)->handle($this->command());
    $countAfterFirst = Estimate::query()->count();
    $second = app(ApplyGeneratedEstimate::class)->handle($this->command());

    self::assertTrue($first->created);
    self::assertFalse($second->created);
    self::assertSame($first->estimateId, $second->estimateId);
    self::assertSame($countAfterFirst, Estimate::query()->count());
}

#[Test]
public function foreign_organization_cannot_apply_session(): void
{
    $this->expectException(ModelNotFoundException::class);
    app(ApplyGeneratedEstimate::class)->handle($this->foreignOrganizationCommand());
}
```

Добавить test, который snapshot-ит counts и `updated_at` существующих ordinary estimates до apply и доказывает, что изменена только новая запись.

- [ ] **Step 2: Запустить tests**

Run: `php artisan test tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php`

Expected: FAIL, use case отсутствует.

- [ ] **Step 3: Реализовать writer contract**

```php
interface GeneratedEstimateWriter
{
    public function createFromSession(EstimateGenerationSession $session): int;
}
```

`LaravelGeneratedEstimateWriter` является единственным классом AI-модуля, который импортирует модели обычных смет. Он переносит существующую логику создания из `EstimateDraftPersistenceService`; сервис после переноса не должен импортировать `Estimate`, `EstimateItem` или `EstimateSection`.

- [ ] **Step 4: Реализовать транзакционный use case**

```php
public function handle(ApplyGeneratedEstimateCommand $command): ApplyGeneratedEstimateResult
{
    return DB::transaction(function () use ($command): ApplyGeneratedEstimateResult {
        $session = EstimateGenerationSession::query()
            ->whereKey($command->sessionId)
            ->where('organization_id', $command->organizationId)
            ->where('project_id', $command->projectId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($session->applied_estimate_id !== null) {
            return new ApplyGeneratedEstimateResult($session->applied_estimate_id, false);
        }

        if ($session->status !== EstimateGenerationStatus::ReadyToApply) {
            throw new InvalidEstimateGenerationTransition($session->status, EstimateGenerationEvent::ApplyStarted);
        }

        $this->workflow->transition($session, EstimateGenerationEvent::ApplyStarted);
        $estimateId = $this->writer->createFromSession($session);

        $session->forceFill([
            'applied_estimate_id' => $estimateId,
            'applied_at' => now(),
        ])->save();
        $this->workflow->transition($session->refresh(), EstimateGenerationEvent::ApplyCompleted);

        return new ApplyGeneratedEstimateResult($estimateId, true);
    });
}
```

- [ ] **Step 5: Переписать controller apply**

Controller строит command из authenticated organization/project/session, вызывает use case и возвращает `AdminResponse::success`. Он не содержит `DB::transaction`, persistence или status mutation.

- [ ] **Step 6: Запустить tests и architecture boundary**

```bash
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php
php artisan test tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php
```

Expected: PASS; direct model imports остаются только в `LaravelGeneratedEstimateWriter.php` — скорректировать allow-list architecture test на этот точный файл.

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Application/Apply app/BusinessModules/Addons/EstimateGeneration/Services/EstimateDraftPersistenceService.php app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php
git commit -m "feat[lk]: защищено применение AI-сметы"
```

### Task 7: Перевести все endpoint mutations на workflow

**Files:**
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Refactor: `app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationDocumentController.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationDocumentJob.php`
- Create: `tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php`
- Modify: `tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php`

**Interfaces:**
- Consumes: `EstimateGenerationWorkflow` and application use cases.
- Produces: отсутствие прямых `status` writes за пределами workflow state store.

- [ ] **Step 1: Написать architecture test**

```php
#[Test]
public function status_is_mutated_only_by_the_workflow_store(): void
{
    $violations = $this->findPhpSourcesContaining([
        "'status' =>",
        'status =',
        "update(['status'",
    ], app_path('BusinessModules/Addons/EstimateGeneration'));

    self::assertSame([
        'Domain/Workflow/EloquentSessionStateStore.php',
    ], $this->relativePaths($violations));
}
```

Исключить migrations, tests, DTO serialization и query filters; искать только mutation patterns.

- [ ] **Step 2: Запустить architecture test**

Run: `php artisan test tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php`

Expected: FAIL со списком текущих controller/job/service mutations.

- [ ] **Step 3: Перенести mutations в workflow**

Для каждого endpoint создать или использовать application command. Controllers сохраняют обязательные `try-catch`, контекстное `Log::error` и `AdminResponse`, но не содержат business logic.

- [ ] **Step 4: Обновить flow test на новый status lifecycle**

Проверить точную последовательность:

```text
draft -> processing_documents -> ready_to_generate -> generating
-> estimate_review_required -> ready_to_apply -> applying -> applied
```

- [ ] **Step 5: Запустить tests**

```bash
php artisan test tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Http app/BusinessModules/Addons/EstimateGeneration/Jobs tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php
git commit -m "refactor[lk]: централизован workflow AI-сметчика"
```

### Task 8: Удалить legacy workflow и закрыть Plan 1

**Files:**
- Delete or replace obsolete branches in: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php`
- Delete obsolete workflow helpers found by architecture tests.
- Modify: `lang/ru/estimate_generation.php`
- Create: `docs/workflows/ai-estimator.md`
- Create: `docs/workflows/ai-estimator-roles-and-statuses.md`
- Update: `tests/Unit/EstimateGeneration/EstimateGenerationModuleRegistrationTest.php`

**Interfaces:**
- Consumes: completed status/RBAC/snapshot/apply contracts.
- Produces: one workflow implementation and operational documentation.

- [ ] **Step 1: Найти legacy status strings и routes**

Run:

```bash
rg -n "created|queued|processing|generated|review_required|blocked|waiting_for_documents|analyzed" app/BusinessModules/Addons/EstimateGeneration tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration
```

Expected: список только для целевого удаления/перевода; после cleanup старые runtime statuses отсутствуют.

- [ ] **Step 2: Удалить старые status branches и неиспользуемые routes**

Удалять класс только после `trace_path`/поиска всех callers и перевода их на новый contract. Не оставлять adapter, alias или deprecated wrapper.

- [ ] **Step 3: Обновить workflow документацию**

`docs/workflows/ai-estimator.md` должен содержать purpose, actors, entry conditions, happy path, review, retry, cancel, apply, archive и ordinary-estimate boundary.

`docs/workflows/ai-estimator-roles-and-statuses.md` должен перечислять permissions, status transitions, terminal states и действия оператора.

- [ ] **Step 4: Выполнить полный Plan 1 gate**

```bash
php artisan test tests/Unit/EstimateGeneration/Workflow tests/Feature/EstimateGeneration/EstimateGenerationWorkflowApiTest.php tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php tests/Feature/EstimateGeneration/EstimateGenerationApplyBoundaryTest.php tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php tests/Architecture/EstimateGenerationStatusMutationBoundaryTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Domain app/BusinessModules/Addons/EstimateGeneration/Application app/BusinessModules/Addons/EstimateGeneration/Http --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

- [ ] **Step 5: Проверить отсутствие legacy statuses**

Run: `rg -n "waiting_for_documents|generated|review_required|blocked|analyzed" app/BusinessModules/Addons/EstimateGeneration`

Expected: exit code 1, совпадений нет, кроме migration data cleanup при документированном исключении.

- [ ] **Step 6: Commit**

```bash
git add -A app/BusinessModules/Addons/EstimateGeneration tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration tests/Architecture docs/workflows lang/ru/estimate_generation.php
git commit -m "refactor[lk]: удален старый workflow AI-сметчика"
```
