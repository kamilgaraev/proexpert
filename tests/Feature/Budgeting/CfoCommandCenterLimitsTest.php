<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CfoCommandCenterLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_checks_show_only_the_latest_warning_and_preserve_history(): void
    {
        $context = $this->context();
        $document = $this->document($context);
        $this->check($context, $document, 'warning', '2026-10-15 10:00:00');
        $latest = $this->check($context, $document, 'warning', '2026-10-15 10:01:00');

        $this->dashboard($context)
            ->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 1)
            ->assertJsonCount(1, 'data.items.limit_overruns')
            ->assertJsonPath('data.items.limit_overruns.0.id', $latest->id);
        $this->assertDatabaseCount('budget_limit_checks', 2);
    }

    public function test_a_later_available_check_clears_the_previous_warning(): void
    {
        $context = $this->context();
        $document = $this->document($context);
        $this->check($context, $document, 'warning', '2026-10-15 10:00:00');
        $this->check($context, $document, 'available', '2026-10-15 10:01:00');

        $this->dashboard($context)->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 0)
            ->assertJsonCount(0, 'data.items.limit_overruns');
        $this->assertDatabaseCount('budget_limit_checks', 2);
    }

    public function test_equal_check_timestamps_use_the_latest_id(): void
    {
        $context = $this->context();
        $document = $this->document($context);
        $this->check($context, $document, 'warning', '2026-10-15 10:00:00');
        $latest = $this->check($context, $document, 'warning', '2026-10-15 10:00:00');

        $this->dashboard($context)->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 1)
            ->assertJsonPath('data.items.limit_overruns.0.id', $latest->id);
    }

    public function test_backfilled_history_does_not_replace_a_more_recent_check(): void
    {
        $context = $this->context();
        $document = $this->document($context);
        $latest = $this->check($context, $document, 'warning', '2026-10-15 10:02:00');
        $this->check($context, $document, 'warning', '2026-10-15 10:01:00');

        $this->dashboard($context)->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 1)
            ->assertJsonPath('data.items.limit_overruns.0.id', $latest->id);
    }

    public function test_month_currency_and_organization_remain_separate(): void
    {
        $context = $this->context();
        $other = $this->context();
        $document = $this->document($context);
        $october = $this->check($context, $document, 'warning', '2026-10-15 10:00:00');
        $this->check($context, $document, 'warning', '2026-10-15 10:01:00', ['period_month' => '2026-11-01']);
        $this->check($context, $document, 'warning', '2026-10-15 10:02:00', ['currency' => 'USD']);
        $this->check($other, $this->document($other), 'warning', '2026-10-15 10:03:00');

        $this->dashboard($context, ['currency' => 'RUB', 'current_organization_id' => $other->organization->id])
            ->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 1)
            ->assertJsonPath('data.items.limit_overruns.0.id', $october->id);
        $this->dashboard($context, ['currency' => 'RUB', 'period_end' => '2026-11-30'])
            ->assertOk()->assertJsonCount(2, 'data.items.limit_overruns');
        $this->dashboard($context, ['currency' => 'USD'])
            ->assertOk()->assertJsonCount(1, 'data.items.limit_overruns');
    }

    public function test_an_old_scope_does_not_resurface_after_a_new_scope_is_checked(): void
    {
        $context = $this->context();
        $oldProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $newProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->document($context, ['project_id' => $newProject->id]);
        $this->check($context, $document, 'warning', '2026-10-15 10:00:00', ['project_id' => $oldProject->id]);
        $latest = $this->check($context, $document, 'warning', '2026-10-15 10:01:00', ['project_id' => $newProject->id]);

        $this->dashboard($context, ['project_id' => $oldProject->id])
            ->assertOk()->assertJsonCount(0, 'data.items.limit_overruns');
        $this->dashboard($context, ['project_id' => $newProject->id])
            ->assertOk()->assertJsonPath('data.items.limit_overruns.0.id', $latest->id);
    }

    public function test_unlinked_checks_are_not_collapsed_into_a_single_document(): void
    {
        $context = $this->context();
        $document = $this->document($context);
        $this->check($context, $document, 'warning', '2026-10-15 10:00:00');
        $this->check($context, $document, 'warning', '2026-10-15 10:01:00');
        $this->check($context, null, 'warning', '2026-10-15 10:02:00');
        $this->check($context, null, 'warning', '2026-10-15 10:03:00');

        $this->dashboard($context)->assertOk()
            ->assertJsonPath('data.summary.limits.warning_count', 3)
            ->assertJsonCount(3, 'data.items.limit_overruns');
        $this->assertDatabaseCount('budget_limit_checks', 4);
    }

    private function context(): AdminApiTestContext
    {
        $context = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $module = Module::query()->firstOrCreate(
            ['slug' => 'budgeting'],
            [
                'name' => 'Budgeting', 'version' => '1.0.0', 'type' => 'feature',
                'billing_model' => 'free', 'category' => 'finance',
                'permissions' => ['budgeting.cfo.view'], 'is_active' => true,
                'is_system_module' => true,
            ],
        );
        $module->forceFill(['is_system_module' => true])->save();
        OrganizationModuleActivation::query()->create([
            'organization_id' => $context->organization->id,
            'module_id' => $module->id, 'status' => 'active', 'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($context->organization->id, 'budgeting');

        return $context;
    }

    private function document(AdminApiTestContext $context, array $attributes = []): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'CFO-'.Str::uuid(),
            'document_date' => '2026-10-15',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => '100000.00', 'paid_amount' => '0.00', 'remaining_amount' => '100000.00',
            'currency' => 'RUB', 'status' => PaymentDocumentStatus::SUBMITTED,
            'due_date' => '2026-10-20', 'created_by_user_id' => $context->user->id,
        ], $attributes));
    }

    private function check(
        AdminApiTestContext $context,
        ?PaymentDocument $document,
        string $status,
        string $checkedAt,
        array $attributes = [],
    ): BudgetLimitCheck {
        $check = BudgetLimitCheck::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'payment_document_id' => $document?->id,
            'operation_type' => 'document_create', 'operation_id' => (string) Str::uuid(),
            'period_month' => '2026-10-01', 'currency' => 'RUB', 'requested_amount' => '100000.00',
            'status' => $status, 'decision' => $status === 'available' ? 'allow' : 'warn',
            'message' => 'Проверка бюджета', 'accepted' => true,
            'checked_by_user_id' => $context->user->id,
            'sources' => [], 'summary' => [], 'dimensions' => [], 'audit_trail' => [],
        ], $attributes));
        $check->forceFill(['created_at' => $checkedAt])->save();

        return $check;
    }

    private function dashboard(AdminApiTestContext $context, array $filters = []): TestResponse
    {
        return $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/budgeting/cfo-command-center?'.http_build_query(array_merge([
                'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            ], $filters)),
        );
    }
}
