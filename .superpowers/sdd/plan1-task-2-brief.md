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
    ): void;
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
