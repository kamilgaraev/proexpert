<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis as Analysis;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents as Documents;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO as VisionDTO;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

#[Group('postgres-contract')]
final class ObserverEvidenceProjectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[Test]
    public function semantic_proposals_survive_actual_observers_arbitration_and_publication_without_engineering_authority(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        Queue::fake();
        $storageMigration = require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_10_24_000200_allow_document_semantic_proposal_entities.php');
        self::assertInstanceOf(Migration::class, $storageMigration);
        $guardDefinition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        self::assertIsString($guardDefinition);
        DB::transaction(function () use ($storageMigration, $guardDefinition): void {
            $storageMigration->down();
            self::assertNotSame($guardDefinition, DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)"));
            $storageMigration->up();
            self::assertSame($guardDefinition, DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)"));
        });
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();
        $digest = hash('sha256', $content);
        $version = 'sha256:'.$digest;
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $scope[0], 'project_id' => $scope[1],
            'session_id' => $scope[2], 'user_id' => $actor->id, 'filename' => 'synthetic-source-rules.png', 'mime_type' => 'image/png',
            'storage_path' => 'org-'.$scope[0].'/synthetic-source-rules.png', 'source_version' => $version,
            'checksum_sha256' => $digest, 'status' => 'ready']);
        $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $scope[0], 'project_id' => $scope[1],
            'session_id' => $scope[2], 'document_id' => $document->id, 'unit_type' => 'raster_image', 'unit_index' => 1,
            'source_version' => $version, 'status' => 'completed', 'output_version' => $version, 'output_count' => 1, 'completed_at' => now(),
            'locator' => ['source_kind' => 'image', 'source_version' => $version, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'org-'.$scope[0].'/synthetic-source-rules.png', 'artifact_source_version' => $version,
                'artifact_sha256' => $version, 'artifact_version_id' => 'semantic-proposal-fixture-v1',
                'artifact_bytes' => strlen($content), 'content_type' => 'image/png']]);
        $page = EstimateGenerationDocumentPage::query()->create(['organization_id' => $scope[0], 'project_id' => $scope[1],
            'session_id' => $scope[2], 'document_id' => $document->id, 'processing_unit_id' => $unit->id, 'page_number' => 1,
            'source_version' => $version, 'status' => 'ready', 'language_codes' => [], 'normalized_payload' => [], 'quality_flags' => []]);
        $polygon = [[0.25, 0.25], [0.75, 0.25], [0.75, 0.75], [0.25, 0.75]];
        $sourceValue = static fn (?string $data): array => ['data' => $data, 'evidenceRef' => $data === null ? null : 'source',
            'sourcePolygonOrNativeRef' => $data === null ? null : $polygon];
        $identity = ['manufacturer' => $sourceValue('Синтетический изготовитель'), 'model' => $sourceValue('FIXTURE-1'),
            'variant' => $sourceValue(null), 'configuration' => $sourceValue(null)];
        $requirement = ['schemaVersion' => 1, 'kind' => 'source_requirement', 'parameter' => $sourceValue('Сервисный зазор'),
            'dimension' => 'length', 'comparator' => $sourceValue('gte'), 'threshold' => $sourceValue('0.90'), 'unit' => $sourceValue('m'),
            'appliesTo' => ['subject' => $sourceValue('Стены и проходы в исходном узле'), 'equipmentIdentity' => null],
            'documentEdition' => $sourceValue(null), 'clause' => $sourceValue('Синтетический пункт 1'), 'conditions' => null];
        $collision = [...$requirement, 'threshold' => $sourceValue('0.95')];
        $equipmentRequirement = [...$requirement, 'threshold' => $sourceValue('1.20'),
            'appliesTo' => ['subject' => $sourceValue('Синтетическое оборудование'), 'equipmentIdentity' => $identity]];
        $workScopeRequirement = [...$requirement,
            'appliesTo' => ['subject' => $sourceValue('Синтетическая область работ в источнике'), 'equipmentIdentity' => null]];
        $proposals = [$requirement, $collision, $equipmentRequirement,
            ['schemaVersion' => 1, 'kind' => 'equipment_identity', 'identity' => $identity]];
        $excerpt = 'Синтетический исходный пункт; его применимость к проекту не установлена.';
        $fact = static fn (string $key, string $type, string $contract): array => ['entityKey' => $key, 'factType' => $type,
            'value' => ['type' => 'string', 'data' => $excerpt], 'unit' => null, 'evidenceRef' => 'source',
            'sourcePolygonOrNativeRef' => $polygon, 'confidence' => 0.9, 'contractVersion' => $contract];
        $constructionFacts = [
            [...$fact('wall:source-rule', 'technology_requirements', 'sheet-analysis:v4'), 'semanticProposal' => $proposals[0]],
            [...$fact('wall:source-rule', 'technology_requirements', 'sheet-analysis:v4'), 'semanticProposal' => $proposals[1]],
            [...$fact('equipment:source-rule', 'equipment_specification', 'sheet-analysis:v4'), 'semanticProposal' => $proposals[2]],
            [...$fact('equipment:source-identity', 'equipment_specification', 'sheet-analysis:v4'), 'semanticProposal' => $proposals[3]],
            [...$fact('quantity:source-work-scope', 'technology_candidate', 'sheet-analysis:v4'), 'semanticProposal' => $workScopeRequirement],
            [...$fact('wall:null-proposal', 'industrial_specification', 'sheet-analysis:v4'), 'semanticProposal' => null],
        ];
        $invalid = [
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'data' => 0.90]],
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'data' => '9e-1']],
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'data' => '0.90001']],
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'data' => '1000000000000.1']],
            [...$requirement, 'unit' => [...$requirement['unit'], 'data' => 'm2']],
            [...$requirement, 'comparator' => [...$requirement['comparator'], 'data' => 'gt']],
            [...$requirement, 'verified' => true],
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'evidenceRef' => 'foreign']],
            [...$requirement, 'threshold' => [...$requirement['threshold'], 'evidenceRef' => 'stale']],
        ];
        foreach ($invalid as $index => $proposal) {
            $constructionFacts[] = [...$fact('wall:invalid-'.$index, 'technology_requirements', 'sheet-analysis:v4'), 'semanticProposal' => $proposal];
        }
        $calls = [];
        $provider = $this->createMock(VisionProvider::class);
        $provider->method('analyze')->willReturnCallback(function (VisionDTO\VisionDocumentInput $input) use (&$calls, $constructionFacts, $fact): VisionDTO\VisionAnalysisData {
            $arbitration = $input->auxiliaryMetadata['arbitration'] ?? null;
            $profile = $arbitration === null ? $input->auxiliaryMetadata['observer']['profile'] : 'arbiter';
            $calls[] = $profile;
            ($input->onPhysicalAttemptReserved)((string) Str::uuid());
            $evidence = ['key' => 'source', 'locator' => ['page_id' => $input->pageId, 'page_number' => $input->pageNumber,
                'processing_unit_id' => $input->processingUnitId, 'source_version' => $input->sourceVersion,
                'coordinate_space' => 'normalized_derivative_v1', 'explicit' => true]];
            if ($arbitration !== null) {
                $decisions = array_map(static fn (array $claim): array => ['claim_id' => $claim['id'], 'status' => 'accepted',
                    'supporting_claim_ids' => [$claim['id']], 'evidence_refs' => [$claim['evidence_ref']],
                    'reason_code' => 'controlled_source_excerpt'], $arbitration['claims']);
                return new VisionDTO\VisionAnalysisData('detail', [VisionDTO\VisionEvidenceData::fromArray($evidence)], [], [], ['scale_missing'],
                    'recorded', 'openai/gpt-6-luna', 'openai/gpt-6-luna', 'semantic-fixture:v1', 'measured', 1, 1, rawObserverFacts: $decisions);
            }
            $contract = $profile === 'construction' ? 'sheet-analysis:v4' : 'sheet-analysis:v3';
            return VisionDTO\VisionAnalysisData::fromProviderArray(['schema_version' => 3, 'sheet_type' => 'detail', 'evidence' => [$evidence,
                ['key' => 'foreign', 'locator' => [...$evidence['locator'], 'page_id' => $input->pageId + 1000]],
                ['key' => 'stale', 'locator' => [...$evidence['locator'], 'source_version' => 'sha256:'.str_repeat('f', 64)]]],
                'elements' => [], 'scale_candidates' => [], 'warnings' => ['scale_missing'], 'visual_attributes' => [],
                'project_sheet_analysis' => ['contractVersion' => $contract, 'role' => 'unknown', 'facts' => match ($profile) {
                    'construction' => $constructionFacts,
                    'literal' => [$fact('wall:legacy-source', 'industrial_specification', $contract)],
                    default => [],
                }]], 'recorded', 'openai/gpt-6-luna', 'openai/gpt-6-luna', 'semantic-fixture:v1', 'measured', 1, 1, 64);
        });
        $transform = (new ProjectiveTransformFactory)->between([[0.2, 0.3], [0.8, 0.3], [0.8, 0.9], [0.2, 0.9]],
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]]);
        $source = new VisionDTO\VisionDocumentInput(...[...$scope, (int) $document->id, (int) $page->id, 1, (int) $unit->id,
            $version, 'sha256:'.$digest, 'image/png', $content, 'high',
            new AiOperationContext((string) Str::uuid(), (string) Str::uuid(), ...[...$scope, 'understand_documents', 'vision', 1,
                (int) $document->id, (int) $page->id, (int) $unit->id]), $transform]);
        $runs = new Analysis\EloquentAiRoleRunRepository(DB::connection(), 60);
        $observers = new Analysis\Observers\RunIndependentObservers($runs, $provider, new Analysis\Observers\ObserverInputBuilder, 'openai/gpt-6-luna');
        $results = $observers->run($source, Analysis\Observers\ObserverProfile::cases());
        $arbiter = new Analysis\Arbitration\RunDocumentArbitration($runs, $provider, new Analysis\Arbitration\ArbitrationInputBuilder, 'openai/gpt-6-luna');
        $arbitrated = $arbiter->run($source, $results);
        $publication = (new Documents\DocumentUnitPublicationFactory)->fromAnalysis($source, $results, $arbitrated);
        self::assertNotNull($publication);
        self::assertCount(7, $publication->claims);
        self::assertCount(7, $publication->decisions);
        $models = new EloquentProjectModelRepository(app('db'));
        $publisher = new Documents\AtomicDocumentUnitPublicationWriter(DB::connection(), new ProjectModelEvidenceWriter($models, new EloquentEvidenceRepository(DB::connection())));
        $publish = fn () => $publisher->write($publication, ...[...$scope, (int) $document->id, 1, $version]);
        $publisher->transaction($scope[0], $scope[2], $publish);
        $snapshot = (new EloquentProjectModelRepository(app('db')))->snapshot(...$scope);
        self::assertCount(7, $snapshot->facts);
        $stored = array_values(array_filter(array_map(static fn ($entity): ?array =>
            $entity->attributes['properties']['document_semantic_proposal'] ?? null, $snapshot->entities)));
        self::assertCount(5, $stored);
        $hashes = [];
        foreach ($stored as $descriptor) {
            self::assertSame(1, $descriptor['schema_version']);
            self::assertSame('proposed', $descriptor['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $descriptor['proposal_hash']);
            $hashes[] = $descriptor['proposal_hash'];
            self::assertNotEmpty($descriptor['field_locators']);
            $pins = $descriptor['source_pins'];
            $expectedPins = ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2],
                'document_id' => (int) $document->id, 'page_number' => 1, 'processing_unit_id' => (int) $unit->id, 'source_version' => $version];
            ksort($pins);
            ksort($expectedPins);
            self::assertSame($expectedPins, $pins);
            foreach ($descriptor['field_locators'] as $locator) {
                self::assertSame([...$scope, (int) $document->id, (int) $page->id, (int) $unit->id, $version],
                    [$locator['organization_id'], $locator['project_id'], $locator['session_id'], $locator['document_id'],
                        $locator['page_id'], $locator['processing_unit_id'], $locator['source_version']]);
                self::assertSame('polygon', $locator['fact_source']['kind']);
                self::assertSame('normalized_source_v1', $locator['fact_source']['coordinate_space']);
                foreach ([[0.35, 0.45], [0.65, 0.45], [0.65, 0.75], [0.35, 0.75]] as $point => $expectedPoint) {
                    self::assertEqualsWithDelta($expectedPoint[0], $locator['fact_source']['value'][$point][0], 0.00000001);
                    self::assertEqualsWithDelta($expectedPoint[1], $locator['fact_source']['value'][$point][1], 0.00000001);
                }
            }
            if ($descriptor['payload']['kind'] === 'source_requirement') {
                self::assertArrayHasKey('threshold', $descriptor['field_locators']);
                self::assertSame('length', $descriptor['payload']['dimension']);
                self::assertSame('gte', $descriptor['payload']['comparator']['data']);
                self::assertSame('m', $descriptor['payload']['unit']['data']);
                self::assertSame(['data' => null, 'evidenceRef' => null, 'sourcePolygonOrNativeRef' => null], $descriptor['payload']['documentEdition']);
                self::assertNull($descriptor['payload']['conditions']);
            }
            self::assertArrayNotHasKey('observed', $descriptor['payload']);
            self::assertArrayNotHasKey('verified', $descriptor);
            self::assertArrayNotHasKey('target_entity_id', $descriptor);
        }
        self::assertCount(5, array_unique($hashes));
        $workScopes = array_values(array_filter($snapshot->entities, static fn ($entity): bool =>
            ($entity->attributes['semantic_type'] ?? null) === 'work_scope'));
        self::assertCount(1, $workScopes);
        self::assertSame('quantity', $workScopes[0]->type);
        self::assertSame('quantity:source-work-scope', $workScopes[0]->attributes['identity']['source_entity_key']);
        self::assertArrayNotHasKey('value', $workScopes[0]->attributes);
        self::assertArrayNotHasKey('unit', $workScopes[0]->attributes);
        $equipment = array_values(array_filter($stored, static fn (array $item): bool =>
            ($item['payload']['kind'] ?? null) === 'source_requirement' && $item['payload']['appliesTo']['equipmentIdentity'] !== null));
        self::assertCount(1, $equipment);
        self::assertSame('1.20', $equipment[0]['payload']['threshold']['data']);
        self::assertSame('FIXTURE-1', $equipment[0]['payload']['appliesTo']['equipmentIdentity']['model']['data']);
        $general = array_values(array_filter($stored, static fn (array $item): bool =>
            ($item['payload']['kind'] ?? null) === 'source_requirement' && $item['payload']['appliesTo']['equipmentIdentity'] === null));
        self::assertCount(3, $general);
        $thresholds = array_column(array_column(array_column($general, 'payload'), 'threshold'), 'data');
        sort($thresholds);
        self::assertSame(['0.90', '0.90', '0.95'], $thresholds);
        foreach ($snapshot->facts as $published) {
            self::assertSame($excerpt, $published->value);
            self::assertNull($published->unit);
            self::assertSame([], $published->nativeNumericProofs);
            self::assertSame([], $published->semanticAttributes);
        }
        $publishedRows = DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all();
        $sourceRow = array_values(array_filter($publishedRows, static fn ($row): bool =>
            $row->entity_kind === 'wall' && isset(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['properties']['document_semantic_proposal'])))[0];
        $sourcePayload = json_decode($sourceRow->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('wall', $sourcePayload['semantic_type']);
        self::assertSame('wall:source-rule', $sourcePayload['identity']['source_entity_key']);
        self::assertNull($sourcePayload['properties']['document_semantic_proposal']['payload']['appliesTo']['equipmentIdentity']);
        $entityInsert = static function (array $payload, string $label) use ($sourceRow): array {
            $row = (array) $sourceRow;
            unset($row['id']);
            $row['stable_key'] = 'entity:'.hash('sha256', $label);
            $row['entity_kind'] = $payload['kind'];
            $payload['key'] = $row['stable_key'];
            $row['payload'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

            return $row;
        };
        $assertStorageRejected = static function (array $row, string $invariant): void {
            try {
                DB::transaction(static function () use ($row): void {
                    DB::table('estimate_generation_project_model_entities')->insert($row);
                    self::fail('SQL accepted an invalid source Entity payload.');
                });
            } catch (QueryException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
                self::assertStringContainsString($invariant, $exception->getMessage());
            }
        };
        $descriptorPath = 'properties.document_semantic_proposal.';
        $invalidStorage = [
            'arbitrary_property' => ['properties.arbitrary' => true],
            'descriptor_extra' => [$descriptorPath.'extra' => true],
            'verified' => [$descriptorPath.'verified' => true],
            'trust' => [$descriptorPath.'trust' => 'trusted'],
            'schema_future' => [$descriptorPath.'schema_version' => 2],
            'schema_null' => [$descriptorPath.'schema_version' => null],
            'status_verified' => [$descriptorPath.'status' => 'verified'],
            'status_null' => [$descriptorPath.'status' => null],
            'kind_future' => [$descriptorPath.'payload.kind' => 'future_requirement'],
            'payload_extra' => [$descriptorPath.'payload.target_entity_id' => 'wall:current-target'],
            'value_extra' => [$descriptorPath.'payload.threshold.verified' => true],
            'float_threshold' => [$descriptorPath.'payload.threshold.data' => 0.9],
            'exponent_threshold' => [$descriptorPath.'payload.threshold.data' => '9e-1'],
            'scale_threshold' => [$descriptorPath.'payload.threshold.data' => '0.90001'],
            'overflow_threshold' => [$descriptorPath.'payload.threshold.data' => '1000000000000.1'],
            'area_unit' => [$descriptorPath.'payload.unit.data' => 'm2'],
            'comparator' => [$descriptorPath.'payload.comparator.data' => 'gt'],
            'dimension' => [$descriptorPath.'payload.dimension' => 'area'],
            'pin_string_id' => [$descriptorPath.'source_pins.processing_unit_id' => (string) $unit->id],
            'pin_organization' => [$descriptorPath.'source_pins.organization_id' => $scope[0] + 1000],
            'pin_project' => [$descriptorPath.'source_pins.project_id' => $scope[1] + 1000],
            'pin_session' => [$descriptorPath.'source_pins.session_id' => $scope[2] + 1000],
            'pin_document' => [$descriptorPath.'source_pins.document_id' => (int) $document->id + 1000],
            'pin_page' => [$descriptorPath.'source_pins.page_number' => 2],
            'pin_unit' => [$descriptorPath.'source_pins.processing_unit_id' => (int) $unit->id + 1000],
            'pin_version' => [$descriptorPath.'source_pins.source_version' => 'different-captured-version'],
            'locator_organization' => [$descriptorPath.'field_locators.threshold.organization_id' => $scope[0] + 1000],
            'locator_document' => [$descriptorPath.'field_locators.threshold.document_id' => (int) $document->id + 1000],
            'locator_page' => [$descriptorPath.'field_locators.threshold.page_id' => (int) $page->id + 1000],
            'locator_unit' => [$descriptorPath.'field_locators.threshold.processing_unit_id' => (int) $unit->id + 1000],
            'locator_version' => [$descriptorPath.'field_locators.threshold.source_version' => 'different-captured-version'],
            'locator_extra' => [$descriptorPath.'field_locators.threshold.trust' => 'verified'],
            'unexpected_locator' => [$descriptorPath.'field_locators.observed_input' =>
                $sourcePayload['properties']['document_semantic_proposal']['field_locators']['threshold']],
            'geometry_in_source' => ['start' => [0, 0], 'end' => [1, 1]],
            'numeric_in_source' => ['value' => '3', 'unit' => 'pcs'],
        ];
        foreach ($invalidStorage as $label => $changes) {
            $invalidPayload = $sourcePayload;
            foreach ($changes as $path => $value) {
                data_set($invalidPayload, $path, $value);
            }
            $assertStorageRejected($entityInsert($invalidPayload, $label),
                'estimate_generation.project_model_document_semantic_proposal_invalid');
        }
        $missingLocator = $sourcePayload;
        unset($missingLocator['properties']['document_semantic_proposal']['field_locators']['threshold']);
        $assertStorageRejected($entityInsert($missingLocator, 'missing_threshold_locator'),
            'estimate_generation.project_model_document_semantic_proposal_invalid');
        $legacyPayloads = [
            'legacy_fact_wall' => ['kind' => 'wall', 'semantic_type' => 'wall', 'identity' => ['source_entity_key' => 'wall:legacy-storage']],
            'legacy_geometry_wall' => ['kind' => 'wall', 'start' => [0, 0], 'end' => [1, 1], 'identity' => ['source_entity_key' => 'wall:legacy-geometry']],
            'legacy_quantity' => ['kind' => 'quantity', 'value' => '3', 'unit' => 'pcs'],
        ];
        $equipmentRow = array_values(array_filter($publishedRows, static fn ($row): bool => $row->entity_kind === 'equipment'))[0];
        $legacyEquipment = json_decode($equipmentRow->payload, true, flags: JSON_THROW_ON_ERROR);
        $invalidEquipment = $legacyEquipment;
        $invalidEquipment['properties']['arbitrary'] = true;
        $assertStorageRejected($entityInsert($invalidEquipment, 'equipment_arbitrary_property'),
            'estimate_generation.project_model_document_semantic_proposal_invalid');
        unset($legacyEquipment['properties']['document_semantic_proposal']);
        $legacyPayloads['legacy_equipment'] = $legacyEquipment;
        foreach ($legacyPayloads as $label => $payload) {
            $row = $entityInsert($payload, $label);
            DB::beginTransaction();
            try {
                DB::table('estimate_generation_project_model_entities')->insert($row);
                $persisted = DB::table('estimate_generation_project_model_entities')->where('stable_key', $row['stable_key'])->value('payload');
                self::assertEquals(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR), json_decode($persisted, true, flags: JSON_THROW_ON_ERROR));
            } finally {
                DB::rollBack();
            }
        }
        $assertStorageRejected($entityInsert(['kind' => 'wall', 'start' => [0], 'end' => [1, 1]], 'legacy_invalid_geometry'),
            'estimate_generation.project_model_entity_payload_invalid');
        $assertStorageRejected($entityInsert(['kind' => 'wall', 'semantic_type' => 'wall', 'arbitrary' => true], 'legacy_invalid_fact_wall'),
            'estimate_generation.project_model_entity_payload_invalid');
        self::assertEquals($publishedRows, DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        $assertHistoryRetained = static function () use ($storageMigration, $guardDefinition, $publishedRows, $scope): void {
            try {
                DB::transaction(static fn () => $storageMigration->down());
                self::fail('Storage rollback accepted retained proposal history.');
            } catch (\RuntimeException $exception) {
                self::assertSame('document_semantic_proposal_entities_require_preserved_history', $exception->getMessage());
            }
            self::assertSame($guardDefinition, DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)"));
            self::assertEquals($publishedRows, DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        };
        $assertHistoryRetained();
        $publishedAssertions = DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->orderBy('id')->get()->all();
        $publishedEvidence = DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->orderBy('id')->get()->all();
        self::assertSame(['literal', 'construction', 'risk', 'arbiter'], $calls);
        $replayed = $observers->run($source, Analysis\Observers\ObserverProfile::cases());
        self::assertEquals($results, $replayed);
        $replayedArbitration = $arbiter->run($source, $replayed);
        self::assertEquals($arbitrated, $replayedArbitration);
        $replayedPublication = (new Documents\DocumentUnitPublicationFactory)->fromAnalysis($source, $replayed, $replayedArbitration);
        self::assertNotNull($replayedPublication);
        $publisher->transaction($scope[0], $scope[2], fn () => $publisher->write($replayedPublication,
            ...[...$scope, (int) $document->id, 1, $version]));
        self::assertEquals($snapshot, (new EloquentProjectModelRepository(app('db')))->snapshot(...$scope));
        self::assertEquals($publishedRows, DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        self::assertEquals($publishedAssertions, DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        self::assertEquals($publishedEvidence, DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        self::assertCount(4, $calls);
        self::assertSame(0, DB::table('estimate_generation_quantity_takeoffs')->where('session_id', $scope[2])->count());
        self::assertSame(0, DB::table('estimate_generation_learning_examples')->where('generation_session_id', $scope[2])->count());
        self::assertSame(4, DB::table('estimate_generation_ai_role_runs')->where('session_id', $scope[2])->where('status', 'completed')->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $scope[2])->count());
        $foreign = Organization::factory()->create();
        self::assertSame([], $models->snapshot((int) $foreign->id, $scope[1], $scope[2])->facts);
        $evidence = new EloquentEvidenceRepository(DB::connection());
        $evidenceIds = DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->orderBy('id')->pluck('id')->all();
        self::assertCount(7, $evidenceIds);
        self::assertNull($evidence->node((int) $foreign->id, $scope[1], $scope[2], (int) $evidenceIds[0]));
        self::assertSame(0, $evidence->invalidate((int) $foreign->id, $scope[1], $scope[2], $evidenceIds, 'foreign_scope_control'));
        self::assertCount(7, $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        $assertionRows = DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->orderBy('id')->get()->all();
        $entityRows = DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all();
        app(Documents\IgnoreEstimateGenerationDocument::class)->handle($session->fresh(), $document->fresh(),
            (int) $session->fresh()->state_version, 'Controlled withdrawal of the synthetic source.');
        self::assertSame('ignored', $document->fresh()->status);
        self::assertSame([], $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']->facts);
        self::assertEquals($assertionRows, DB::table('estimate_generation_project_model_assertions')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        self::assertEquals($entityRows, DB::table('estimate_generation_project_model_entities')->where('session_id', $scope[2])->orderBy('id')->get()->all());
        $assertHistoryRetained();
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $scope[2])->count());
        self::assertCount(4, $calls);
        Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        $recordedRequests = Http::recorded()->map(static function (array $record): string {
            $request = $record[0];
            $url = parse_url($request->url());

            return $request->method().' '.($url['scheme'] ?? 'unknown').'://'.($url['host'] ?? 'unknown').($url['path'] ?? '/');
        })->all();
        self::assertSame([], $recordedRequests, 'Intercepted HTTP requests: '.json_encode($recordedRequests, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    #[Test]
    public function photo_room_names_remain_visible_candidates_with_evidence_and_without_dimensions(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id,
                'user_id' => User::factory()->create()->id, 'status' => 'draft',
                'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('b', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
            $models = new EloquentProjectModelRepository(app('db'));
            $writer = new ProjectModelEvidenceWriter($models, new EloquentEvidenceRepository(DB::connection()));
            $claims = [];
            foreach (['first-floor', 'second-floor'] as $index => $floor) {
                $claims[] = new ObservationClaim('literal:room:'.($index + 1), 'observer_literal', 'room.kitchen', 'room_name',
                    ['type' => 'string', 'data' => 'Кухня'], null, 'literal:room:'.($index + 1), true,
                    ...[...$scope, $sourceVersion, ['page' => 1, 'unit_type' => 'raster_image', 'unit_index' => 1,
                        'source_version' => $sourceVersion, 'floor_id' => $floor,
                        'region_key' => 'region:'.str_repeat((string) ($index + 1), 64), 'bbox' => [10, 20, 30, 40]], 0.9]);
            }
            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeArbitration($claims, array_map(static fn (ObservationClaim $claim): ArbitrationDecision => new ArbitrationDecision(
                $claim->id, 'accepted', [$claim->id], [$claim->evidenceRef], 'fixture_label_observed', null,
            ), $claims), 173, 1);

            $snapshot = $models->snapshot(...$scope);
            self::assertCount(2, $snapshot->entities);
            self::assertCount(2, $snapshot->facts);
            self::assertSame(['room_name'], array_values(array_unique(array_map(static fn ($fact): string => $fact->type, $snapshot->facts))));
            self::assertCount(2, array_unique(array_map(static fn ($fact): string => $fact->entityId, $snapshot->facts)));
            foreach ($snapshot->facts as $fact) {
                self::assertSame('room_name', $fact->type);
                self::assertSame('Кухня', $fact->value);
                self::assertSame('candidate', $fact->status);
                self::assertNull($fact->unit);
                self::assertCount(1, $fact->evidenceIds);
            }
            foreach ($snapshot->entities as $entity) {
                self::assertSame('room', $entity->type);
                self::assertSame('Кухня', $entity->attributes['name']);
                self::assertTrue($entity->attributes['properties']['identity_only']);
                foreach (['area', 'length', 'width', 'height', 'polygon'] as $parameter) {
                    self::assertArrayNotHasKey($parameter, $entity->attributes);
                }
            }
            $evidence = DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->get();
            self::assertCount(2, $evidence);
            foreach ($evidence as $row) {
                $value = json_decode($row->value, true, flags: JSON_THROW_ON_ERROR);
                $locator = json_decode($row->locator, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(['fact_key' => 'room_name', 'fact_value' => hash('sha256', 'Кухня')], $value);
                self::assertSame(1, $locator['page']);
                self::assertSame([10, 20, 30, 40], $locator['bbox']);
                self::assertSame($sourceVersion, $row->source_version);
            }
            self::assertSame(0, DB::table('estimate_generation_quantity_takeoffs')->where('session_id', $scope[2])->count());
            foreach ([['fact_key' => 'room_name', 'fact_value' => 'material:'.str_repeat('a', 64)],
                ['fact_key' => 'room_name', 'fact_value' => str_repeat('a', 64), 'unit' => 'm2']] as $invalidValue) {
                try {
                    DB::transaction(function () use ($evidence, $invalidValue): void {
                        $row = (array) $evidence[0];
                        unset($row['id']);
                        $row['value'] = json_encode($invalidValue, JSON_THROW_ON_ERROR);
                        DB::table('estimate_generation_evidence')->insert($row);
                    });
                    self::fail('SQL accepted a monetary/material or numeric room-name proof.');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('room_identity_evidence_invalid', $exception->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function production_shaped_completed_observer_batch_is_atomic_and_keeps_only_canonical_project_model_candidates(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 20,
                'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('5', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
            $locator = [
                'page' => 1,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'explicit' => true,
            ];
            $claims = [
                new ObservationClaim(
                    'construction:material', 'observer_construction', 'material.1', 'material',
                    ['type' => 'string', 'data' => 'Обезличенный материал'], null, 'construction:material', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
                new ObservationClaim(
                    'literal:1', 'observer_literal', 'building.roof', 'roof_geometry',
                    ['type' => 'enum', 'data' => 'gable'], null, 'literal:roof', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
                new ObservationClaim(
                    'literal:2', 'observer_literal', 'finish.1', 'finish_zone',
                    ['type' => 'string', 'data' => 'Обезличенная зона отделки'], null, 'literal:finish-zone', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
            ];
            $writer = new ProjectModelEvidenceWriter(
                new EloquentProjectModelRepository(app('db')),
                new EloquentEvidenceRepository(DB::connection()),
            );

            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeIndependentObservations($claims, 173, 1);

            self::assertSame(1, DB::table('estimate_generation_project_model_entities')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_evidence')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->count());
            self::assertSame('object', DB::selectOne(
                "SELECT jsonb_typeof(payload->'properties') AS type FROM estimate_generation_project_model_entities WHERE entity_kind = 'material'",
            )->type);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function candidate_replay_is_idempotent_and_distinct_observations_do_not_collide(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 20,
            'input_payload' => [],
        ]);
        $sourceVersion = 'sha256:'.str_repeat('6', 64);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
        $locator = ['page' => 1, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $sourceVersion, 'explicit' => true];
        $writer = new ProjectModelEvidenceWriter(
            new EloquentProjectModelRepository(app('db')),
            new EloquentEvidenceRepository(DB::connection()),
        );
        $first = new ObservationClaim(
            'construction:material:first', 'observer_construction', 'material.same', 'material',
            ['type' => 'string', 'data' => 'Материал А'], null, 'construction:material:first', true,
            $scope[0], $scope[1], $scope[2], $scope[3], $locator,
        );
        $collision = new ObservationClaim(
            'construction:material:second', 'observer_construction', 'material.same', 'material',
            ['type' => 'string', 'data' => 'Материал Б'], null, 'construction:material:second', true,
            $scope[0], $scope[1], $scope[2], $scope[3], $locator,
        );

        $writer->writeIndependentObservations([$first], 173, 1);
        $writer->writeIndependentObservations([$first], 173, 1);
        $writer->writeIndependentObservations([$collision], 173, 1);

        self::assertSame(2, DB::table('estimate_generation_project_model_entities')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->where('source_version', $sourceVersion)->count());
        self::assertSame(2, DB::table('estimate_generation_project_model_assertions')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->where('source_version', $sourceVersion)->count());
        self::assertSame(2, DB::table('estimate_generation_evidence')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->count());
    }

    #[Test]
    public function visual_confirmation_candidate_is_current_while_an_ordinary_candidate_remains_non_current(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 20,
                'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('7', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
            $locator = ['page' => 5, 'unit_type' => 'pdf_page', 'unit_index' => 5, 'source_version' => $sourceVersion, 'explicit' => true];
            $models = new EloquentProjectModelRepository(app('db'));
            $writer = new ProjectModelEvidenceWriter($models, new EloquentEvidenceRepository(DB::connection()));
            $writer->writeIndependentObservations([new ObservationClaim(
                'construction:toilet', 'observer_construction', 'room.bathroom.toilet', 'sanitary_fixture',
                ['type' => 'string', 'data' => 'Унитаз'], null, 'construction:toilet', true,
                $scope[0], $scope[1], $scope[2], $scope[3], $locator,
            )], 173, 5);
            $writer->writeIndependentObservations([new ObservationClaim(
                'construction:material', 'observer_construction', 'material.wall', 'material',
                ['type' => 'string', 'data' => 'Не подтверждённый материал'], null, 'construction:material', true,
                $scope[0], $scope[1], $scope[2], $scope[3], $locator,
            )], 173, 5);

            $facts = $models->currentFacts($scope[0], $scope[1], $scope[2]);

            self::assertCount(1, $facts);
            self::assertSame('sanitary_fixture', $facts[0]->type);
            self::assertSame('candidate', $facts[0]->status);
            self::assertSame(1, DB::table('estimate_generation_project_model_fact_projections')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('is_current', true)
                ->count());
        } finally {
            DB::rollBack();
        }
    }
}
