<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\Exceptions\BusinessLogicException;
use App\Models\ActingPolicy;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Acting\ActingActWizardService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformanceActFinancialBasisTest extends TestCase
{
    use RefreshDatabase;

    public function test_act_line_uses_and_persists_the_approved_estimate_version_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'FIN-ACT-001',
            'date' => '2026-08-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'number' => 'EST-001',
            'name' => 'Утверждённая смета',
            'status' => 'draft',
            'estimate_date' => '2026-08-01',
            'total_amount' => 1000,
            'total_amount_with_vat' => 1200,
            'vat_rate' => 20,
            'approved_at' => now(),
            'approved_by_user_id' => $actor->id,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => 'work',
            'name' => 'Работа по снимку',
            'quantity' => 1,
            'quantity_total' => 1,
            'unit_price' => 1000,
            'current_unit_price' => 1000,
            'total_amount' => 1000,
            'current_total_amount' => 1000,
        ]);
        ContractEstimateItem::query()->create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 1,
            'amount' => 1000,
        ]);
        $version = app(EstimateVersioningService::class)->createSnapshot(
            estimate: $estimate,
            actorId: $actor->id,
            label: 'Утверждённая версия',
            snapshotType: 'approval',
        );
        $item->forceFill([
            'unit_price' => 9000,
            'current_unit_price' => 9000,
            'total_amount' => 9000,
            'current_total_amount' => 9000,
        ])->saveQuietly();
        $estimate->forceFill([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $actor->id,
        ])->saveQuietly();
        $work = CompletedWork::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'estimate_item_id' => $item->id,
            'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            'quantity' => 1,
            'completed_quantity' => 1,
            'price' => 9000,
            'total_amount' => 9000,
            'completion_date' => '2026-08-10',
            'status' => 'confirmed',
        ]);

        $act = app(ActingActWizardService::class)->createFromWizard(
            organizationId: $organization->id,
            data: [
                'contract_id' => $contract->id,
                'act_document_number' => 'ACT-001',
                'act_date' => '2026-08-10',
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'selected_works' => [[
                    'completed_work_id' => $work->id,
                    'quantity' => 1,
                ]],
            ],
            userId: $actor->id,
            canManageManualLines: false,
        );

        $line = $act->lines()->firstOrFail();

        self::assertSame($version->id, $act->estimate_version_id);
        self::assertSame($version->id, $line->estimate_version_id);
        self::assertSame('1200.00', $line->unit_price);
        self::assertSame('1200.00', $line->amount);
        self::assertSame('20.00', $act->vat_rate);
        self::assertSame('200.00', $act->vat_amount);
        self::assertSame('1000.00', $act->amount_without_vat);
        self::assertSame($version->snapshot_hash, $line->basis_snapshot['estimate_version_hash']);
        self::assertSame($item->fresh()->stable_key, $line->basis_snapshot['estimate_item']['stable_key']);

        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $actor->id);
        $approved = $workflow->approve($act->fresh(), $actor->id);

        self::assertSame('1200.00', $approved->amount);
        self::assertSame('1200.00', $approved->lines()->firstOrFail()->amount);

        $invoice = PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'document_number' => 'INV-ACT-001',
            'document_date' => '2026-08-11',
            'direction' => 'outgoing',
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'origin_key' => "performance-act:{$approved->id}:outgoing",
            'amount' => 1200,
            'vat_rate' => 20,
            'vat_amount' => 200,
            'amount_without_vat' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1200,
            'currency' => 'RUB',
            'status' => 'approved',
        ]);
        $paidInvoice = PaymentDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'document_number' => 'INV-ACT-PAID',
            'document_date' => '2026-08-11',
            'direction' => 'incoming',
            'invoiceable_type' => ContractPerformanceAct::class,
            'invoiceable_id' => $approved->id,
            'origin_key' => null,
            'amount' => 1200,
            'paid_amount' => 1200,
            'remaining_amount' => 0,
            'currency' => 'RUB',
            'status' => 'paid',
        ]);
        self::assertSame(ContractPerformanceAct::class, $paidInvoice->invoiceable_type);
        self::assertSame(1, PaymentDocument::query()
            ->where('organization_id', $organization->id)
            ->where('invoiceable_type', ContractPerformanceAct::class)
            ->where('invoiceable_id', $approved->id)
            ->count());
        self::assertSame(2, PaymentDocument::query()
            ->where('organization_id', $organization->id)
            ->where(function ($query) use ($approved): void {
                $query->where('origin_key', 'like', 'performance-act:'.$approved->id.':%')
                    ->orWhere(function ($morphQuery) use ($approved): void {
                        $morphQuery->whereIn('invoiceable_type', [
                            ContractPerformanceAct::class,
                            $approved->getMorphClass(),
                        ])->where('invoiceable_id', $approved->id);
                    });
            })->count());

        try {
            $workflow->annul(
                $approved,
                $actor->id,
                'Ошибка в основании акта',
                'annul-act-fin-paid',
            );
            self::fail('Зачтённая оплата должна блокировать аннулирование акта');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $paidInvoice->forceFill([
            'status' => 'cancelled',
            'paid_amount' => 0,
            'remaining_amount' => 0,
        ])->saveQuietly();

        $annulled = $workflow->annul(
            $approved,
            $actor->id,
            'Ошибка в основании акта',
            'annul-act-fin-001',
        );

        self::assertSame('annulled', $annulled->status);
        self::assertFalse($annulled->is_approved);
        self::assertSame('cancelled', $invoice->fresh()->status->value);
        $this->assertDatabaseHas('performance_act_reversals', [
            'performance_act_id' => $approved->id,
            'idempotency_key' => 'annul-act-fin-001',
        ]);
    }

    public function test_manual_line_requires_approved_variation_order_and_respects_its_remaining_amount(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'FIN-ACT-MANUAL',
            'date' => '2026-08-01',
            'subject' => 'Работы',
            'total_amount' => 100000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => 100000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $change = ChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $actor->id,
            'change_number' => 'CH-001',
            'title' => 'Дополнительные работы',
            'reason' => 'customer_request',
            'description' => 'Согласованный дополнительный объём',
            'initiator_type' => 'customer',
            'status' => 'approved',
            'reporting_currency' => 'RUB',
            'reporting_contract_project_allocation_id' => $allocationId,
            'approved_at' => now(),
        ]);
        $variation = VariationOrder::query()->create([
            'organization_id' => $organization->id,
            'change_request_id' => $change->id,
            'variation_number' => 'VO-001',
            'amount' => 1000,
            'description' => 'Согласованный лимит',
        ]);
        ActingPolicy::query()->create([
            'organization_id' => $organization->id,
            'contract_id' => $contract->id,
            'mode' => 'operational',
            'allow_manual_lines' => true,
            'require_manual_line_reason' => true,
        ]);
        $service = app(ActingActWizardService::class);
        $baseData = [
            'contract_id' => $contract->id,
            'act_date' => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'manual_lines' => [[
                'title' => 'Дополнительная работа',
                'quantity' => 1,
                'amount' => 600,
                'manual_reason' => 'Согласовано заказчиком',
            ]],
        ];

        try {
            $service->createFromWizard(
                $organization->id,
                [...$baseData, 'act_document_number' => 'ACT-MISSING-VO'],
                $actor->id,
                true,
            );
            self::fail('Ручная строка без согласованного основания не должна создаваться');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }

        $baseData['manual_lines'][0]['variation_order_id'] = $variation->id;
        $act = $service->createFromWizard(
            $organization->id,
            [...$baseData, 'act_document_number' => 'ACT-VO-1'],
            $actor->id,
            true,
        );

        self::assertSame('600.00', $act->amount);
        self::assertSame($variation->id, $act->lines()->firstOrFail()->variation_order_id);

        $baseData['manual_lines'][0]['amount'] = 500;
        $this->expectException(BusinessLogicException::class);
        $service->createFromWizard(
            $organization->id,
            [...$baseData, 'act_document_number' => 'ACT-VO-OVER'],
            $actor->id,
            true,
        );
    }
}
