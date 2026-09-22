<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionAccounting;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveTransmittalService;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ActingPolicy;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\MeasurementUnit;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\WorkType;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\Support\ExecutiveDocumentRequirementFixture;
use Tests\Support\SubmitsWorkVolumeStatements;
use Tests\TestCase;

final class ConstructionAccountingLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsWorkVolumeStatements;

    public function test_wvs_fact_acceptance_act_reserve_itd_return_v2_and_package_acceptance(): void
    {
        Storage::fake('s3');
        $this->bootAccess();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'Подрядчик T28',
            'contractor_type' => 'manual',
        ]);
        $contract = Contract::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'T28-LIFECYCLE',
            'date' => '2026-01-01',
            'status' => 'active',
            'total_amount' => 100000,
            'currency' => 'RUB',
        ]);
        $unit = MeasurementUnit::query()->where('organization_id', $context->organization->id)->firstOrFail();

        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $wvs = app(WorkVolumeStatementService::class);
        $statement = $this->approveReviewed($wvs, $context->user, $wvs->createDraft($context->user, $project->id, [
            'lines' => [[
                'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'name' => 'Стена T28',
                'unit_code' => 'м²',
                'quantity' => '100',
                'place' => ['axis' => 'А-1'],
            ]],
        ]));
        self::assertSame('approved', $statement->status);
        self::assertSame('100.000000', $statement->fresh('lines')->lines->first()->quantity);
        $this->app->forgetInstance(AuthorizationService::class);

        $type = WorkType::create([
            'organization_id' => $context->organization->id,
            'name' => 'Работа T28',
            'measurement_unit_id' => $unit->id,
        ]);
        $work = CompletedWork::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'work_type_id' => $type->id,
            'user_id' => $context->user->id,
            'quantity' => 100,
            'completed_quantity' => 100,
            'price' => 50,
            'total_amount' => 5000,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'completion_date' => '2026-09-15',
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
        self::assertSame(100.0, (float) $work->fresh()->completed_quantity);

        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Частичная приёмка T28',
            'status' => 'in_progress',
        ]);
        app(TechnicalAcceptanceQuantityService::class)->draft(
            $context->organization->id,
            $context->user->id,
            $scope->id,
            [[
                'completed_work_id' => $work->id,
                'unit_id' => $unit->id,
                'presented_quantity' => '100',
                'accepted_quantity' => '80',
                'defect_quantity' => '20',
                'defect_reason' => 'Замечание по качеству',
            ]],
            0,
            't28-acceptance-quantities',
        );
        $accepted = app(HandoverAcceptanceService::class)->acceptScope($scope->fresh(), $context->user->id, null);
        self::assertSame('accepted', $accepted->status);
        $qty = $accepted->workQuantities->first();
        self::assertSame('100.000000', (string) $qty->presented_quantity);
        self::assertSame('80.000000', (string) $qty->accepted_quantity);
        self::assertSame('20.000000', (string) $qty->defect_quantity);

        ActingPolicy::query()->create([
            'organization_id' => $context->organization->id,
            'contract_id' => $contract->id,
            'mode' => ActingPolicy::MODE_OPERATIONAL,
            'settings' => ['technical_acceptance' => ['mode' => 'accepted_only']],
        ]);
        $beforeAct = app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertSame($work->id, $beforeAct[0]['completed_work_id']);
        self::assertEquals(80.0, $beforeAct[0]['available_quantity']);

        $act = app(ActingActWizardService::class)->createFromWizard((int) $context->organization->id, [
            'contract_id' => $contract->id,
            'act_document_number' => 'T28-KS2-1',
            'act_date' => '2026-09-20',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => '60']],
        ], (int) $context->user->id, false);
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $context->user->id);
        $approvedAct = $workflow->approve($act->fresh(), $context->user->id);
        self::assertSame('approved', $approvedAct->status);
        self::assertSame(60.0, (float) PerformanceActLine::query()->where('completed_work_id', $work->id)->sum('quantity'));

        DB::transaction(function () use ($work, $approvedAct): void {
            $locked = CompletedWork::query()->whereKey($work->id)->lockForUpdate()->get();
            $available = app(ActingQuantityReservationService::class)->availableQuantities(
                $locked,
                null,
                ['settings' => ['technical_acceptance' => ['mode' => 'accepted_only']]],
            );
            self::assertSame(200000, $available[$work->id]);
            self::assertSame(
                800000,
                app(ActingQuantityReservationService::class)->availableQuantities(
                    $locked,
                    $approvedAct->id,
                    ['settings' => ['technical_acceptance' => ['mode' => 'accepted_only']]],
                )[$work->id],
            );
        });
        $afterAct = app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertEquals(20.0, $afterAct[0]['available_quantity']);

        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'T28-SET-1',
            'title' => 'Комплект T28',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Рабочие чертежи T28',
            'status' => 'draft',
            'profile_data' => [
                'drawing_set_code' => 'РД-T28',
                'drawing_section' => 'АР',
                'sheet_list' => ['1'],
                'compliance_mark' => 'Соответствует',
                'responsible_person' => 'Инженер ПТО',
                'authority_document' => 'Приказ 1',
                'drawing_set_status' => 'review',
            ],
        ]);
        $executive = app(ExecutiveDocumentationService::class);
        $v1 = $executive->addVersion($document, $context->user->id, [
            'version_number' => '1',
            'file' => UploadedFile::fake()->createWithContent('t28-v1.pdf', 'version-one-content'),
        ]);
        $executive->submit($document->fresh(), $context->user->id, null, $v1->id);
        $executive->approve($document->fresh(), $context->user->id, null, $v1->id);
        ExecutiveDocumentRequirementFixture::cover($set->fresh(), $v1->fresh(), $context->user);
        $firstTransmittal = $executive->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T28-TR-1',
            'operation_key' => 't28-transmit-v1',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;
        $v1Manifest = $firstTransmittal->manifest;
        $v1Hash = $firstTransmittal->manifest_hash;
        self::assertSame($v1->id, $v1Manifest['documents'][0]['version_id']);
        self::assertSame($v1->content_hash, $v1Manifest['documents'][0]['content_hash']);

        $customer = app(ExecutiveTransmittalService::class);
        $customer->decide($firstTransmittal->id, $context->user->id, 'receive', [
            'operation_key' => 't28-receive-v1',
            'expected_manifest_hash' => $v1Hash,
        ]);
        $customer->decide($firstTransmittal->id, $context->user->id, 'return', [
            'operation_key' => 't28-return-v1',
            'expected_manifest_hash' => $v1Hash,
            'comment' => 'Исправить схему осей',
        ]);
        self::assertSame('returned', $firstTransmittal->fresh()->status);
        self::assertSame($v1Manifest, $firstTransmittal->fresh()->manifest);
        self::assertSame($v1Hash, $firstTransmittal->fresh()->manifest_hash);

        $v2 = $executive->addVersion($document->fresh(), $context->user->id, [
            'expected_version_id' => $v1->id,
            'version_number' => '2',
            'file' => UploadedFile::fake()->createWithContent('t28-v2.pdf', 'version-two-content'),
        ]);
        self::assertSame('draft', $set->fresh()->status->value);
        $executive->submit($document->fresh(), $context->user->id, null, $v2->id);
        $executive->approve($document->fresh(), $context->user->id, null, $v2->id);
        ExecutiveDocumentRequirementFixture::cover($set->fresh(), $v2->fresh(), $context->user);
        $secondTransmittal = $executive->transmit($set->fresh(), $context->user->id, [
            'transmittal_number' => 'T28-TR-2',
            'operation_key' => 't28-transmit-v2',
            'recipient' => ['organization_id' => $context->organization->id, 'name' => 'Заказчик'],
        ])->transmittal;
        self::assertNotSame($firstTransmittal->id, $secondTransmittal->id);
        self::assertSame($v2->id, $secondTransmittal->manifest['documents'][0]['version_id']);
        self::assertSame($v1->id, $firstTransmittal->fresh()->manifest['documents'][0]['version_id']);
        self::assertSame($v1Hash, $firstTransmittal->fresh()->manifest_hash);
        self::assertSame($v1->content_hash, $firstTransmittal->fresh()->manifest['documents'][0]['content_hash']);
        self::assertSame('100.000000', $statement->fresh('lines')->lines->first()->quantity);
        self::assertSame(60.0, (float) PerformanceActLine::query()->where('completed_work_id', $work->id)->sum('quantity'));

        $customer->decide($secondTransmittal->id, $context->user->id, 'receive', [
            'operation_key' => 't28-receive-v2',
            'expected_manifest_hash' => $secondTransmittal->manifest_hash,
        ]);
        $customer->decide($secondTransmittal->id, $context->user->id, 'accept', [
            'operation_key' => 't28-accept-v2',
            'expected_manifest_hash' => $secondTransmittal->manifest_hash,
        ]);
        self::assertSame('accepted', $secondTransmittal->fresh()->status);

        $handover = app(HandoverAcceptanceService::class);
        $handover->createPackage($accepted->fresh(), $context->user->id, [
            'title' => 'Передача комплекта T28',
            'executive_document_set_id' => $set->id,
            'documents' => [[
                'title' => 'РД',
                'document_type' => 'working_drawing_set',
                'is_required' => true,
                'executive_document_version_id' => $v2->id,
            ]],
        ]);
        $handed = $handover->handoverScope($accepted->fresh(), $context->user->id);
        self::assertSame('handed_over', $handed->status);
        $package = $handed->fresh()->handoverPackage()->with('documents')->first();
        self::assertNotNull($package);
        self::assertSame($v2->id, $package->documents->first()->executive_document_version_id);
        self::assertSame($v1->id, $firstTransmittal->fresh()->manifest['documents'][0]['version_id']);
        $remaining = app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertNotEmpty($remaining);
        self::assertEquals(20.0, $remaining[0]['available_quantity']);
    }

    private function bootAccess(): void
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
    }
}
