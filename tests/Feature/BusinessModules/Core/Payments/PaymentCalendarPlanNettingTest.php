<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentCalendarPlanNettingTest extends TestCase
{
    use RefreshDatabase;

    public static function forecasts(): array
    {
        return [
            'linked ACT without plan counterparty' => ['linked', '0.00', '100000.00'],
            'part of plan remains' => ['smaller', '40000.00', '100000.00'],
            'invoice exceeds plan' => ['larger', '0.00', '120000.00'],
            'partially paid invoice' => ['partial', '0.00', '100000.00'],
            'fully paid invoice' => ['paid', '0.00', '100000.00'],
            'invoice outside visible week' => ['week', '0.00', '0.00'],
            'invoice in another month' => ['month', '100000.00', '100000.00'],
            'different currency' => ['currency', '100000.00', '100000.00'],
            'different project' => ['project', '100000.00', '100000.00'],
            'different contract' => ['contract', '100000.00', '200000.00'],
            'explicit matching counterparty' => ['counterparty', '0.00', '100000.00'],
            'different counterparty' => ['other-counterparty', '100000.00', '200000.00'],
            'different article' => ['article', '100000.00', '200000.00'],
            'different responsibility center' => ['center', '100000.00', '200000.00'],
            'ambiguous active versions' => ['versions', '100000.00', '300000.00'],
            'cancelled invoice' => ['cancelled', '100000.00', '100000.00'],
            'document scope excludes invoice' => ['scope', '100000.00', '100000.00'],
            'foreign organization invoice' => ['organization', '100000.00', '100000.00'],
            'schedule reservation and actual payment' => ['dedup', '0.00', '100000.00'],
            'multi-project ACT in allowed project' => ['multi-act', '0.00', '100000.00'],
            'multi-project direct contract' => ['multi-direct', '0.00', '100000.00'],
            'multi-project contract source' => ['multi-source', '0.00', '100000.00'],
            'multi-project contract without this project' => ['multi-unlinked', '100000.00', '200000.00'],
            'single-project contract ignores stale pivot' => ['single-pivot', '100000.00', '200000.00'],
            'organization ID collides with contractor ID' => ['counterparty-type', '100000.00', '200000.00'],
        ];
    }

    #[DataProvider('forecasts')]
    public function test_forecast_keeps_only_the_uncommitted_plan(string $case, string $expectedPlan, string $expectedTotal): void
    {
        Queue::fake();
        $fixture = $this->fixture();
        ['context' => $context, 'document' => $document, 'line' => $line, 'amount' => $amount,
            'version' => $version, 'project' => $project, 'contractor' => $contractor] = $fixture;
        $start = '2026-10-01';
        $end = '2026-10-31';
        $scope = null;
        $projectScope = null;
        if ($case === 'smaller' || $case === 'larger') {
            $value = $case === 'smaller' ? '60000.00' : '120000.00';
            $document->update(['amount' => $value, 'remaining_amount' => $value]);
        } elseif (in_array($case, ['partial', 'paid', 'dedup'], true)) {
            $paid = $case === 'paid' ? '100000.00' : '40000.00';
            $document->update(['status' => $case === 'paid' ? PaymentDocumentStatus::PAID : PaymentDocumentStatus::PARTIALLY_PAID,
                'paid_amount' => $paid, 'remaining_amount' => $case === 'paid' ? '0.00' : '60000.00']);
            PaymentTransaction::query()->create([
                'payment_document_id' => $document->id, 'organization_id' => $context->organization->id,
                'project_id' => $project->id, 'payer_organization_id' => $context->organization->id,
                'payee_organization_id' => $document->payee_organization_id,
                'amount' => $paid, 'currency' => 'RUB', 'payment_method' => 'bank_transfer',
                'transaction_date' => '2026-10-15', 'value_date' => '2026-10-15', 'status' => 'completed',
                'created_by_user_id' => $context->user->id,
            ]);
            if ($case === 'dedup') {
                PaymentSchedule::query()->create(['payment_document_id' => $document->id, 'installment_number' => 1,
                    'due_date' => '2026-10-20', 'amount' => '100000.00', 'paid_amount' => '40000.00', 'status' => 'pending']);
                BudgetLimitReservation::query()->create([
                    'organization_id' => $context->organization->id, 'payment_document_id' => $document->id,
                    'budget_period_id' => $version->budget_period_id, 'project_id' => $project->id,
                    'contract_id' => $fixture['contract']->id, 'counterparty_id' => $contractor->id,
                    'budget_article_id' => $line->budget_article_id, 'responsibility_center_id' => $line->responsibility_center_id,
                    'period_month' => '2026-10-01', 'currency' => 'RUB', 'amount' => '100000.00',
                    'status' => BudgetLimitReservation::STATUS_RESERVED, 'reserved_at' => now(),
                    'created_by_user_id' => $context->user->id,
                ]);
            }
        } elseif ($case === 'week') {
            $document->update(['due_date' => '2026-10-28']);
            $start = '2026-10-12';
            $end = '2026-10-18';
        } elseif ($case === 'month') {
            $document->update(['due_date' => '2026-11-20']);
        } elseif ($case === 'currency') {
            $document->update(['currency' => 'USD']);
        } elseif ($case === 'project') {
            $other = Project::factory()->create(['organization_id' => $context->organization->id]);
            $document->update(['project_id' => $other->id]);
        } elseif ($case === 'contract') {
            $other = $this->contract($context, $project, $contractor);
            $line->update(['contract_id' => $other->id]);
        } elseif ($case === 'counterparty') {
            $line->update(['counterparty_id' => $contractor->id]);
        } elseif ($case === 'other-counterparty') {
            $other = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'Другой подрядчик']);
            $line->update(['counterparty_id' => $other->id]);
        } elseif ($case === 'article' || $case === 'center') {
            $model = $case === 'article' ? $fixture['article'] : $fixture['center'];
            $other = $model->replicate(['uuid']);
            $other->code = 'OTHER-'.uniqid();
            $other->save();
            $document->update([$case === 'article' ? 'budget_article_id' : 'responsibility_center_id' => $other->id]);
        } elseif ($case === 'versions') {
            $otherVersion = $version->replicate(['uuid']);
            $otherVersion->version_number = 2;
            $otherVersion->save();
            $otherLine = $line->replicate(['uuid']);
            $otherLine->budget_version_id = $otherVersion->id;
            $otherLine->save();
            BudgetAmount::query()->create(['budget_line_id' => $otherLine->id, 'month' => '2026-10-01',
                'plan_amount' => '100000.00', 'forecast_amount' => '100000.00', 'currency' => 'RUB']);
        } elseif ($case === 'cancelled') {
            $document->update(['status' => PaymentDocumentStatus::CANCELLED]);
        } elseif ($case === 'scope') {
            $scope = static fn ($query) => $query->whereKey(-1);
        } elseif ($case === 'organization') {
            $document->update(['organization_id' => Organization::factory()->create()->id]);
        } elseif (str_starts_with($case, 'multi-') || $case === 'single-pivot') {
            $contract = $fixture['contract'];
            $contract->update(['is_multi_project' => $case !== 'single-pivot', 'project_id' => null]);
            if ($case !== 'multi-unlinked') $contract->projects()->attach($project->id);
            if ($case === 'multi-direct') {
                $document->update(['invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id]);
            } elseif ($case === 'multi-source') {
                $document->update(['invoiceable_type' => null, 'invoiceable_id' => null, 'source_type' => Contract::class, 'source_id' => $contract->id]);
            }
            $scope = static fn ($query) => $query->where('project_id', $project->id);
            $projectScope = static fn ($query) => $query->where('project_id', $project->id);
        } elseif ($case === 'counterparty-type') {
            $collisionId = max((int) Organization::query()->max('id'), (int) Contractor::query()->max('id')) + 100;
            $organization = Organization::factory()->create(['id' => $collisionId]);
            $otherContractor = new Contractor(['organization_id' => $context->organization->id,
                'name' => 'Отдельный подрядчик', 'source_organization_id' => $document->payee_organization_id]);
            $otherContractor->id = $collisionId;
            $otherContractor->save();
            $line->update(['counterparty_id' => $otherContractor->id]);
            $document->update(['payee_contractor_id' => null, 'contractor_id' => null,
                'counterparty_organization_id' => $organization->id, 'payee_organization_id' => $organization->id]);
        }

        $items = app(PaymentCalendarSourceService::class)->collect(new PaymentCalendarSourceFilters(
            organizationId: (int) $context->organization->id, periodStart: $start, periodEnd: $end,
            projectId: (int) $project->id, currency: 'RUB',
        ), new CarbonImmutable('2026-10-15'), $scope, $projectScope);
        $plan = collect($items)->first(fn ($item) => $item->sourceType === 'budget_amount' && (int) $item->sourceId === (int) $amount->id);
        $this->assertNotNull($plan);
        $this->assertSame('100000.00', $plan->amount);
        $this->assertSame($expectedPlan, $plan->remainingAmount);
        $total = '0.00';
        foreach ($items as $item) $total = PortfolioDecimal::add($total, $item->remainingAmount);
        $this->assertSame($expectedTotal, $total);
        $this->assertSame('100000.00', $amount->fresh()->plan_amount);
        $this->assertSame('100000.00', $amount->fresh()->forecast_amount);
        $this->assertSame($case === 'versions' ? 2 : 1, collect($items)->where('sourceType', 'budget_amount')->count());
        if ($case === 'dedup') {
            $this->assertSame(1, collect($items)->where('sourceType', 'payment_schedule')->count());
            $this->assertSame(1, collect($items)->where('sourceType', 'payment_transaction')->count());
            $this->assertSame(0, collect($items)->whereIn('sourceType', ['payment_document', 'budget_limit_reservation'])->count());
        }
    }

    private function contract(AdminApiTestContext $context, Project $project, Contractor $contractor): Contract
    {
        return Contract::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'CAL-'.uniqid(), 'date' => '2026-10-01',
            'subject' => 'Учебные работы', 'total_amount' => '100000.00', 'status' => 'active']);
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = Organization::factory()->verified()->create();
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id,
            'name' => 'Учебный подрядчик', 'source_organization_id' => $payee->id]);
        $contract = $this->contract($context, $project, $contractor);
        $act = ContractPerformanceAct::withoutEvents(static fn () => ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => 'ACT-CAL-1',
            'act_date' => '2026-10-13', 'period_start' => '2026-10-01', 'period_end' => '2026-10-13',
            'amount' => '100000.00', 'currency' => 'RUB', 'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]));
        $period = BudgetPeriod::query()->create(['organization_id' => $context->organization->id,
            'code' => 'CAL-PER-'.uniqid(), 'name' => 'Октябрь', 'period_type' => 'month',
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open']);
        $scenario = BudgetScenario::query()->create(['organization_id' => $context->organization->id,
            'code' => 'CAL-BASE-'.uniqid(), 'name' => 'Проектный', 'scenario_type' => 'base', 'is_default' => false, 'is_active' => true]);
        $article = BudgetArticle::query()->create(['organization_id' => $context->organization->id,
            'code' => 'CAL-PAY-'.uniqid(), 'name' => 'Работы', 'budget_kind' => 'bdds',
            'flow_direction' => 'outflow', 'is_leaf' => true, 'is_active' => true]);
        $center = ResponsibilityCenter::query()->create(['organization_id' => $context->organization->id,
            'center_type' => 'project', 'code' => 'CAL-CFO-'.uniqid(), 'name' => 'ЦФО проекта', 'is_active' => true]);
        $version = BudgetVersion::query()->create(['organization_id' => $context->organization->id,
            'budget_period_id' => $period->id, 'scenario_id' => $scenario->id, 'budget_kind' => 'bdds',
            'version_number' => 1, 'name' => 'Проектный бюджет', 'status' => 'active', 'approved_at' => now(), 'activated_at' => now()]);
        $line = BudgetLine::query()->create(['budget_version_id' => $version->id, 'budget_article_id' => $article->id,
            'responsibility_center_id' => $center->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'currency' => 'RUB']);
        $amount = BudgetAmount::query()->create(['budget_line_id' => $line->id, 'month' => '2026-10-01',
            'plan_amount' => '100000.00', 'forecast_amount' => '100000.00', 'currency' => 'RUB']);
        $document = PaymentDocument::query()->create(['organization_id' => $context->organization->id,
            'project_id' => $project->id, 'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $payee->id, 'payee_contractor_id' => $contractor->id, 'contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::INVOICE, 'invoice_type' => InvoiceType::ACT,
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
            'document_number' => 'CAL-INV-'.uniqid(), 'document_date' => '2026-10-15', 'due_date' => '2026-10-20',
            'direction' => InvoiceDirection::OUTGOING, 'status' => PaymentDocumentStatus::SUBMITTED,
            'amount' => '100000.00', 'paid_amount' => '0.00', 'remaining_amount' => '100000.00', 'currency' => 'RUB',
            'budget_article_id' => $article->id, 'responsibility_center_id' => $center->id, 'created_by_user_id' => $context->user->id]);

        return compact('context', 'project', 'contractor', 'contract', 'period', 'scenario', 'article', 'center', 'version', 'line', 'amount', 'document');
    }
}
