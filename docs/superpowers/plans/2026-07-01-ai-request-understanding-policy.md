# AI Request Understanding Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить серверный слой Request Understanding / Intent Policy для ProHelper AI Assistant, чтобы отрицательные ограничения пользователя управляли доступностью tools, agent flow и payload.

**Architecture:** Запрос разбирается один раз в `AssistantRequestUnderstandingResolver`, затем результат хранится в `taskPlan['request_understanding']`. `AssistantToolEligibilityPolicy` применяет этот результат перед передачей tools в LLM, перед фактическим выполнением tool call, перед agent executor и перед добавлением navigation/proposed actions в payload.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, существующие сервисы AI Assistant без миграций и без локальных DB-команд.

---

### Task 1: Regression Tests For Request Understanding

**Files:**
- Create: `tests/Unit/AIAssistant/RequestUnderstanding/AssistantRequestUnderstandingResolverTest.php`
- Create: `tests/Unit/AIAssistant/RequestUnderstanding/AssistantToolEligibilityPolicyTest.php`
- Modify: `tests/Unit/AIAssistant/Reports/AssistantReportIntentResolverTest.php`
- Modify: `tests/Unit/AIAssistant/AssistantTaskOrchestratorTest.php`
- Modify: `tests/Unit/AIAssistant/Agent/AssistantAgentPlannerTest.php`
- Modify: `tests/Unit/AIAssistant/AIAssistantServiceBudgetTest.php`

- [x] **Step 1: Add resolver tests**

Cover exact prompts A-J from the request:

```php
$result = (new AssistantRequestUnderstandingResolver)->resolve(
    'По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.',
    []
);

$this->assertSame('search_knowledge', $result->primaryIntent);
$this->assertSame('text', $result->outputFormat);
$this->assertSame('read_only', $result->actionPolicy);
$this->assertTrue($result->hasConstraint('no_pdf'));
$this->assertTrue($result->hasConstraint('no_file'));
$this->assertTrue($result->hasConstraint('no_report'));
$this->assertTrue($result->hasConstraint('text_only'));
$this->assertContains('project', $result->requestedEntities);
```

- [x] **Step 2: Add eligibility tests**

Assert report/PDF/file tools are blocked for text-only/no-actions policies, read-only snapshot/search tools remain allowed, mutation tools require confirmation, and navigation is blocked for json/no-actions/no-navigation:

```php
$understanding = (new AssistantRequestUnderstandingResolver)->resolve('Только текст. Не создавай PDF, файл или отчет.', []);
$policy = new AssistantToolEligibilityPolicy;

$this->assertFalse($policy->canExposeTool('generate_operational_pdf_report', $understanding)->allowed);
$this->assertTrue($policy->canExposeTool('get_project_snapshot', $understanding)->allowed);
```

- [x] **Step 3: Add report intent negative tests**

Add assertions that negative report/file wording returns `not_report`:

```php
$result = (new AssistantReportIntentResolver)->resolve('Без отчета расскажи, какие риски по проекту.');
$this->assertSame('not_report', $result['status']);
```

- [x] **Step 4: Add orchestrator/payload tests**

Assert `plan()` includes `request_understanding`, JSON-only/no-actions removes `next_actions` and navigation, and read-only summary keeps read-only policy:

```php
$plan = $orchestrator->plan('Ответь строго JSON без markdown. Без действий и без навигации.', [], $accessContext);
$payload = $orchestrator->buildPayload($plan, '{"ok":true}');

$this->assertSame('json', $plan['request_understanding']['output_format']);
$this->assertSame([], $payload['next_actions']);
$this->assertNull($payload['navigation_target']);
```

- [x] **Step 5: Verify RED**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\RequestUnderstanding tests\Unit\AIAssistant\Reports\AssistantReportIntentResolverTest.php tests\Unit\AIAssistant\AssistantTaskOrchestratorTest.php tests\Unit\AIAssistant\Agent\AssistantAgentPlannerTest.php tests\Unit\AIAssistant\AIAssistantServiceBudgetTest.php
```

Expected: FAIL because `AssistantRequestUnderstandingResolver` and `AssistantToolEligibilityPolicy` do not exist yet and existing services do not expose policy.

### Task 2: Request Understanding Model And Resolver

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/DTOs/RequestUnderstanding/AssistantRequestUnderstanding.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/RequestUnderstanding/AssistantRequestUnderstandingResolver.php`

- [x] **Step 1: Implement DTO**

Create a strict typed readonly object with:

```php
public function __construct(
    public string $primaryIntent,
    public string $outputFormat,
    public string $actionPolicy,
    public array $constraints,
    public array $requestedEntities,
    public float $confidence,
    public array $evidence,
) {}
```

Add `hasConstraint(string $constraint): bool`, `toArray(): array`, and `fromArray(array $payload): self`.

- [x] **Step 2: Implement resolver**

Use deterministic Russian phrase matching with negative constraints taking priority:

```php
if ($this->containsAny($normalized, ['не создавай pdf', 'без pdf', 'не нужен pdf'])) {
    $constraints[] = 'no_pdf';
}

if ($this->containsAny($normalized, ['только текст', 'только текстом', 'просто напиши текстом'])) {
    $constraints[] = 'text_only';
    $outputFormat = 'text';
}
```

Resolve `search_knowledge` for knowledge-base/source/fact requests, `generate_report` only for explicit positive report/file/PDF commands without negative constraints, `approve` for approval verbs, `navigate` for open/go verbs, and `read_only` when no mutation/navigation/file generation is explicitly allowed.

- [x] **Step 3: Run GREEN for resolver tests**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\RequestUnderstanding/AssistantRequestUnderstandingResolverTest.php
```

Expected: PASS.

### Task 3: Tool Eligibility Policy

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/DTOs/RequestUnderstanding/AssistantToolEligibility.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/RequestUnderstanding/AssistantToolEligibilityPolicy.php`

- [x] **Step 1: Implement eligibility DTO**

Return `allowed`, `reason`, `category`, `requiresConfirmation` and `toArray()`.

- [x] **Step 2: Implement tool categories and guard**

Classify tools by name:

```php
report: str_starts_with($toolName, 'generate_') && str_ends_with($toolName, '_report')
file: report || str_contains($toolName, 'pdf') || str_contains($toolName, 'file')
mutation: prefixes create_, update_, delete_, approve_, send_
read: prefixes get_, search_
navigation: payload actions with type navigate
```

Block report/file tools when constraints include `no_file`, `no_pdf`, `no_report`, `text_only`, `json_only`, or `no_actions`. Block mutation tools for `read_only`/`no_actions` unless policy is `requires_confirmation`. Allow read-only tools under `read_only`.

- [x] **Step 3: Run GREEN for policy tests**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\RequestUnderstanding/AssistantToolEligibilityPolicyTest.php
```

Expected: PASS.

### Task 4: Integrate Policy Into Planner, Tools And Payload

**Files:**
- Modify: `app/BusinessModules/Features/AIAssistant/Services/AssistantTaskOrchestrator.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/AIAssistantService.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantAgentPlanner.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantAgentExecutor.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/Reports/AssistantReportIntentResolver.php`

- [x] **Step 1: Add resolver and policy to orchestrator**

Inject defaultable dependencies:

```php
private readonly AssistantRequestUnderstandingResolver $requestUnderstandingResolver = new AssistantRequestUnderstandingResolver,
private readonly AssistantToolEligibilityPolicy $toolEligibilityPolicy = new AssistantToolEligibilityPolicy
```

Add `request_understanding` to plan and pass it into `buildNextActions()` / `buildAccessLimits()` / `buildPayload()`.

- [x] **Step 2: Filter tools before LLM**

In `AIAssistantService::resolveToolDefinitions()`, compute tool names, filter each via policy, log allowed/blocked categories, then call registry only with allowed names.

- [x] **Step 3: Guard tool execution**

Pass `$taskPlan` into `handleToolCall()`. Before permission checks, call policy. If blocked, append a safe business-readable failure, log `ai.tool.blocked_by_request_policy`, and return an error result without executing the tool.

- [x] **Step 4: Guard agent report flow**

In `AssistantAgentPlanner`, resolve request understanding before report intent. If report/file/action is blocked by constraints, return `answer` instead of `execute_tool` / clarification. In `AssistantAgentExecutor`, add an optional understanding parameter and block execution as a second guard.

- [x] **Step 5: Guard payload actions**

In `AssistantTaskOrchestrator::buildPayload()`, remove `next_actions` and `navigation_target` when policy blocks navigation/actions; remove proposed mutation actions when `no_actions` or `read_only` forbids them.

- [x] **Step 6: Run integration unit tests**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Reports\AssistantReportIntentResolverTest.php tests\Unit\AIAssistant\AssistantTaskOrchestratorTest.php tests\Unit\AIAssistant\Agent\AssistantAgentPlannerTest.php tests\Unit\AIAssistant\AIAssistantServiceBudgetTest.php
```

Expected: PASS.

### Task 5: Verification And Commit

**Files:**
- All changed PHP files
- `docs/superpowers/plans/2026-07-01-ai-request-understanding-policy.md`

- [x] **Step 1: Syntax check**

Run `php -l` for every changed PHP file. Expected: `No syntax errors detected`.

- [x] **Step 2: Static analysis**

Run:

```powershell
vendor\bin\phpstan analyse app\BusinessModules\Features\AIAssistant\Services app\BusinessModules\Features\AIAssistant\DTOs tests\Unit\AIAssistant --memory-limit=1G --no-progress
```

Expected: no new errors in touched scope.

- [x] **Step 3: Focused tests**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\RequestUnderstanding tests\Unit\AIAssistant\Reports\AssistantReportIntentResolverTest.php tests\Unit\AIAssistant\AssistantTaskOrchestratorTest.php tests\Unit\AIAssistant\Agent\AssistantAgentPlannerTest.php tests\Unit\AIAssistant\Agent\AssistantAgentExecutorTest.php tests\Unit\AIAssistant\AIAssistantServiceBudgetTest.php
```

Expected: PASS.

- [x] **Step 4: Commit**

Run:

```powershell
git status --short
git add app\BusinessModules\Features\AIAssistant tests\Unit\AIAssistant docs\superpowers\plans\2026-07-01-ai-request-understanding-policy.md
git commit -m "feat[ai]: добавлена политика понимания запросов ассистента"
```

Expected: commit created on `feat/ai-request-understanding-policy`.
