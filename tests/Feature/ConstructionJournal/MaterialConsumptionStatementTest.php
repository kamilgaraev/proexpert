<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BudgetEstimates\Services\MaterialConsumptionStatementService;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\MaterialConsumptionReadinessException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\File;
use App\Models\Material;
use App\Models\MaterialConsumptionFact;
use App\Models\MaterialConsumptionRate;
use App\Models\MaterialConsumptionStatement;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\MaterialConsumption\MaterialConsumptionFactService;
use App\Services\MaterialConsumption\MaterialConsumptionRateService;
use App\Services\MaterialConsumption\MaterialConsumptionStatementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

final class MaterialConsumptionStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_times_volume_shows_deviation_reason_and_basis(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);
        $this->consumption($context, $work, '170', [
            'deviation_reason' => 'Потери при укладке',
            'agreed_by_user_id' => $context['actor']->id,
        ]);

        $statement = $this->draftStatement($context);
        $lineI = $statement->snapshot['section_i'][0];
        $lineII = $statement->snapshot['section_ii'][0];

        $this->assertSame('80', $lineI['accepted_volume']);
        $this->assertSame('2', $lineI['rate_per_unit']);
        $this->assertSame('160', $lineI['normative_need']);
        $this->assertSame('ГЭСН 08-02-001-01', $lineI['rate_basis_text']);
        $this->assertSame('160', $lineII['normative_consumption']);
        $this->assertSame('170', $lineII['actual_consumption']);
        $this->assertSame('10', $lineII['deviation']);
        $this->assertSame('10', $lineII['overconsumption']);
        $this->assertSame('0', $lineII['economy']);
        $this->assertSame('Потери при укладке', $lineII['deviation_reason']);
        $this->assertSame($context['actor']->id, $lineII['agreed_by_user_id']);
        $this->assertTrue($statement->is_ready);
    }

    public function test_warehouse_issue_is_not_consumption_and_links_batch_and_quality(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);
        $movement = $this->warehouseIssue($context, '200', 'П-12');
        $quality = $this->qualityPassport($context);
        $fact = $this->consumption($context, $work, '170', [
            'warehouse_movement_id' => $movement->id,
            'quality_document_id' => $quality->id,
            'batch_number' => 'П-12',
            'deviation_reason' => 'Потери при укладке',
        ]);

        $this->assertSame($movement->id, $fact->warehouse_movement_id);
        $this->assertSame($quality->id, $fact->quality_document_id);
        $this->assertSame('П-12', $fact->batch_number);
        $this->assertSame($work->id, $fact->completed_work_id);
        $this->assertEquals(30, (float) $fact->site_remainder_quantity);

        $statement = $this->draftStatement($context);
        $line = $statement->snapshot['section_ii'][0];
        $this->assertSame('170', $line['actual_consumption']);
        $this->assertSame('200', $line['warehouse_issued']);
        $this->assertSame('30', $line['site_remainder']);
        $this->assertSame([$movement->id], $line['warehouse_movement_ids']);
        $this->assertNotEquals($line['actual_consumption'], $line['warehouse_issued']);
        $this->assertSame(WarehouseMovement::TYPE_WRITE_OFF, $movement->fresh()->movement_type);
    }

    public function test_return_and_replay_do_not_double_write(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);
        $facts = app(MaterialConsumptionFactService::class);
        $first = $facts->record($context['organization']->id, $this->factPayload($work, $context, '170', [
            'operation_key' => 'cons-replay-0001',
        ]), $context['actor']->id);
        $replay = $facts->record($context['organization']->id, $this->factPayload($work, $context, '170', [
            'operation_key' => 'cons-replay-0001',
        ]), $context['actor']->id);
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, MaterialConsumptionFact::query()->where('completed_work_id', $work->id)->count());

        $returned = $facts->record($context['organization']->id, $this->factPayload($work, $context, '20', [
            'kind' => MaterialConsumptionFact::KIND_RETURN,
            'operation_key' => 'cons-return-0001',
        ]), $context['actor']->id);
        $returnedAgain = $facts->record($context['organization']->id, $this->factPayload($work, $context, '20', [
            'kind' => MaterialConsumptionFact::KIND_RETURN,
            'operation_key' => 'cons-return-0001',
        ]), $context['actor']->id);
        $this->assertSame($returned->id, $returnedAgain->id);
        $this->assertSame(2, MaterialConsumptionFact::query()->where('completed_work_id', $work->id)->count());
        $this->assertEquals(150, (float) (string) $facts->netConverted(
            $context['organization']->id,
            (int) $work->id,
            (int) $context['material']->id,
        ));

        try {
            $facts->record($context['organization']->id, $this->factPayload($work, $context, '180', [
                'operation_key' => 'cons-replay-0001',
            ]), $context['actor']->id);
            $this->fail('Изменённый повтор должен конфликтовать');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(409, $exception->getCode());
        }
    }

    public function test_statement_cannot_be_approved_without_rate_or_unlinked_work(): void
    {
        $context = $this->context();
        $work = $this->confirmedWork($context, 80);
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessage(trans_message('material_consumption.rate_not_approved'));
        $this->consumption($context, $work, '170');
    }

    public function test_statement_without_rate_for_work_material_is_not_ready(): void
    {
        $context = $this->context();
        $this->confirmedWork($context, 80);
        $this->approvedRate($context, '2');
        $otherMaterial = Material::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'Песок',
            'measurement_unit_id' => $context['kg']->id,
            'is_active' => true,
        ]);
        MaterialConsumptionFact::query()->create([
            'organization_id' => $context['organization']->id,
            'project_id' => $context['project']->id,
            'completed_work_id' => CompletedWork::query()->value('id'),
            'material_id' => $otherMaterial->id,
            'rate_id' => null,
            'kind' => MaterialConsumptionFact::KIND_CONSUMPTION,
            'quantity' => '1',
            'unit_id' => $context['kg']->id,
            'converted_quantity' => '1',
            'occurred_on' => '2026-01-20',
            'operation_key' => 'orphan-fact-0001',
            'payload_hash' => str_repeat('b', 64),
            'created_by_user_id' => $context['actor']->id,
        ]);

        $statement = $this->draftStatement($context);
        $this->assertFalse($statement->is_ready);
        $this->assertNotEmpty($statement->blockers);
        $this->assertSame('rate_missing', $statement->blockers[0]['code']);

        try {
            app(MaterialConsumptionStatementService::class)->approve($statement, $context['actor']->id);
            $this->fail('Отчёт без нормы нельзя утвердить');
        } catch (MaterialConsumptionReadinessException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertSame(trans_message('material_consumption.statement_not_ready'), $exception->getMessage());
            $this->assertSame('rate_missing', $exception->reasons[0]['code']);
            $this->assertStringContainsString('Песок', $exception->reasons[0]['message']);
        }
    }

    public function test_mixed_units_require_explicit_coefficient(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);

        try {
            app(MaterialConsumptionFactService::class)->record(
                $context['organization']->id,
                $this->factPayload($work, $context, '0.17', [
                    'unit_id' => $context['ton']->id,
                    'operation_key' => 'mixed-blocked-0001',
                ]),
                $context['actor']->id,
            );
            $this->fail('Смешанные единицы без коэффициента должны блокироваться');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertSame(trans_message('material_consumption.fact_unit_conversion_required'), $exception->getMessage());
        }

        $converted = app(MaterialConsumptionFactService::class)->record(
            $context['organization']->id,
            $this->factPayload($work, $context, '0.17', [
                'unit_id' => $context['ton']->id,
                'conversion' => ['coefficient' => '1000', 'reason' => '1 т = 1000 кг'],
                'operation_key' => 'mixed-ok-0001',
                'deviation_reason' => 'Потери при укладке',
            ]),
            $context['actor']->id,
        );
        $this->assertEquals(170, (float) $converted->converted_quantity);
    }

    public function test_new_rate_version_does_not_change_signed_statement(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);
        $this->consumption($context, $work, '170', ['deviation_reason' => 'Потери при укладке']);
        $service = app(MaterialConsumptionStatementService::class);
        $statement = $service->approve($this->draftStatement($context), $context['actor']->id);
        $file = File::query()->create([
            'organization_id' => $context['organization']->id,
            'fileable_id' => $statement->id,
            'fileable_type' => MaterialConsumptionStatement::class,
            'user_id' => $context['actor']->id,
            'name' => 'm29-signed.pdf',
            'original_name' => 'm29-signed.pdf',
            'path' => 'org-1/m29-signed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'signed_m29',
        ]);
        $signed = $service->markSigned($statement, (int) $file->id, $context['actor']->id);
        $before = $signed->snapshot;
        $beforeChecksum = $service->checksum($service->exportDataset($signed));

        $newRate = app(MaterialConsumptionRateService::class)->create($context['organization']->id, [
            'project_id' => $context['project']->id,
            'material_id' => $context['material']->id,
            'work_type_id' => $context['workType']->id,
            'work_unit_id' => $context['workUnit']->id,
            'material_unit_id' => $context['kg']->id,
            'quantity_per_work_unit' => '3',
            'basis_kind' => MaterialConsumptionRate::BASIS_PROJECT,
            'basis_text' => 'Изменение после периода',
            'effective_from' => '2026-02-01',
            'idempotency_key' => 'rate-cement-v2-0001',
        ], $context['actor']->id);
        app(MaterialConsumptionRateService::class)->approve($newRate, $context['actor']->id);

        $fresh = $signed->fresh();
        $this->assertSame(MaterialConsumptionStatement::STATUS_SIGNED, $fresh->status);
        $this->assertEquals($before, $fresh->snapshot);
        $this->assertSame('2', $fresh->snapshot['section_i'][0]['rate_per_unit']);
        $this->assertSame('160', $fresh->snapshot['section_i'][0]['normative_need']);
        $this->assertSame($beforeChecksum, $service->checksum($service->exportDataset($fresh)));
        $this->assertSame(2, (int) $newRate->fresh()->version_number);
        $this->assertEquals(3, (float) $newRate->fresh()->quantity_per_work_unit);
    }

    public function test_xlsx_and_pdf_share_checksum(): void
    {
        $context = $this->context();
        $this->approvedRate($context, '2');
        $work = $this->confirmedWork($context, 80);
        $this->consumption($context, $work, '170', ['deviation_reason' => 'Потери при укладке']);
        $service = app(MaterialConsumptionStatementService::class);
        $statement = $service->approve($this->draftStatement($context), $context['actor']->id);
        $export = app(MaterialConsumptionStatementExportService::class);
        $dataset = $export->dataset($statement);
        $checksum = $service->checksum($dataset);
        $this->assertFalse($dataset['is_draft']);
        $this->assertSame('160', $checksum['normative']);
        $this->assertSame('170', $checksum['actual']);
        $this->assertSame('10', $checksum['deviation']);

        $spreadsheet = new Spreadsheet;
        $export->fillSpreadsheet($spreadsheet, $dataset);
        $sheet = $spreadsheet->getActiveSheet();
        $found = [];
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $title = (string) $sheet->getCell("A{$row}")->getValue();
            if ($title === 'Итого') {
                $found = [
                    (string) $sheet->getCell("C{$row}")->getValue(),
                    (string) $sheet->getCell("D{$row}")->getValue(),
                    (string) $sheet->getCell("F{$row}")->getValue(),
                ];
            }
        }
        $this->assertSame([$checksum['normative'], $checksum['actual'], $checksum['deviation']], $found);

        $html = view('materials.exports.m29', $dataset)->render();
        $this->assertStringContainsString('160', $html);
        $this->assertStringContainsString('170', $html);
        $this->assertStringContainsString('Потери при укладке', $html);
        $this->assertStringContainsString('ГЭСН 08-02-001-01', $html);
        $this->assertSame($checksum, $service->checksum($dataset));
    }

    public function test_http_create_and_approve_return_russian_payload(): void
    {
        $context = $this->context();
        $this->withoutMiddleware();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
        $rate = $this->actingAs($context['actor'], 'api_admin')->postJson('/api/v1/admin/material-consumption/rates', [
            'project_id' => $context['project']->id,
            'material_id' => $context['material']->id,
            'work_type_id' => $context['workType']->id,
            'work_unit_id' => $context['workUnit']->id,
            'material_unit_id' => $context['kg']->id,
            'quantity_per_work_unit' => '2',
            'basis_kind' => 'gesn',
            'basis_text' => 'ГЭСН 08-02-001-01',
            'effective_from' => '2026-01-01',
            'idempotency_key' => 'http-rate-0001',
        ]);
        $rate->assertCreated();
        $this->assertSame('Норма расхода сохранена', $rate->json('message'));
        $approveRate = $this->actingAs($context['actor'], 'api_admin')
            ->postJson('/api/v1/admin/material-consumption/rates/'.$rate->json('data.id').'/approve');
        $approveRate->assertOk();

        $work = $this->confirmedWork($context, 80);
        $fact = $this->actingAs($context['actor'], 'api_admin')->postJson('/api/v1/admin/material-consumption/facts', [
            'completed_work_id' => $work->id,
            'material_id' => $context['material']->id,
            'kind' => 'consumption',
            'quantity' => '170',
            'unit_id' => $context['kg']->id,
            'occurred_on' => '2026-01-20',
            'deviation_reason' => 'Потери при укладке',
            'operation_key' => 'http-fact-0001',
        ]);
        $fact->assertCreated();

        $create = $this->actingAs($context['actor'], 'api_admin')->postJson('/api/v1/admin/material-consumption/statements', [
            'project_id' => $context['project']->id,
            'contract_id' => $context['contract']->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'foreman_name' => 'Иванов И.И.',
            'idempotency_key' => 'http-m29-0001',
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.status', MaterialConsumptionStatement::STATUS_DRAFT);
        $this->assertSame('Отчёт М-29 сохранён', $create->json('message'));
        $id = (int) $create->json('data.id');
        $approve = $this->actingAs($context['actor'], 'api_admin')
            ->postJson("/api/v1/admin/material-consumption/statements/{$id}/approve");
        $approve->assertOk();
        $approve->assertJsonPath('data.status', MaterialConsumptionStatement::STATUS_APPROVED);
        $this->assertSame('Отчёт М-29 утверждён', $approve->json('message'));
    }

    /**
     * @return array{
     *     organization: Organization,
     *     project: Project,
     *     actor: User,
     *     contract: Contract,
     *     workType: WorkType,
     *     material: Material,
     *     kg: MeasurementUnit,
     *     ton: MeasurementUnit,
     *     workUnit: MeasurementUnit
     * }
     */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Подрядчик М-29',
            'contractor_type' => 'manual',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'T22-M29',
            'date' => '2025-12-01',
            'status' => 'active',
            'total_amount' => 10000,
            'is_fixed_amount' => true,
            'currency' => 'RUB',
        ]);
        $kg = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'кг')->firstOrFail();
        $ton = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'т')->firstOrFail();
        $workUnit = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'м²')->firstOrFail();
        $workType = WorkType::create([
            'organization_id' => $organization->id,
            'name' => 'Устройство стяжки',
            'measurement_unit_id' => $workUnit->id,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Цемент',
            'measurement_unit_id' => $kg->id,
            'is_active' => true,
        ]);

        return compact('organization', 'project', 'actor', 'contract', 'workType', 'material', 'kg', 'ton', 'workUnit');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function approvedRate(array $context, string $quantity, string $from = '2026-01-01'): MaterialConsumptionRate
    {
        $rate = app(MaterialConsumptionRateService::class)->create($context['organization']->id, [
            'project_id' => $context['project']->id,
            'material_id' => $context['material']->id,
            'work_type_id' => $context['workType']->id,
            'work_unit_id' => $context['workUnit']->id,
            'material_unit_id' => $context['kg']->id,
            'quantity_per_work_unit' => $quantity,
            'basis_kind' => MaterialConsumptionRate::BASIS_GESN,
            'basis_text' => 'ГЭСН 08-02-001-01',
            'effective_from' => $from,
            'idempotency_key' => 'rate-'.$quantity.'-'.$from,
        ], $context['actor']->id);

        return app(MaterialConsumptionRateService::class)->approve($rate, $context['actor']->id);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function confirmedWork(array $context, float $quantity): CompletedWork
    {
        return CompletedWork::create([
            'organization_id' => $context['organization']->id,
            'project_id' => $context['project']->id,
            'contract_id' => $context['contract']->id,
            'work_type_id' => $context['workType']->id,
            'user_id' => $context['actor']->id,
            'quantity' => $quantity,
            'completed_quantity' => $quantity,
            'price' => 1,
            'total_amount' => $quantity,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'completion_date' => '2026-01-20',
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     */
    private function consumption(array $context, CompletedWork $work, string $quantity, array $overrides = []): MaterialConsumptionFact
    {
        return app(MaterialConsumptionFactService::class)->record(
            $context['organization']->id,
            $this->factPayload($work, $context, $quantity, $overrides),
            $context['actor']->id,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function factPayload(CompletedWork $work, array $context, string $quantity, array $overrides = []): array
    {
        return array_merge([
            'completed_work_id' => $work->id,
            'material_id' => $context['material']->id,
            'kind' => MaterialConsumptionFact::KIND_CONSUMPTION,
            'quantity' => $quantity,
            'unit_id' => $context['kg']->id,
            'occurred_on' => '2026-01-20',
            'operation_key' => 'cons-'.$work->id.'-'.$quantity,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function draftStatement(array $context): MaterialConsumptionStatement
    {
        return app(MaterialConsumptionStatementService::class)->create($context['organization']->id, [
            'project_id' => $context['project']->id,
            'contract_id' => $context['contract']->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'foreman_name' => 'Иванов И.И.',
            'idempotency_key' => 'm29-'.$context['project']->id.'-jan',
        ], $context['actor']->id);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function warehouseIssue(array $context, string $quantity, string $batch): WarehouseMovement
    {
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'Склад объекта',
            'code' => 'WH-'.$context['project']->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'project_id' => $context['project']->id,
            'is_main' => false,
            'is_active' => true,
        ]);

        return WarehouseMovement::query()->create([
            'organization_id' => $context['organization']->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $context['material']->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => $quantity,
            'project_id' => $context['project']->id,
            'user_id' => $context['actor']->id,
            'document_number' => 'М-11-1',
            'reason' => 'Отпуск на объект',
            'operation_category' => WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
            'metadata' => ['batch_number' => $batch],
            'movement_date' => '2026-01-18 10:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function qualityPassport(array $context): ExecutiveDocument
    {
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context['organization']->id,
            'project_id' => $context['project']->id,
            'created_by' => $context['actor']->id,
            'set_number' => 'ITD-'.$context['project']->id,
            'title' => 'Комплект качества',
            'status' => ExecutiveDocumentStatusEnum::DRAFT->value,
        ]);

        return ExecutiveDocument::query()->create([
            'organization_id' => $context['organization']->id,
            'project_id' => $context['project']->id,
            'document_set_id' => $set->id,
            'created_by' => $context['actor']->id,
            'document_type' => ExecutiveDocumentTypeEnum::QUALITY_PASSPORT->value,
            'title' => 'Паспорт цемента',
            'status' => ExecutiveDocumentStatusEnum::DRAFT->value,
        ]);
    }
}
