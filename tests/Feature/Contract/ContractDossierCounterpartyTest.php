<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Contract\ContractDossierDocumentCreator;
use App\Services\Contract\ContractSideMutationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ContractDossierCounterpartyTest extends TestCase
{
    public function test_creation_uses_the_saved_external_party_and_replay_preserves_the_document(): void
    {
        $database = new Capsule;
        $database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $database->setAsGlobal();
        $database->setEventDispatcher(new Dispatcher(new Container));
        $database->bootEloquent();
        Model::clearBootedModels();
        $database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('number');
            $table->string('contract_side_type');
            $table->string('dossier_creation_key');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $database->schema()->create('contract_parties', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('side');
            $table->string('name');
        });
        $database->schema()->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('counterparty_name')->nullable();
            $table->softDeletes();
        });
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
        $names = [
            'customer_to_general_contractor' => 'Заказчик объекта',
            'general_contractor_to_contractor' => 'Подрядчик объекта',
            'contractor_to_subcontractor' => 'Субподрядчик объекта',
            'general_contractor_to_supplier' => 'Поставщик генподрядчика',
            'contractor_to_supplier' => 'Поставщик подрядчика',
            'subcontractor_to_supplier' => 'Поставщик субподрядчика',
        ];
        try {
            foreach ($names as $type => $name) {
                $contracts = Mockery::mock(ContractSideMutationService::class);
                $contracts->shouldReceive('create')->once()->andReturnUsing(static function () use ($database, $type, $name): Contract {
                    $contract = Contract::query()->create([
                        'organization_id' => 7, 'number' => 'Д-'.$database->table('contracts')->count(),
                        'contract_side_type' => $type, 'dossier_creation_key' => $type,
                    ]);
                    $externalSide = $type === 'customer_to_general_contractor' ? 'first' : 'second';
                    foreach (['first', 'second'] as $side) {
                        $database->table('contract_parties')->insert([
                            'contract_id' => $contract->id, 'side' => $side,
                            'name' => $side === $externalSide ? $name : 'Наша организация',
                        ]);
                    }

                    return $contract;
                });
                $documents = Mockery::mock(ContractDossierDocumentCreator::class);
                $documents->shouldReceive('create')->once()->andReturnUsing(static function (int $organizationId, ?int $userId, array $data) use ($database): LegalArchiveDocument {
                    $id = $database->table('legal_archive_documents')->insertGetId([
                        'organization_id' => $organizationId, 'counterparty_name' => $data['counterparty_name'] ?? null,
                    ]);

                    return LegalArchiveDocument::query()->findOrFail($id);
                });
                $service = new ContractDossierCreationService(
                    $database->getConnection(), $contracts,
                    new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $database->getConnection()),
                    $documents,
                );
                $input = new ContractDossierCreationInput(
                    new ContractDTO(
                        project_id: null, contractor_id: null, parent_contract_id: null,
                        number: 'Д-01', date: '2026-09-03', subject: 'Тестовый договор',
                        work_type_category: null, payment_terms: null, base_amount: 120000.0, total_amount: 120000.0,
                        gp_percentage: null, gp_calculation_type: null, gp_coefficient: null,
                        warranty_retention_calculation_type: null, warranty_retention_percentage: null,
                        warranty_retention_coefficient: null, subcontract_amount: null,
                        planned_advance_amount: null, actual_advance_amount: null,
                        status: ContractStatusEnum::DRAFT, start_date: null, end_date: null, notes: null,
                        contract_side_type: ContractSideTypeEnum::from($type),
                    ),
                    $type, 'Тестовый договор',
                );
                $created = $service->create(7, $actor, $input);
                self::assertSame($name, $created->document->counterparty_name);
                $database->table('contract_parties')->where('contract_id', $created->contract->id)->update(['name' => 'Изменённое наименование']);
                $replayed = $service->create(7, $actor, $input);
                self::assertTrue($replayed->replayed);
                self::assertSame($created->document->id, $replayed->document->id);
                self::assertSame($name, $replayed->document->counterparty_name);
            }
        } finally {
            Mockery::close();
        }
    }
}
