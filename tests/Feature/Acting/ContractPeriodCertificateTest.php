<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\ContractPeriodCertificate;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ContractPeriodCertificateService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ReflectionClass;
use Tests\TestCase;

final class ContractPeriodCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_acts_enter_one_certificate_once_and_replay_is_idempotent(): void
    {
        [$contract, $actor, $firstAct, $secondAct] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);

        $certificate = $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-once-0001', $actor->id);
        $replay = $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-once-0001', $actor->id);

        $this->assertSame($certificate->id, $replay->id);
        $this->assertSame([$firstAct->id, $secondAct->id], $certificate->source_act_ids);
        $this->assertCount(2, $certificate->composition);
        $this->assertNull($certificate->approved_by_user_id);
        $this->assertSame(ContractPeriodCertificate::STATUS_DRAFT, $certificate->status);
        $this->assertSame('2026-01-31', $certificate->performed_at->toDateString());
        $this->assertSame(ContractPeriodCertificateService::CALCULATION_VERSION, $certificate->calculation_version);
        $this->assertSame(
            [(int) $firstAct->id, (int) $secondAct->id],
            collect($certificate->composition)->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
    }

    public function test_including_an_act_already_bound_to_an_approved_certificate_is_rejected(): void
    {
        [$contract, $actor, $firstAct] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->create(
            $contract,
            '2026-01-01',
            '2026-01-31',
            null,
            'ks3-bind-0001',
            $actor->id,
            [$firstAct->id],
        );
        $service->approve($certificate, $actor->id);

        try {
            $service->create(
                $contract,
                '2026-01-01',
                '2026-01-31',
                null,
                'ks3-bind-0002',
                $actor->id,
                [$firstAct->id],
            );
            $this->fail('Повторное включение акта должно быть отклонено');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertSame(trans_message('act_reports.certificate_act_already_included'), $exception->getMessage());
        }
    }

    public function test_approved_certificate_stays_frozen_after_real_annulment_and_new_act(): void
    {
        [$contract, $actor, $firstAct] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->approve(
            $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-frozen-0001', $actor->id),
            $actor->id,
        );
        $before = $certificate->snapshot;
        $beforeTotals = $certificate->totals;
        $beforeChecksum = $service->checksum($service->exportDataset($certificate));

        $annulled = app(ActReportWorkflowService::class)->annul(
            $firstAct,
            $actor->id,
            'Ошибка периода в акте',
            'annul-ks3-t21-0001',
        );
        $this->assertSame(ContractPerformanceAct::STATUS_ANNULLED, $annulled->status);

        $fresh = $certificate->fresh();
        $this->assertTrue($fresh->has_annulled_acts);
        $this->assertSame($before, $fresh->snapshot);
        $this->assertSame($beforeTotals, $fresh->totals);
        $this->assertSame($beforeChecksum, $service->checksum($service->exportDataset($fresh)));
        $this->assertContains((int) $firstAct->id, collect($fresh->composition)->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $newAct = $this->approvedAct($contract, $actor, $this->confirmedWork($contract, $actor, '2026-01-20', 8), 'T21-NEW', '2026-01-31', 8);
        $this->assertSame($beforeChecksum, $service->checksum($service->exportDataset($fresh->fresh())));
        $this->assertNotContains($newAct->id, $fresh->fresh()->source_act_ids);
    }

    public function test_document_date_and_execution_date_are_distinct_and_draft_has_no_approver(): void
    {
        [$contract, $actor] = $this->twoJanuaryActs();
        $certificate = app(ContractPeriodCertificateService::class)->create(
            $contract,
            '2026-01-01',
            '2026-01-31',
            null,
            'ks3-dates-0001',
            $actor->id,
            null,
            '2026-02-15',
        );

        $this->assertSame('2026-02-15', $certificate->document_date->toDateString());
        $this->assertSame('2026-01-31', $certificate->performed_at->toDateString());
        $this->assertNull($certificate->approved_by_user_id);
        $this->assertNull($certificate->approved_at);
        $this->assertSame('2026-01-31', $certificate->composition[0]['execution_date']);
        $this->assertNotSame($certificate->document_date->toDateString(), $certificate->composition[0]['execution_date']);
        $this->assertNotNull($certificate->composition[0]['vat_amount']);
    }

    public function test_null_project_identity_rejects_duplicate_version(): void
    {
        [$contract, $actor] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->approve(
            $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-unique-0001', $actor->id),
            $actor->id,
        );

        $this->expectException(UniqueConstraintViolationException::class);
        ContractPeriodCertificate::query()->create([
            'organization_id' => $certificate->organization_id,
            'contract_id' => $certificate->contract_id,
            'project_id' => null,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'number' => 'КС-3-DUP',
            'version_number' => $certificate->version_number,
            'status' => ContractPeriodCertificate::STATUS_DRAFT,
            'calculation_version' => ContractPeriodCertificateService::CALCULATION_VERSION,
            'idempotency_key' => 'ks3-unique-dup-0002',
            'payload_hash' => str_repeat('a', 64),
            'source_act_ids' => [],
            'composition' => [],
            'snapshot' => ['lines' => [], 'totals' => []],
            'totals' => ['period' => 0],
            'document_date' => '2026-01-31',
            'performed_at' => '2026-01-31',
            'has_annulled_acts' => false,
        ]);
    }

    public function test_pdf_and_xlsx_use_the_same_certificate_checksum(): void
    {
        [$contract, $actor] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);
        $certificate = $service->approve(
            $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-sum-0001', $actor->id),
            $actor->id,
        );
        $export = app(OfficialFormsExportService::class);
        $dataset = $export->prepareKS3CertificateData($certificate);
        $checksum = $export->ks3Checksum($dataset);
        $this->assertFalse($dataset['is_draft']);
        $this->assertSame($checksum, $service->checksum($dataset));

        $sheet = (new Spreadsheet)->getActiveSheet();
        $reflection = new ReflectionClass($export);
        $reflection->getMethod('setKS3DatasetHeader')->invoke($export, $sheet, $dataset);
        $reflection->getMethod('setKS3DatasetItems')->invoke($export, $sheet, $dataset);
        $found = [];
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $title = (string) $sheet->getCell("B{$row}")->getValue();
            if ($title === 'Всего работ и затрат, включаемых в стоимость работ') {
                $found['totals'] = [
                    (float) $sheet->getCell("D{$row}")->getValue(),
                    (float) $sheet->getCell("E{$row}")->getValue(),
                    (float) $sheet->getCell("F{$row}")->getValue(),
                ];
            }
            if ($title === 'В том числе НДС') {
                $found['vat'] = (float) $sheet->getCell("F{$row}")->getValue();
            }
        }

        $this->assertSame([$checksum['from_start'], $checksum['year_total'], $checksum['period']], $found['totals']);
        $this->assertSame($checksum['vat'], $found['vat']);
        $this->assertSame($checksum['period'], round((float) $certificate->totals['period'], 2));
    }

    public function test_same_idempotency_key_with_changed_payload_is_conflict(): void
    {
        [$contract, $actor] = $this->twoJanuaryActs();
        $service = app(ContractPeriodCertificateService::class);
        $service->create($contract, '2026-01-01', '2026-01-31', null, 'ks3-conflict-0001', $actor->id);

        try {
            $service->create($contract, '2026-01-02', '2026-01-31', null, 'ks3-conflict-0001', $actor->id);
            $this->fail('Изменённый повтор должен конфликтовать');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(409, $exception->getCode());
        }
    }

    public function test_http_create_and_approve_return_russian_payload(): void
    {
        [$contract, $actor] = $this->twoJanuaryActs();
        $this->withoutMiddleware();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });

        $create = $this->actingAs($actor, 'api_admin')->postJson('/api/v1/admin/act-reports/contract-period-certificates', [
            'contract_id' => $contract->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'document_date' => '2026-02-01',
            'idempotency_key' => 'ks3-http-0001',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('success', true);
        $create->assertJsonPath('data.status', ContractPeriodCertificate::STATUS_DRAFT);
        $create->assertJsonPath('data.approved_by_user_id', null);
        $this->assertSame('Справка КС-3 создана', $create->json('message'));

        $id = (int) $create->json('data.id');
        $approve = $this->actingAs($actor, 'api_admin')->postJson("/api/v1/admin/act-reports/contract-period-certificates/{$id}/approve");
        $approve->assertOk();
        $approve->assertJsonPath('data.status', ContractPeriodCertificate::STATUS_APPROVED);
        $approve->assertJsonPath('data.is_frozen', true);
        $this->assertSame('Справка КС-3 утверждена', $approve->json('message'));
    }

    /**
     * @return array{0: Contract, 1: User, 2: ContractPerformanceAct, 3: ContractPerformanceAct}
     */
    private function twoJanuaryActs(): array
    {
        $context = $this->context();
        $firstWork = $this->confirmedWork($context['contract'], $context['actor'], '2026-01-05', 10);
        $secondWork = $this->confirmedWork($context['contract'], $context['actor'], '2026-01-20', 20);
        $firstAct = $this->approvedAct($context['contract'], $context['actor'], $firstWork, 'T21-A', '2026-01-31', 10);
        $secondAct = $this->approvedAct($context['contract'], $context['actor'], $secondWork, 'T21-B', '2026-01-31', 20);

        return [$context['contract'], $context['actor'], $firstAct, $secondAct];
    }

    /**
     * @return array{organization: Organization, project: Project, actor: User, contract: Contract, unit: MeasurementUnit}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик КС-3',
            'contractor_type' => 'manual',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'T21-KS3',
            'date' => '2025-12-01',
            'status' => 'active',
            'total_amount' => 10000,
            'is_fixed_amount' => true,
            'currency' => 'RUB',
        ]);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->firstOrFail();

        return compact('organization', 'project', 'actor', 'contract', 'unit');
    }

    private function confirmedWork(Contract $contract, User $actor, string $date, float $quantity): CompletedWork
    {
        $unit = MeasurementUnit::query()->where('organization_id', $contract->organization_id)->firstOrFail();
        $type = WorkType::create([
            'organization_id' => $contract->organization_id,
            'name' => 'Работа '.$date,
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
            'price' => 1,
            'total_amount' => $quantity,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'completion_date' => $date,
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
    }

    private function approvedAct(
        Contract $contract,
        User $actor,
        CompletedWork $work,
        string $number,
        string $periodEnd,
        float $quantity,
    ): ContractPerformanceAct {
        $act = app(ActingActWizardService::class)->createFromWizard($contract->organization_id, [
            'contract_id' => $contract->id,
            'act_document_number' => $number,
            'act_date' => '2026-02-10',
            'period_start' => substr($periodEnd, 0, 7).'-01',
            'period_end' => $periodEnd,
            'selected_works' => [['completed_work_id' => $work->id, 'quantity' => $quantity]],
        ], $actor->id, false);
        $workflow = app(ActReportWorkflowService::class);
        $workflow->submit($act, $actor->id);

        return $workflow->approve($act->fresh(), $actor->id);
    }
}
