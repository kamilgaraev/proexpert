<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Features\Budgeting;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\Services\WipForecastReportService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WipPaymentDocumentContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
    }

    public function test_act_documents_join_the_contract_in_report_filters_and_drill_down(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $otherContract = $this->contract($context, $project);
        $act = $this->act($contract, $project->id);
        $this->document($context, $project->id, 100000, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
            'source_type' => Contract::class, 'source_id' => $otherContract->id,
            'metadata' => ['contract_id' => $otherContract->id],
        ]);
        $this->document($context, $project->id, 250, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $act->id,
        ]);
        $this->document($context, $project->id, 125, [
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
        ]);
        $this->document($context, $project->id, 50, [
            'source_type' => Contract::class, 'source_id' => $contract->id,
        ]);
        $this->document($context, $project->id, 25, [
            'metadata' => ['contract_id' => $contract->id, 'accrual_source' => 'management_accrual'],
        ]);

        $service = app(WipForecastReportService::class);
        $filters = $this->filters($context, $project->id);
        $report = $service->report($filters, $context->user);
        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame($contract->id, (int) $row['contract']['id']);
        $this->assertSame(100450.0, (float) $row['metrics']['ac']);
        $filtered = $service->report([...$filters, 'contract_id' => $contract->id], $context->user);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame(100450.0, (float) $filtered['rows'][0]['metrics']['ac']);
        $drillDown = $service->drillDown([...$filters, 'drill_down_key' => $row['drill_down_key']], $context->user);
        $this->assertSame(100450.0, (float) $drillDown['summary']['actual_cost']);
        $this->assertDatabaseCount('payment_documents', 5);
    }

    public function test_act_resolution_preserves_organization_and_project_boundaries(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $otherContract = $this->contract($context, $otherProject);
        $foreignContract = $this->contract($foreign, $project);
        $ownAct = $this->act($contract, $project->id);
        $otherAct = $this->act($otherContract, $otherProject->id);
        $foreignAct = $this->act($foreignContract, $project->id);
        $this->document($context, $project->id, 100, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $ownAct->id,
        ]);
        $this->document($context, $project->id, 20, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $foreignAct->id,
            'metadata' => ['contract_id' => $contract->id],
        ]);
        $this->document($context, $project->id, 30, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $otherAct->id,
        ]);
        $this->document($foreign, $project->id, 9000, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $foreignAct->id,
        ]);
        $this->document($context, $otherProject->id, 8000, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $otherAct->id,
        ]);
        $this->document($context, $project->id, 7000, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $ownAct->id,
            'direction' => InvoiceDirection::INCOMING,
        ]);
        $this->document($context, $project->id, 6000, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $ownAct->id,
            'status' => PaymentDocumentStatus::DRAFT,
        ]);

        $service = app(WipForecastReportService::class);
        $filters = $this->filters($context, $project->id);
        $report = $service->report($filters, $context->user);
        $rows = collect($report['rows']);
        $own = $rows->first(fn (array $row): bool => (int) ($row['contract']['id'] ?? 0) === $contract->id);
        $this->assertNotNull($own);
        $this->assertSame(100.0, (float) $own['metrics']['ac']);
        $this->assertSame(150.0, $rows->sum(fn (array $row): float => (float) $row['metrics']['ac']));
        $this->assertFalse($rows->contains(fn (array $row): bool => in_array(
            (int) ($row['contract']['id'] ?? 0), [$otherContract->id, $foreignContract->id], true,
        )));
        $filtered = $service->report([...$filters, 'contract_id' => $contract->id], $context->user);
        $this->assertSame(100.0, (float) $filtered['rows'][0]['metrics']['ac']);
    }

    public function test_legacy_act_without_project_uses_contract_project_without_rewriting_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $act = $this->act($contract, null);
        $this->document($context, $project->id, 200, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $act->id,
        ]);
        $unassigned = $this->document($context, null, 100, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
        ]);
        $originalMetadata = $unassigned->metadata;
        $service = app(WipForecastReportService::class);
        $report = $service->report($this->filters($context, $project->id), $context->user);
        $this->assertCount(1, $report['rows']);
        $this->assertSame($contract->id, (int) $report['rows'][0]['contract']['id']);
        $this->assertSame(200.0, (float) $report['rows'][0]['metrics']['ac']);
        $all = $service->report($this->filters($context, null), $context->user);
        $this->assertSame(300.0, collect($all['rows'])->sum(fn (array $row): float => (float) $row['metrics']['ac']));
        $this->assertNull($act->fresh()->project_id);
        $this->assertNull($unassigned->fresh()->project_id);
        $this->assertSame($originalMetadata, $unassigned->fresh()->metadata);
    }

    public function test_legacy_multi_project_act_requires_contract_project_membership(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $secondProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unlinkedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $contract->forceFill(['is_multi_project' => true, 'project_id' => null])->saveQuietly();
        $contract->projects()->sync([$project->id, $secondProject->id]);
        $act = $this->act($contract, null);
        $this->document($context, $project->id, 100, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
        ]);
        $this->document($context, $secondProject->id, 200, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $act->id,
        ]);
        $this->document($context, $unlinkedProject->id, 300, [
            'source_type' => ContractPerformanceAct::class, 'source_id' => $act->id,
        ]);

        $service = app(WipForecastReportService::class);
        foreach ([[$project->id, 100.0], [$secondProject->id, 200.0]] as [$projectId, $cost]) {
            $report = $service->report($this->filters($context, $projectId), $context->user);
            $this->assertCount(1, $report['rows']);
            $this->assertSame($contract->id, (int) $report['rows'][0]['contract']['id']);
            $this->assertSame($cost, (float) $report['rows'][0]['metrics']['ac']);
        }
        $filters = $this->filters($context, $unlinkedProject->id);
        $unlinked = $service->report($filters, $context->user);
        $this->assertCount(1, $unlinked['rows']);
        $this->assertNull($unlinked['rows'][0]['contract']);
        $this->assertSame(300.0, (float) $unlinked['rows'][0]['metrics']['ac']);
        $filtered = $service->report([...$filters, 'contract_id' => $contract->id], $context->user);
        $this->assertSame([], $filtered['rows']);
        $all = $service->report($this->filters($context, null), $context->user);
        $this->assertSame(600.0, collect($all['rows'])->sum(fn (array $row): float => (float) $row['metrics']['ac']));
    }

    public function test_deleted_act_contract_is_not_resolved_but_cost_remains_visible(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contract = $this->contract($context, $project);
        $act = $this->act($contract, $project->id);
        $this->document($context, $project->id, 100, [
            'invoiceable_type' => ContractPerformanceAct::class, 'invoiceable_id' => $act->id,
        ]);
        $contract->deleteQuietly();

        $service = app(WipForecastReportService::class);
        $filters = $this->filters($context, $project->id);
        $report = $service->report($filters, $context->user);
        $this->assertCount(1, $report['rows']);
        $this->assertNull($report['rows'][0]['contract']);
        $this->assertSame(100.0, (float) $report['rows'][0]['metrics']['ac']);
        $filtered = $service->report([...$filters, 'contract_id' => $contract->id], $context->user);
        $this->assertSame([], $filtered['rows']);
    }

    private function contract(AdminApiTestContext $context, Project $project): Contract
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'WIP contractor',
            'inn' => (string) random_int(1000000000, 9999999999),
        ]);

        return Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'WIP-'.uniqid(),
            'date' => '2026-10-01', 'subject' => 'WIP test', 'total_amount' => 100000, 'status' => 'active',
        ]);
    }

    private function act(Contract $contract, ?int $projectId): ContractPerformanceAct
    {
        return ContractPerformanceAct::withoutEvents(static fn (): ContractPerformanceAct => ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $projectId, 'act_document_number' => 'WIP-ACT-'.uniqid(),
            'act_date' => '2026-10-16', 'period_start' => '2026-10-01', 'period_end' => '2026-10-16',
            'amount' => 100000, 'amount_without_vat' => 100000, 'vat_rate' => 0, 'vat_amount' => 0,
            'currency' => 'RUB', 'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]));
    }

    private function document(AdminApiTestContext $context, ?int $projectId, float $amount, array $attributes): PaymentDocument
    {
        return PaymentDocument::query()->create(array_merge([
            'organization_id' => $context->organization->id, 'project_id' => $projectId,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'WIP-DOC-'.uniqid(),
            'document_date' => '2026-10-16', 'due_date' => '2026-10-20', 'direction' => InvoiceDirection::OUTGOING,
            'amount' => $amount, 'amount_without_vat' => $amount, 'paid_amount' => 0,
            'remaining_amount' => $amount, 'currency' => 'RUB', 'status' => PaymentDocumentStatus::APPROVED,
        ], $attributes));
    }

    private function filters(AdminApiTestContext $context, ?int $projectId): array
    {
        return [
            'organization_id' => $context->organization->id, 'project_id' => $projectId,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'as_of_date' => '2026-10-31',
            'currency' => 'RUB', 'group_by' => ['project', 'contract', 'currency'],
            'use_live_sources' => true, '_skip_data_mart_meta' => true,
        ];
    }
}
