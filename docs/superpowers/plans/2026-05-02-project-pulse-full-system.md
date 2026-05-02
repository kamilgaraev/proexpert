# Полный системный пульс проектов Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Превратить “Пульс проектов” в полноценный ежедневный управленческий отчет внутри админки, который покрывает проекты, заявки, закупки, снабжение, склад, финансы, договоры, графики, работы, отчеты и исполнителей.

**Architecture:** Пульс собирает факты через набор модульных источников, нормализует их в единый DTO, строит метрики и рекомендации правилами, а затем при включенном ИИ усиливает формулировки без выдумывания событий. Backend остается источником истины для фактов, приоритетов, маршрутов действий и группировок; frontend только отображает готовый управленческий отчет.

**Tech Stack:** Laravel 11, PHP 8.2+, PostgreSQL, AdminResponse, React/Vite/TypeScript, существующий AI Assistant модуль, существующие RBAC permissions.

---

## Scope

Реализовать сразу полный вариант, без MVP-разделения:

- Backend собирает факты из всех ключевых контуров.
- Backend возвращает полный контракт отчета с группами, категориями, действиями и источниками.
- Rule engine строит рекомендации по всем категориям.
- AI получает полную карту фактов и работает только как слой управленческого синтеза.
- Admin UI показывает ежедневный отчет по категориям, группам, фактам, рекомендациям и переходам в исходные сущности.
- Старый “анализ системы” и промежуточные урезанные варианты не сохраняются как отдельная логика.

## File Structure

**Backend create:**

- `app/BusinessModules/Features/AIAssistant/Contracts/ProjectPulse/ProjectPulseFactSourceInterface.php` — контракт источников фактов.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseProjectFactSource.php` — проекты и сроки.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseSiteRequestFactSource.php` — заявки с объекта.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseProcurementFactSource.php` — закупочный контур, снабжение, поставщики, КП, заказы.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseWarehouseFactSource.php` — склад, остатки, резервы, приходы, списания.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseFinanceFactSource.php` — платежи, счета, заявки на оплату, задолженности.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseContractFactSource.php` — договоры, допсоглашения, акты, согласования.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseScheduleFactSource.php` — графики, задачи, этапы, просрочки.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseReportFactSource.php` — отчеты, отсутствие обязательных отчетов, проблемные показатели.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseWorkFactSource.php` — выполненные работы, акты, расхождения.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulsePeopleFactSource.php` — исполнители, назначения, перегрузки, отсутствие ответственных.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactSourceRegistry.php` — единая регистрация источников.

**Backend modify:**

- `app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php` — расширить DTO категориями, действиями, источниками, статусом, сроками.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactCollector.php` — заменить ручной сбор на registry всех источников.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseRuleEngine.php` — пересобрать рекомендации, группы риска и активность по всем категориям.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFormatter.php` — вернуть новый контракт `categories`, `groups`, `facts`, `recommendations`, `next_actions`.
- `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseAiSynthesizer.php` — передать AI полную карту фактов и запретить фактические домыслы.
- `config/ai-assistant.php` — добавить настройки категорий, лимитов и порогов пульса.
- `lang/ru/ai_assistant.php` — добавить переводы категорий, статусов, AI-плашек и fallback-сообщений через `trans_message`.
- `tests/Feature/Api/V1/Admin/ProjectPulse/ProjectPulseReportTest.php` — добавить сценарии полного системного пульса.
- `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactSourcesTest.php` — покрыть источники фактов.

**Admin modify:**

- `prohelper_admin/src/types/projectPulse.ts` — расширить контракт отчета.
- `prohelper_admin/src/services/projectPulseService.ts` — нормализовать новый ответ без догадок на странице.
- `prohelper_admin/src/pages/ProjectPulse/ProjectPulsePage.tsx` — показать полный отчет по категориям.
- `prohelper_admin/src/pages/ProjectPulse/ProjectPulseReportPage.tsx` — показать сохраненный полный отчет.
- `prohelper_admin/src/pages/ProjectPulse/ProjectPulseHistoryPage.tsx` — фильтры по статусу, категории, проекту, периоду.
- `prohelper_admin/src/pages/ProjectPulse/projectPulseTranslations.ts` — все UI-строки пульса на русском.
- `prohelper_admin/src/pages/ProjectPulse/ProjectPulse.css` — адаптивная компоновка без карточек внутри карточек.

---

## Target API Contract

Backend detail/current/generate endpoints return:

```json
{
  "id": 2,
  "report_date": "2026-05-02",
  "period": {
    "preset": "today",
    "from": "2026-05-02T00:00:00+03:00",
    "to": "2026-05-02T23:59:59+03:00"
  },
  "scope": {
    "type": "project",
    "organization_id": 46,
    "project_id": 56
  },
  "status": "warning",
  "ai_mode": {
    "status": "active",
    "provider": "yandex",
    "message": "Рекомендации усилены ИИ на основе фактов из системы."
  },
  "summary": {
    "title": "Есть вопросы для контроля",
    "text": "По проекту есть события, которые требуют управленческого решения сегодня."
  },
  "categories": [
    {
      "key": "procurement",
      "label": "Закупки",
      "status": "warning",
      "critical_count": 0,
      "warning_count": 2,
      "info_count": 1,
      "amount": 35000
    }
  ],
  "groups": [
    {
      "key": "requires_action",
      "label": "Требует реакции",
      "facts": []
    }
  ],
  "facts": [
    {
      "id": "purchase_request:2:no_order",
      "source": "procurement",
      "category": "procurement",
      "priority": "warning",
      "status": "approved",
      "title": "Согласована, но заказ поставщику не создан",
      "text": "По согласованной закупочной заявке 33-202604-0001 еще не оформлен заказ поставщику.",
      "next_action": "Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.",
      "project_id": 56,
      "project_name": "Строительство склада Литер А",
      "amount": 35000,
      "deadline": null,
      "age_days": 2,
      "owner_name": null,
      "related_entity": {
        "type": "purchase_request",
        "id": 2,
        "label": "Заявка на закупку 33-202604-0001",
        "route": "/procurement/purchase-requests/2"
      },
      "primary_action": {
        "label": "Создать заказ",
        "route": "/procurement/proposals",
        "permission": "procurement.purchase_orders.create"
      },
      "occurred_at": "2026-04-30T12:00:00+03:00"
    }
  ],
  "recommendations": [],
  "next_actions": [],
  "metrics": [],
  "finance": {},
  "generated_at": "2026-05-02T15:31:11+03:00"
}
```

---

### Task 1: Expand Project Pulse Fact DTO

**Files:**
- Modify: `app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php`
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactTest.php`

- [ ] **Step 1: Add the DTO test**

Create `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\AIAssistant\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use PHPUnit\Framework\TestCase;

class ProjectPulseFactTest extends TestCase
{
    public function test_fact_exports_full_management_contract(): void
    {
        $fact = new ProjectPulseFact(
            id: 'purchase_request:2:no_order',
            type: 'purchase_request',
            priority: 'warning',
            title: 'Согласована, но заказ поставщику не создан',
            text: 'По согласованной закупочной заявке 33-202604-0001 еще не оформлен заказ поставщику.',
            projectId: 56,
            projectName: 'Строительство склада Литер А',
            relatedEntity: [
                'type' => 'purchase_request',
                'id' => 2,
                'label' => 'Заявка на закупку 33-202604-0001',
                'route' => '/procurement/purchase-requests/2',
            ],
            amount: 35000.0,
            occurredAt: '2026-04-30T12:00:00+03:00',
            source: 'procurement',
            category: 'procurement',
            status: 'approved',
            nextAction: 'Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.',
            primaryAction: [
                'label' => 'Создать заказ',
                'route' => '/procurement/proposals',
                'permission' => 'procurement.purchase_orders.create',
            ],
            deadline: null,
            ageDays: 2,
            ownerName: null,
        );

        $payload = $fact->toArray();

        self::assertSame('procurement', $payload['source']);
        self::assertSame('procurement', $payload['category']);
        self::assertSame('approved', $payload['status']);
        self::assertSame('Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.', $payload['next_action']);
        self::assertSame('/procurement/proposals', $payload['primary_action']['route']);
        self::assertSame(2, $payload['age_days']);
        self::assertSame(35000.0, $payload['amount']);
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
vendor/bin/phpunit tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactTest.php
```

Expected: FAIL because `ProjectPulseFact` does not accept the new named arguments.

- [ ] **Step 3: Expand `ProjectPulseFact`**

Modify constructor and `toArray()` in `app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php` so the class contains:

```php
public function __construct(
    public readonly string $id,
    public readonly string $type,
    public readonly string $priority,
    public readonly string $title,
    public readonly string $text,
    public readonly ?int $projectId = null,
    public readonly ?string $projectName = null,
    public readonly ?array $relatedEntity = null,
    public readonly ?float $amount = null,
    public readonly ?string $occurredAt = null,
    public readonly string $source = 'system',
    public readonly string $category = 'system',
    public readonly ?string $status = null,
    public readonly ?string $nextAction = null,
    public readonly ?array $primaryAction = null,
    public readonly ?string $deadline = null,
    public readonly ?int $ageDays = null,
    public readonly ?string $ownerName = null,
) {
}
```

`toArray()` must return snake_case keys:

```php
return [
    'id' => $this->id,
    'type' => $this->type,
    'source' => $this->source,
    'category' => $this->category,
    'priority' => $this->priority,
    'status' => $this->status,
    'title' => $this->title,
    'text' => $this->text,
    'next_action' => $this->nextAction,
    'project_id' => $this->projectId,
    'project_name' => $this->projectName,
    'related_entity' => $this->relatedEntity,
    'primary_action' => $this->primaryAction,
    'amount' => $this->amount,
    'deadline' => $this->deadline,
    'age_days' => $this->ageDays,
    'owner_name' => $this->ownerName,
    'occurred_at' => $this->occurredAt,
];
```

- [ ] **Step 4: Verify DTO**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php
vendor/bin/phpunit tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactTest.php
```

Expected: syntax check passes, unit test passes.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactTest.php
git commit -m "feat[lk]: расширить факты пульса проектов"
```

---

### Task 2: Add Fact Source Registry

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/Contracts/ProjectPulse/ProjectPulseFactSourceInterface.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactSourceRegistry.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactCollector.php`
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseFactCollectorTest.php`

- [ ] **Step 1: Add source interface**

Create `ProjectPulseFactSourceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use Illuminate\Support\Collection;

interface ProjectPulseFactSourceInterface
{
    public function key(): string;

    public function collect(ProjectPulseContext $context): Collection;
}
```

- [ ] **Step 2: Add registry**

Create `ProjectPulseFactSourceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use Illuminate\Support\Collection;

class ProjectPulseFactSourceRegistry
{
    /**
     * @param iterable<ProjectPulseFactSourceInterface> $sources
     */
    public function __construct(
        private readonly iterable $sources,
    ) {
    }

    public function all(): Collection
    {
        return collect($this->sources);
    }
}
```

- [ ] **Step 3: Refactor collector**

Modify `ProjectPulseFactCollector::collect()`:

```php
public function __construct(
    private readonly ProjectPulseFactSourceRegistry $sourceRegistry,
) {
}

public function collect(ProjectPulseContext $context): Collection
{
    return $this->sourceRegistry
        ->all()
        ->flatMap(fn (ProjectPulseFactSourceInterface $source) => $source->collect($context))
        ->values();
}
```

Keep `metrics()` and `finance()` temporarily in the collector until Tasks 9 and 10 move their logic into sources and formatter.

- [ ] **Step 4: Bind sources in service provider**

Modify the existing AI Assistant service provider if present, otherwise the module provider, so `ProjectPulseFactSourceRegistry` receives all concrete sources after Tasks 3-10 are created. For this task bind an empty array:

```php
$this->app->bind(ProjectPulseFactSourceRegistry::class, function ($app): ProjectPulseFactSourceRegistry {
    return new ProjectPulseFactSourceRegistry([]);
});
```

- [ ] **Step 5: Verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Contracts/ProjectPulse/ProjectPulseFactSourceInterface.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactSourceRegistry.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactCollector.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse app/BusinessModules/Features/AIAssistant/Contracts --memory-limit=1G
```

Expected: no syntax errors, phpstan passes.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Contracts/ProjectPulse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse
git commit -m "refactor[lk]: добавить источники фактов пульса проектов"
```

---

### Task 3: Implement Project, Site Request, Schedule, Work Sources

**Files:**
- Create: `ProjectPulseProjectFactSource.php`
- Create: `ProjectPulseSiteRequestFactSource.php`
- Create: `ProjectPulseScheduleFactSource.php`
- Create: `ProjectPulseWorkFactSource.php`
- Modify: `ProjectPulseFactSourceRegistry` binding
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/OperationalFactSourcesTest.php`

- [ ] **Step 1: Add project source**

Create source that returns:

- active project with overdue `end_date` => critical;
- active project ending in next 7 days => warning;
- project with no activity facts in period => warning.

Routes:

- project route: `/projects/{id}`

Fact examples:

```php
new ProjectPulseFact(
    id: 'project:' . $project->id . ':deadline_overdue',
    type: 'project_deadline',
    priority: 'critical',
    title: 'Срок проекта истек',
    text: 'Проект «' . $project->name . '» остается активным после плановой даты завершения.',
    projectId: (int) $project->id,
    projectName: (string) $project->name,
    relatedEntity: [
        'type' => 'project',
        'id' => (int) $project->id,
        'label' => 'Проект ' . $project->name,
        'route' => '/projects/' . $project->id,
    ],
    source: 'projects',
    category: 'project',
    status: (string) $project->status,
    nextAction: 'Проверить план завершения проекта и назначить ответственного за актуализацию сроков.',
    primaryAction: [
        'label' => 'Открыть проект',
        'route' => '/projects/' . $project->id,
        'permission' => 'projects.view',
    ],
    deadline: $project->end_date?->toDateString(),
)
```

- [ ] **Step 2: Add site request source**

Move current `collectSiteRequestFacts()` into `ProjectPulseSiteRequestFactSource` and expand:

- no `assigned_to` => warning;
- status `draft`, `new`, `pending` older than 1 day => warning;
- priority `urgent` or `high` with no movement => critical;
- material request without linked purchase request => warning.

Routes:

- `/site-requests/{id}`
- action to create purchase request: `/procurement/purchase-requests/create?site_request_id={id}`

- [ ] **Step 3: Add schedule source**

Read existing schedule tables defensively with `Schema::hasTable()` and `Schema::hasColumn()`:

- overdue schedule task not completed => critical;
- task due in next 3 days without responsible user => warning;
- project schedule absent for active project => warning.

Routes:

- `/projects/{project_id}/schedule`
- `/schedules`

- [ ] **Step 4: Add work source**

Read `completed_works`, act reports, and available status columns defensively:

- completed work added in period => info;
- completed work not linked to act/payment when columns exist => warning;
- amount mismatch between completed work and act when columns exist => warning.

Routes:

- `/completed-works/{id}`
- `/act-reports/{id}`

- [ ] **Step 5: Register sources**

The registry binding must include:

```php
return new ProjectPulseFactSourceRegistry([
    $app->make(ProjectPulseProjectFactSource::class),
    $app->make(ProjectPulseSiteRequestFactSource::class),
    $app->make(ProjectPulseScheduleFactSource::class),
    $app->make(ProjectPulseWorkFactSource::class),
]);
```

- [ ] **Step 6: Verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseProjectFactSource.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseSiteRequestFactSource.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseScheduleFactSource.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseWorkFactSource.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse --memory-limit=1G
```

Expected: no syntax errors, phpstan passes.

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse tests/Unit/BusinessModules/AIAssistant/ProjectPulse
git commit -m "feat[lk]: подключить проектные факты к пульсу"
```

---

### Task 4: Implement Full Procurement and Supply Source

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseProcurementFactSource.php`
- Modify: registry binding
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProcurementFactSourceTest.php`

- [ ] **Step 1: Add procurement source cases**

`ProjectPulseProcurementFactSource` must collect:

- `purchase_requests.status = approved` and no `purchase_orders` => warning, action “Создать заказ”.
- `purchase_requests.assigned_to IS NULL` and status not final => warning, action “Назначить исполнителя”.
- `supplier_requests.status = draft` and not sent => warning, action “Отправить поставщикам”.
- `supplier_requests.status = sent` and no proposal after 1 day => warning, action “Проверить ответы поставщиков”.
- supplier proposals received but no accepted decision => warning, action “Выбрать предложение”.
- `purchase_orders.status = draft` => warning, action “Отправить заказ”.
- `purchase_orders.status = sent` and not confirmed after 1 day => warning.
- delivery date overdue and order not delivered/cancelled => critical.
- delivered order without receipt when receipt tables exist => warning.
- order without contract when contract is required by workflow => warning.

- [ ] **Step 2: Use safe schema checks**

Every table and optional column must be guarded:

```php
if (!Schema::hasTable('purchase_requests')) {
    return collect();
}

if (Schema::hasTable('purchase_orders')) {
    // join or subquery purchase_orders
}
```

- [ ] **Step 3: Build the exact fact for the screenshot case**

The approved purchase request without order must produce:

```php
new ProjectPulseFact(
    id: 'purchase_request:' . $row->id . ':approved_without_order',
    type: 'purchase_request',
    priority: 'warning',
    title: 'Согласована, но заказ поставщику не создан',
    text: 'По согласованной закупочной заявке ' . $row->request_number . ' еще не оформлен заказ поставщику.',
    projectId: $row->project_id !== null ? (int) $row->project_id : null,
    projectName: $row->project_name,
    relatedEntity: [
        'type' => 'purchase_request',
        'id' => (int) $row->id,
        'label' => 'Заявка на закупку ' . $row->request_number,
        'route' => '/procurement/purchase-requests/' . $row->id,
    ],
    amount: $row->budget_amount !== null ? (float) $row->budget_amount : null,
    occurredAt: (string) $row->created_at,
    source: 'procurement',
    category: 'procurement',
    status: 'approved',
    nextAction: 'Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.',
    primaryAction: [
        'label' => 'Создать заказ',
        'route' => '/procurement/proposals',
        'permission' => 'procurement.purchase_orders.create',
    ],
    ageDays: now()->diffInDays($row->created_at),
)
```

- [ ] **Step 4: Resolve project through site request**

`purchase_requests` does not always have `project_id`. Join through `site_requests`:

```sql
purchase_requests.site_request_id -> site_requests.id -> site_requests.project_id -> projects.id
```

The source must work for project-scoped pulse:

```php
->when($context->projectId !== null, function ($query) use ($context): void {
    $query->where('site_requests.project_id', $context->projectId);
})
```

- [ ] **Step 5: Register source**

Add `$app->make(ProjectPulseProcurementFactSource::class)` to the registry.

- [ ] **Step 6: Verify with production read-only data**

Run on production with read-only wrapper:

```bash
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='\$context = new App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext(organizationId: 46, projectId: 56, userId: 61, period: \"today\", date: now(\"Europe/Moscow\"), from: now(\"Europe/Moscow\")->startOfDay(), to: now(\"Europe/Moscow\")->endOfDay(), useAi: false); \$source = app(App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\ProjectPulseProcurementFactSource::class); echo json_encode(\$source->collect(\$context)->map->toArray()->values(), JSON_UNESCAPED_UNICODE);'"
```

Expected: output contains purchase request `33-202604-0001` with route `/procurement/purchase-requests/{id}` and action label `Создать заказ`.

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseProcurementFactSource.php app/BusinessModules/Features/AIAssistant/Services/ProjectPulse tests/Unit/BusinessModules/AIAssistant/ProjectPulse
git commit -m "feat[lk]: подключить закупки и снабжение к пульсу"
```

---

### Task 5: Implement Warehouse Source

**Files:**
- Create: `ProjectPulseWarehouseFactSource.php`
- Modify: registry binding
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/WarehouseFactSourceTest.php`

- [ ] **Step 1: Collect stock risks**

Use existing warehouse tables defensively. The source must produce:

- stock below minimum => warning or critical;
- zero stock for material requested by site/procurement => critical;
- reserved item not issued after 1 day => warning;
- receipt not distributed to project/warehouse cell => warning;
- write-off without linked project/request where columns exist => warning;
- warehouse task overdue => warning or critical.

- [ ] **Step 2: Add routes**

Use available admin routes:

- `/warehouse`
- `/warehouse/inventory`
- `/warehouse/receipts`
- `/warehouse/reservations`
- `/procurement/purchase-requests/{id}` for missing requested material.

- [ ] **Step 3: Add fact categories**

All warehouse facts:

```php
source: 'warehouse',
category: 'warehouse'
```

- [ ] **Step 4: Register source and verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseWarehouseFactSource.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse --memory-limit=1G
```

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse
git commit -m "feat[lk]: подключить склад к пульсу проектов"
```

---

### Task 6: Implement Finance and Contract Sources

**Files:**
- Create: `ProjectPulseFinanceFactSource.php`
- Create: `ProjectPulseContractFactSource.php`
- Modify: registry binding
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/FinanceContractFactSourcesTest.php`

- [ ] **Step 1: Finance facts**

Collect:

- payment request awaiting approval => warning;
- overdue invoice/payment document => critical;
- completed work exists but payment is missing => warning;
- payment amount materially lower than performed amount => warning;
- high daily expense above configured threshold => info/warning.

Routes:

- `/payments/requests/{id}`
- `/payments/invoices/{id}`
- `/payments/transactions/{id}`
- `/completed-works/{id}`

- [ ] **Step 2: Contract facts**

Collect:

- contract end date overdue while active => critical;
- contract payment schedule overdue => warning/critical;
- supplementary agreement waiting approval => warning;
- act waiting approval/signature => warning;
- procurement order requires contract but none exists => warning.

Routes:

- `/contracts/{id}`
- `/agreements/{id}`
- `/act-reports/{id}`
- `/procurement/purchase-orders/{id}`

- [ ] **Step 3: Register and verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseFinanceFactSource.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseContractFactSource.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse --memory-limit=1G
```

- [ ] **Step 4: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse
git commit -m "feat[lk]: подключить финансы и договоры к пульсу"
```

---

### Task 7: Implement Report and People Sources

**Files:**
- Create: `ProjectPulseReportFactSource.php`
- Create: `ProjectPulsePeopleFactSource.php`
- Modify: registry binding
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ReportPeopleFactSourcesTest.php`

- [ ] **Step 1: Report facts**

Collect:

- expected daily/weekly report missing where report schedules exist;
- custom report execution failed;
- scheduled report overdue;
- report generated with critical indicators where fields exist.

Routes:

- `/reports`
- `/custom-reports`
- `/report-files`

- [ ] **Step 2: People facts**

Collect:

- important entity without assignee;
- user overloaded by count of open critical/warning facts;
- foreman has no activity in period for active project where time/activity tables exist;
- pending approval has no available approver when data allows.

Routes:

- `/users`
- source entity routes from the fact.

- [ ] **Step 3: Register and verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulseReportFactSource.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/Sources/ProjectPulsePeopleFactSource.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse --memory-limit=1G
```

- [ ] **Step 4: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse
git commit -m "feat[lk]: подключить отчеты и исполнителей к пульсу"
```

---

### Task 8: Rebuild Rule Engine and Formatter for Full Report

**Files:**
- Modify: `ProjectPulseRuleEngine.php`
- Modify: `ProjectPulseFormatter.php`
- Modify: `config/ai-assistant.php`
- Modify: `lang/ru/ai_assistant.php`
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseRuleEngineTest.php`

- [ ] **Step 1: Add category config**

In `config/ai-assistant.php`:

```php
'project_pulse' => [
    'periods' => ['today', 'yesterday', 'week'],
    'categories' => [
        'project' => 'Проекты',
        'request' => 'Заявки',
        'procurement' => 'Закупки',
        'warehouse' => 'Склад',
        'finance' => 'Финансы',
        'contract' => 'Договоры',
        'schedule' => 'График',
        'report' => 'Отчеты',
        'work' => 'Работы',
        'people' => 'Исполнители',
        'system' => 'Система',
    ],
    'limits' => [
        'facts_per_source' => 30,
        'facts_total' => 250,
        'recommendations' => 12,
        'next_actions' => 10,
    ],
],
```

- [ ] **Step 2: Build categories**

`ProjectPulseRuleEngine` must expose:

```php
public function categories(Collection $facts): array
```

It returns per-category status and counts:

```php
[
    'key' => 'procurement',
    'label' => 'Закупки',
    'status' => 'warning',
    'critical_count' => 0,
    'warning_count' => 2,
    'info_count' => 1,
    'amount' => 35000.0,
]
```

- [ ] **Step 3: Build groups**

Add:

```php
public function groups(Collection $facts): array
```

Groups:

- `requires_action`
- `critical`
- `today`
- `procurement`
- `warehouse`
- `finance`
- `schedule`
- `contracts`
- `reports`

- [ ] **Step 4: Build next actions**

Add:

```php
public function nextActions(Collection $facts): array
```

Return up to configured limit, ordered by:

1. critical facts;
2. warning facts;
3. facts with deadline overdue;
4. facts with primary action;
5. oldest age.

- [ ] **Step 5: Update formatter**

`ProjectPulseFormatter::format()` must include:

```php
'categories' => $report->categories ?? [],
'groups' => $report->groups ?? [],
'facts' => $report->raw_facts ?? [],
'next_actions' => $report->next_actions ?? [],
```

If columns do not exist yet, store these inside existing JSON fields:

- `risk_groups` can contain full `groups`;
- `urgent_actions` can contain `next_actions`;
- `metrics` can contain category metrics;
- `raw_facts` contains full facts.

No migration is required for this release.

- [ ] **Step 6: Verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseRuleEngine.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFormatter.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse --memory-limit=1G
```

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse config/ai-assistant.php lang/ru/ai_assistant.php tests/Unit/BusinessModules/AIAssistant/ProjectPulse
git commit -m "feat[lk]: собрать полный управленческий отчет пульса"
```

---

### Task 9: Rebuild AI Synthesis for Full System Context

**Files:**
- Modify: `ProjectPulseAiSynthesizer.php`
- Test: `tests/Unit/BusinessModules/AIAssistant/ProjectPulse/ProjectPulseAiSynthesizerTest.php`

- [ ] **Step 1: Prepare AI payload**

AI payload must include:

```php
[
    'scope' => [
        'organization_id' => $context->organizationId,
        'project_id' => $context->projectId,
        'period' => $context->period,
        'date' => $context->date->toDateString(),
    ],
    'facts' => $facts->map->toArray()->values()->all(),
    'categories' => $categories,
    'next_actions' => $nextActions,
]
```

- [ ] **Step 2: Add strict AI instruction**

Prompt must contain:

```text
Используй только факты из payload. Не добавляй событий, сумм, сроков, статусов и участников, которых нет в фактах. Если данных недостаточно, напиши, что в системе нет подтвержденных данных для такого вывода.
```

- [ ] **Step 3: Keep rules-only mode complete**

When AI is disabled or provider fails:

- summary still exists;
- recommendations still exist;
- next actions still exist;
- AI mode says “Рекомендации подготовлены по правилам на основе данных системы.”

- [ ] **Step 4: Verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseAiSynthesizer.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseAiSynthesizer.php --memory-limit=1G
```

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseAiSynthesizer.php tests/Unit/BusinessModules/AIAssistant/ProjectPulse
git commit -m "feat[lk]: усилить ии-сводку полного пульса"
```

---

### Task 10: Update Backend Generation Storage

**Files:**
- Modify: `ProjectPulseService.php`
- Modify: `ProjectPulseFormatter.php`
- Test: `tests/Feature/Api/V1/Admin/ProjectPulse/ProjectPulseReportTest.php`

- [ ] **Step 1: Store complete facts**

`ProjectPulseService::generate()` must:

```php
$facts = $this->factCollector->collect($context);
$categories = $this->ruleEngine->categories($facts);
$groups = $this->ruleEngine->groups($facts);
$nextActions = $this->ruleEngine->nextActions($facts);
$ruleRecommendations = $this->ruleEngine->recommendations($facts);
$synthesis = $this->aiSynthesizer->synthesize($facts, $ruleRecommendations, $context->useAi, $categories, $nextActions);
```

Store:

```php
'metrics' => $categories,
'urgent_actions' => $nextActions,
'risk_groups' => $groups,
'recommendations' => $synthesis['recommendations'],
'raw_facts' => $facts->map->toArray()->values()->all(),
```

- [ ] **Step 2: Update current/list/detail**

`current`, `reports`, and `show` must return compatible summary plus new fields. List endpoint should include:

- `id`
- `report_date`
- `period`
- `scope`
- `project`
- `status`
- `ai_mode`
- `summary`
- `category_summary`
- `next_action_count`
- `generated_at`

- [ ] **Step 3: Feature test full procurement case**

In `ProjectPulseReportTest`, add:

```php
public function test_project_pulse_contains_approved_purchase_request_without_order(): void
{
    $this->withoutMiddleware();

    // create organization, user, project, site request, purchase request approved with no purchase order
    // generate project pulse
    // assert raw facts contain category procurement and route /procurement/purchase-requests/{id}
}
```

Use factories if they exist; otherwise create rows with model or DB insert matching table columns.

- [ ] **Step 4: Verify**

Run:

```bash
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseService.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFormatter.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant/Services/ProjectPulse tests/Feature/Api/V1/Admin/ProjectPulse/ProjectPulseReportTest.php --memory-limit=1G
```

Feature tests may still be blocked locally by existing SQLite-incompatible PostgreSQL migrations. If blocked, record the exact migration error and verify with phpstan plus production read-only source checks.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Features/AIAssistant/Services/ProjectPulse tests/Feature/Api/V1/Admin/ProjectPulse/ProjectPulseReportTest.php
git commit -m "feat[lk]: сохранять полный системный пульс"
```

---

### Task 11: Update Admin Types and Service Normalization

**Files:**
- Modify: `prohelper_admin/src/types/projectPulse.ts`
- Modify: `prohelper_admin/src/services/projectPulseService.ts`
- Test: `prohelper_admin/src/services/projectPulseService.test.ts`

- [ ] **Step 1: Add full TypeScript types**

`ProjectPulseFact`:

```ts
export interface ProjectPulseFact {
  id: string;
  type: string;
  source: string;
  category: ProjectPulseCategoryKey;
  priority: 'critical' | 'warning' | 'info';
  status?: string | null;
  title: string;
  text: string;
  next_action?: string | null;
  project_id?: number | null;
  project_name?: string | null;
  related_entity?: ProjectPulseRelatedEntity | null;
  primary_action?: ProjectPulsePrimaryAction | null;
  amount?: number | null;
  deadline?: string | null;
  age_days?: number | null;
  owner_name?: string | null;
  occurred_at?: string | null;
}
```

`ProjectPulseCategoryKey`:

```ts
export type ProjectPulseCategoryKey =
  | 'project'
  | 'request'
  | 'procurement'
  | 'warehouse'
  | 'finance'
  | 'contract'
  | 'schedule'
  | 'report'
  | 'work'
  | 'people'
  | 'system';
```

- [ ] **Step 2: Normalize optional arrays**

In service layer, ensure:

```ts
facts: Array.isArray(payload.facts) ? payload.facts : [],
categories: Array.isArray(payload.categories) ? payload.categories : [],
groups: Array.isArray(payload.groups) ? payload.groups : [],
next_actions: Array.isArray(payload.next_actions) ? payload.next_actions : [],
recommendations: Array.isArray(payload.recommendations) ? payload.recommendations : [],
```

- [ ] **Step 3: Verify TypeScript**

Run:

```bash
npx tsc --noEmit
```

Do not run `npm run build`.

- [ ] **Step 4: Commit**

```bash
git add src/types/projectPulse.ts src/services/projectPulseService.ts src/services/projectPulseService.test.ts
git commit -m "feat[lk]: обновить контракт полного пульса"
```

---

### Task 12: Update Admin UI for Full Daily Report

**Files:**
- Modify: `prohelper_admin/src/pages/ProjectPulse/ProjectPulsePage.tsx`
- Modify: `prohelper_admin/src/pages/ProjectPulse/ProjectPulseReportPage.tsx`
- Modify: `prohelper_admin/src/pages/ProjectPulse/ProjectPulseHistoryPage.tsx`
- Modify: `prohelper_admin/src/pages/ProjectPulse/projectPulseTranslations.ts`
- Modify: `prohelper_admin/src/pages/ProjectPulse/ProjectPulse.css`

- [ ] **Step 1: Add category filter**

Filters:

- Project
- Period
- Date
- Category
- Status
- AI mode

Category labels:

```ts
export const PROJECT_PULSE_CATEGORY_LABELS = {
  project: 'Проекты',
  request: 'Заявки',
  procurement: 'Закупки',
  warehouse: 'Склад',
  finance: 'Финансы',
  contract: 'Договоры',
  schedule: 'График',
  report: 'Отчеты',
  work: 'Работы',
  people: 'Исполнители',
  system: 'Система',
} as const;
```

- [ ] **Step 2: Add dashboard sections**

Main report page sections:

- summary;
- AI/rules mode banner;
- category strip;
- next actions;
- grouped facts;
- recommendations;
- finance block;
- all facts table.

- [ ] **Step 3: Fact card action behavior**

Each fact card must show:

- title;
- category label;
- priority label in Russian;
- related entity link;
- next action text;
- primary action button if present.

Button behavior:

```ts
if (fact.primary_action?.route) {
  navigate(fact.primary_action.route);
} else if (fact.related_entity?.route) {
  navigate(fact.related_entity.route);
}
```

- [ ] **Step 4: Remove technical UI words**

Do not display:

- `warning`
- `critical`
- `rules_only`
- `active`
- `procurement`
- `warehouse`

Use translations only.

- [ ] **Step 5: Verify**

Run:

```bash
npx tsc --noEmit
```

Do not run `npm run build`.

- [ ] **Step 6: Commit**

```bash
git add src/pages/ProjectPulse src/types/projectPulse.ts src/services/projectPulseService.ts
git commit -m "feat[lk]: показать полный ежедневный пульс в админке"
```

---

### Task 13: End-to-End Verification and Production Read-Only Check

**Files:**
- No required source edits unless verification finds a bug.

- [ ] **Step 1: Backend static verification**

Run in `prohelper`:

```bash
php -l app/BusinessModules/Features/AIAssistant/DTOs/ProjectPulse/ProjectPulseFact.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFactCollector.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseRuleEngine.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseFormatter.php
php -l app/BusinessModules/Features/AIAssistant/Services/ProjectPulse/ProjectPulseAiSynthesizer.php
vendor/bin/phpstan analyse app/BusinessModules/Features/AIAssistant tests/Unit/BusinessModules/AIAssistant/ProjectPulse --memory-limit=1G
```

- [ ] **Step 2: Admin static verification**

Run in `prohelper_admin`:

```bash
npx tsc --noEmit
```

- [ ] **Step 3: Production read-only fact check**

Run:

```bash
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='\$context = new App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext(organizationId: 46, projectId: 56, userId: 61, period: \"today\", date: now(\"Europe/Moscow\"), from: now(\"Europe/Moscow\")->startOfDay(), to: now(\"Europe/Moscow\")->endOfDay(), useAi: false); \$facts = app(App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseFactCollector::class)->collect(\$context); echo json_encode(\$facts->map->toArray()->values(), JSON_UNESCAPED_UNICODE);'"
```

Expected:

- facts include `category = procurement`;
- facts include purchase request `33-202604-0001`;
- facts include warehouse/finance/schedule/contract categories when matching production data exists;
- every fact has a Russian title and next action;
- every actionable fact has a route.

- [ ] **Step 4: Manual browser verification**

Open admin:

1. Go to `/project-pulse`.
2. Select project “Строительство склада Литер А”.
3. Generate report for `02.05.2026`.
4. Confirm category strip includes all categories with data.
5. Confirm procurement card for `33-202604-0001` is visible.
6. Click “Создать заказ” and confirm navigation opens the procurement flow.
7. Open report from history and confirm detail loads with the same facts.

- [ ] **Step 5: Commit verification fixes**

If verification required fixes:

```bash
git add app/BusinessModules/Features/AIAssistant prohelper_admin/src/pages/ProjectPulse prohelper_admin/src/types/projectPulse.ts prohelper_admin/src/services/projectPulseService.ts
git commit -m "fix[lk]: стабилизировать полный пульс проектов"
```

- [ ] **Step 6: Push**

```bash
git push origin main
```

---

## Self-Review

**Spec coverage:**

- Закупочный контур covered by Task 4.
- Снабжение covered by supplier requests, proposals, purchase orders in Task 4.
- Склад covered by Task 5.
- Отчеты covered by Task 7.
- Графики covered by Task 3.
- Финансы covered by Task 6.
- Договоры covered by Task 6.
- Работы covered by Task 3.
- Исполнители covered by Task 7.
- Full admin UI covered by Tasks 11-12.
- AI/rules mode covered by Task 9.
- Production verification covered by Task 13.

**No MVP split:** Tasks are parallelizable implementation blocks, but acceptance requires all tasks completed before release.

**No technical UI wording:** Task 12 explicitly removes raw technical statuses from user-visible UI.

**Known local test risk:** Existing SQLite-incompatible PostgreSQL migrations can block feature tests before Project Pulse tests run. If that happens, record the exact migration error, keep phpstan/static verification mandatory, and use production read-only checks for factual coverage.

## Execution Options

Plan complete and saved to `docs/superpowers/plans/2026-05-02-project-pulse-full-system.md`.

**1. Subagent-Driven (recommended)** — dispatch independent workers for backend source groups, frontend contract/UI, and verification; review and integrate task-by-task.

**2. Inline Execution** — execute the whole plan in this session with checkpoints after backend, frontend, and verification.
