<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ActingQuantityConflictException;
use App\Models\ActingPolicy;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\ContractPeriodCertificate;
use App\Models\File;
use App\Models\MeasurementUnit;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ContractPeriodCertificateService;
use App\Services\ActReport\ActReportWorkflowService;
use App\Services\Pto\PtoWorkspaceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ActingWizardPartialAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_shows_presented_accepted_remarks_and_server_available_to_act(): void
    {
        $this->authorizeHttp();
        $context = AdminApiTestContext::create();
        [$contract, $work] = $this->workFixture($context, 100);
        $this->acceptance($contract, $work, '100', '80', '20');

        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ])->assertOk();
        $row = $response->json('data.available_works.0');
        self::assertSame($work->id, $row['completed_work_id']);
        self::assertEquals(100, $row['presented_quantity']);
        self::assertEquals(80, $row['accepted_quantity']);
        self::assertEquals(20, $row['with_remarks_quantity']);
        self::assertEquals(100, $row['available_to_act']);
        self::assertEquals($row['available_quantity'], $row['available_to_act']);

        ActingPolicy::query()->create([
            'organization_id' => $contract->organization_id,
            'contract_id' => $contract->id,
            'mode' => ActingPolicy::MODE_OPERATIONAL,
            'settings' => ['technical_acceptance' => ['mode' => 'accepted_only']],
        ]);
        $acceptedOnly = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/act-reports/preview', [
            'contract_id' => $contract->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ])->assertOk()->json('data.available_works.0');
        self::assertEquals(100, $acceptedOnly['presented_quantity']);
        self::assertEquals(80, $acceptedOnly['accepted_quantity']);
        self::assertEquals(20, $acceptedOnly['with_remarks_quantity']);
        self::assertEquals(80, $acceptedOnly['available_to_act']);
    }

    public function test_remainder_conflict_returns_new_available_and_does_not_write_other_rows(): void
    {
        $this->authorizeHttp();
        $context = AdminApiTestContext::create();
        [$contract, $firstWork] = $this->workFixture($context, 10, '2026-09-10');
        $secondWork = $this->confirmedWork($contract, $context->user, 10, '2026-09-12');
        app(ActingActWizardService::class)->createFromWizard((int) $contract->organization_id, [
            'contract_id' => $contract->id,
            'act_document_number' => 'T25-FIRST',
            'act_date' => '2026-09-20',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'selected_works' => [['completed_work_id' => $firstWork->id, 'quantity' => '7']],
        ], (int) $context->user->id, false);

        try {
            app(ActingActWizardService::class)->createFromWizard((int) $contract->organization_id, [
                'contract_id' => $contract->id,
                'act_document_number' => 'T25-SECOND',
                'act_date' => '2026-09-20',
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'selected_works' => [
                    ['completed_work_id' => $secondWork->id, 'quantity' => '5'],
                    ['completed_work_id' => $firstWork->id, 'quantity' => '10'],
                ],
            ], (int) $context->user->id, false);
            self::fail('Конфликт остатка должен остановить запись');
        } catch (ActingQuantityConflictException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame($firstWork->id, $exception->completedWorkId);
            self::assertSame('3.000', $exception->availableToAct);
            self::assertSame('10.000', $exception->requestedQuantity);
            self::assertSame($firstWork->id, $exception->payload()['conflict']['completed_work_id']);
        }

        self::assertSame(0, PerformanceActLine::query()->where('completed_work_id', $secondWork->id)->count());
        self::assertSame(1, ContractPerformanceAct::query()->where('contract_id', $contract->id)->count());
        self::assertEquals(7, PerformanceActLine::query()->where('completed_work_id', $firstWork->id)->sum('quantity'));

        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/act-reports/create-from-wizard', [
            'contract_id' => $contract->id,
            'act_document_number' => 'T25-HTTP',
            'act_date' => '2026-09-20',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'selected_works' => [
                ['completed_work_id' => $secondWork->id, 'quantity' => 5],
                ['completed_work_id' => $firstWork->id, 'quantity' => 10],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('conflict.completed_work_id', $firstWork->id);
        $response->assertJsonPath('conflict.available_to_act', '3.000');
        $response->assertJsonPath('conflict.requested_quantity', '10.000');
        self::assertSame(0, PerformanceActLine::query()->where('completed_work_id', $secondWork->id)->count());
        self::assertSame(1, ContractPerformanceAct::query()->where('contract_id', $contract->id)->count());
    }

    public function test_new_act_does_not_change_approved_certificate_snapshot(): void
    {
        $context = AdminApiTestContext::create();
        [$contract, $firstWork] = $this->workFixture($context, 10, '2026-01-05');
        $firstAct = $this->approvedAct($contract, $context->user, $firstWork, 'T25-KS3-A', '10');
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->approve(
            $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-t25-0001', $context->user->id),
            $context->user->id,
        );
        $before = $certificate->snapshot;
        $beforeTotals = $certificate->totals;

        $secondWork = $this->confirmedWork($contract, $context->user, 8, '2026-01-20');
        $this->approvedAct($contract, $context->user, $secondWork, 'T25-KS3-B', '8');

        $fresh = $certificate->fresh();
        self::assertSame($before, $fresh->snapshot);
        self::assertSame($beforeTotals, $fresh->totals);
        self::assertNotContains($secondWork->id, $fresh->source_act_ids ?? []);
        self::assertSame(ContractPeriodCertificate::STATUS_APPROVED, $fresh->status);
    }

    public function test_signed_certificate_upload_does_not_recalculate_snapshot(): void
    {
        Storage::fake('s3');
        $this->withoutMiddleware();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnTrue();
        });
        $context = AdminApiTestContext::create();
        [$contract, $work] = $this->workFixture($context, 10, '2026-01-05');
        $this->approvedAct($contract, $context->user, $work, 'T25-SIGN-A', '10');
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->approve(
            $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-t25-sign-0001', $context->user->id),
            $context->user->id,
        );
        $before = $certificate->snapshot;
        $beforeTotals = $certificate->totals;
        $beforeChecksum = $service->checksum($service->exportDataset($certificate));

        $upload = $this->actingAs($context->user, 'api_admin')->post(
            "/api/v1/admin/act-reports/contract-period-certificates/{$certificate->id}/signed-file",
            ['file' => UploadedFile::fake()->createWithContent('ks3-signed.pdf', "%PDF-1.4\n".str_repeat('A', 512))],
        );
        self::assertSame(200, $upload->status(), (string) $upload->getContent());
        $upload->assertJsonPath('data.status', ContractPeriodCertificate::STATUS_SIGNED);
        self::assertNotNull($upload->json('data.signed_file_id'));

        $fresh = $certificate->fresh();
        self::assertSame($before, $fresh->snapshot);
        self::assertSame($beforeTotals, $fresh->totals);
        self::assertSame($beforeChecksum, $service->checksum($service->exportDataset($fresh)));
        self::assertSame(1, File::query()
            ->where('fileable_type', ContractPeriodCertificate::class)
            ->where('fileable_id', $certificate->id)
            ->where('category', 'signed_certificate')
            ->count());

        $download = $this->actingAs($context->user, 'api_admin')
            ->get("/api/v1/admin/act-reports/contract-period-certificates/{$certificate->id}/signed-file");
        self::assertSame(200, $download->baseResponse->getStatusCode());
    }

    public function test_open_requirement_blocks_work_with_pto_href(): void
    {
        $context = AdminApiTestContext::create();
        [$contract, $work] = $this->workFixture($context, 10);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'created_by' => $context->user->id,
            'set_number' => 'T25-PTO-1',
            'title' => 'Комплект актирования',
            'status' => 'draft',
        ]);
        $requirement = ExecutiveDocumentRequirement::query()->create([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'document_set_id' => $set->id,
            'completed_work_id' => $work->id,
            'stage' => 'acting',
            'requirement_key' => 'aosr',
            'title' => 'Нужен акт освидетельствования скрытых работ',
            'profile_type' => 'hidden_work_act',
            'applicability' => 'required',
            'revision' => 1,
            'source' => 'test',
            'source_revision' => '1',
            'rule_snapshot' => [],
            'coverage_scope' => [],
            'evidence' => [],
        ]);

        $blocked = app(ActingAvailabilityService::class)->getBlockedWorks($contract->id, '2026-09-01', '2026-09-30');
        self::assertSame($work->id, $blocked[0]['completed_work_id']);
        $blocker = collect($blocked[0]['blockers'])->firstWhere('code', 'executive_requirement_open');
        self::assertNotNull($blocker);
        self::assertSame((string) $requirement->id, $blocker['requirement_id']);
        self::assertSame(
            '/pto?project_id='.$contract->project_id.'&section=completeness&category=blocker&source_key='
            .PtoWorkspaceQuery::requirementSourceKey((int) $requirement->id).'&set_id='.$set->id,
            $blocker['href'],
        );
        self::assertSame([], app(ActingAvailabilityService::class)->getAvailableWorks($contract->id, '2026-09-01', '2026-09-30'));
    }

    private function authorizeHttp(): void
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
    }

    /**
     * @return array{0: Contract, 1: CompletedWork}
     */
    private function workFixture(AdminApiTestContext $context, float $quantity, string $date = '2026-09-20'): array
    {
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $context->organization->id,
            'name' => 'Подрядчик T25',
            'contractor_type' => 'manual',
        ]);
        $contract = Contract::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'T25-'.$context->organization->id,
            'date' => '2026-01-01',
            'status' => 'active',
            'total_amount' => 100000,
            'currency' => 'RUB',
        ]);
        $work = $this->confirmedWork($contract, $context->user, $quantity, $date);

        return [$contract, $work];
    }

    private function confirmedWork(Contract $contract, User $actor, float $quantity, string $date): CompletedWork
    {
        $unit = MeasurementUnit::query()->where('organization_id', $contract->organization_id)->firstOrFail();
        $type = WorkType::create([
            'organization_id' => $contract->organization_id,
            'name' => 'Работа '.$date.' '.bin2hex(random_bytes(2)),
            'measurement_unit_id' => $unit->id,
        ]);

        return CompletedWork::create([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'contract_id' => $contract->id,
            'work_type_id' => $type->id,
            'user_id' => $actor->id,
            'quantity' => $quantity,
            'completed_quantity' => $quantity,
            'price' => 50,
            'total_amount' => $quantity * 50,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'completion_date' => $date,
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
    }

    private function acceptance(Contract $contract, CompletedWork $work, string $presented, string $accepted, string $remarks): void
    {
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'created_by_user_id' => $work->user_id,
            'title' => 'Частичная приёмка T25',
            'status' => 'accepted',
        ]);
        $scope->workQuantities()->create([
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'completed_work_id' => $work->id,
            'unit_id' => $work->workType->measurement_unit_id,
            'presented_quantity' => $presented,
            'accepted_quantity' => $accepted,
            'defect_quantity' => $remarks,
            'defect_reason' => 'Замечание по качеству',
        ]);
    }

    private function approvedAct(
        Contract $contract,
        User $actor,
        CompletedWork $work,
        string $number,
        string $quantity,
    ): ContractPerformanceAct {
        $periodEnd = substr((string) $work->completion_date?->toDateString() ?? '2026-01-31', 0, 7).'-31';
        $periodStart = substr($periodEnd, 0, 7).'-01';
        $act = app(ActingActWizardService::class)->createFromWizard((int) $contract->organization_id, [
            'contract_id' => $contract->id,
            'act_document_number' => $number,
            'act_date' => '2026-02-10',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => $quantity]],
        ], $actor->id, false);
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $actor->id);

        return $workflow->approve($act->fresh(), $actor->id);
    }
}
