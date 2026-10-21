<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentAdmissionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class NativeSpreadsheetUploadHttpPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private int $storageWrites = 0;

    public function test_authenticated_multipart_upload_publishes_current_native_count_through_dispatched_jobs(): void
    {
        [$rights, $project, $session] = $this->fixture();
        $file = $this->workbook();
        $digest = hash_file('sha256', $file->getPathname());
        $headers = $this->headers($rights->member);
        $requestId = (string) Str::uuid();
        $response = $this->post($this->url($project, $session), [
            'state_version' => 0, 'request_id' => $requestId, 'files' => [$file],
        ], $headers);
        self::assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('success', true);
        $document = $session->documents()->sole();
        self::assertSame('queued', $document->status);
        self::assertSame('sha256:'.$digest, $document->source_version);
        self::assertSame($digest, $document->checksum_sha256);
        self::assertStringStartsWith('org-'.$rights->organization->id.'/', $document->storage_path);
        self::assertSame($digest, hash('sha256', Storage::disk('s3')->get($document->storage_path)));
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame([], $this->snapshot($session)->facts);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertNotPushed(ProcessEstimateGenerationUnitJob::class);

        $manifestJob = Queue::pushed(ProcessEstimateGenerationDocumentJob::class)->sole();
        $this->app->call([$manifestJob, 'handle']);
        Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 1);
        $unitJob = Queue::pushed(ProcessEstimateGenerationUnitJob::class)->sole();
        $this->app->call([$unitJob, 'handle']);

        $snapshot = $this->snapshot($session);
        $counts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_count'));
        self::assertCount(1, $counts);
        $count = $counts[0];
        self::assertSame('3', $count->value);
        self::assertSame('шт', $count->unit);
        self::assertSame('document', $count->origin);
        self::assertSame('confirmed', $count->status);
        self::assertSame([(int) $session->organization_id, (int) $project->id, (int) $session->id, $document->source_version],
            [$count->organizationId, $count->projectId, $count->sessionId, $count->sourceVersion]);
        $evidence = array_values(array_filter($snapshot->evidence, static fn ($item): bool => in_array($item->id, $count->evidenceIds, true)));
        self::assertCount(1, $evidence);
        self::assertSame('document:'.$document->id, $evidence[0]->sourceArtifactId);
        self::assertSame('xlsx:sheet:Оборудование!C3', $evidence[0]->nativeReference);
        self::assertTrue((new NativeNumericFactProof)->matches($count, $evidence[0]));
        $entities = array_values(array_filter($snapshot->entities, static fn ($entity): bool => $entity->id === $count->entityId));
        self::assertCount(1, $entities);
        self::assertSame('equipment:boiler', $entities[0]->attributes['properties']['source_entity_key']);
        $projection = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $session->organization_id,
            (int) $project->id, (int) $session->id);
        $quantity = (new WorkItemQuantityMapper)->mapForEntity('equipment.install', $projection['quantities'], $count->entityId);
        self::assertNotNull($quantity);
        self::assertSame('3', $quantity->amount);
        self::assertSame('count', $quantity->unit);
        self::assertSame($count->entityId, $quantity->formulaInputs['entity_id']);
        self::assertCount(1, $quantity->formulaInputs['operands']);
        $operand = $quantity->formulaInputs['operands'][0];
        self::assertSame($count->id, $operand['fact_id']);
        self::assertSame($count->evidenceIds, $operand['evidence_ids']);
        self::assertSame($document->source_version, $operand['source_version']);
        self::assertTrue($operand['current']);
        $detail = $this->getJson($this->url($project, $session).'/'.$document->id, $headers)
            ->assertOk()->assertJsonPath('data.document_source_version', $document->source_version);
        $presented = array_values(array_filter($detail->json('data.facts'),
            static fn (array $fact): bool => ($fact['source_ref']['locator']['native_reference'] ?? null) === 'xlsx:sheet:Оборудование!C3'));
        self::assertCount(1, $presented);
        self::assertSame('dimension', $presented[0]['fact_type']);
        self::assertSame('3.0000', $presented[0]['value_number']);
        self::assertSame('шт', $presented[0]['unit']);
        self::assertSame($document->source_version, $presented[0]['source_ref']['source_version']);

        $before = $this->publicationCounts($session);
        $processedState = $session->fresh()->state_version;
        $this->post($this->url($project, $session), ['state_version' => 0, 'request_id' => $requestId, 'files' => [$file]], $headers)
            ->assertOk();
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 1);
        $this->app->call([$manifestJob, 'handle']);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 1);
        $this->app->call([$unitJob, 'handle']);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 1);
        self::assertSame($before, $this->publicationCounts($session));
        self::assertSame(1, $session->documents()->count());
        self::assertSame($processedState, $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public function test_upload_requires_authentication_before_admission_or_storage(): void
    {
        [, $project, $session] = $this->fixture();
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]],
            ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertNoAdmission($session);
    }

    public function test_real_role_without_upload_permission_cannot_store_a_document(): void
    {
        [$rights, $project, $session] = $this->fixture(false);
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]],
            $this->headers($rights->member))->assertForbidden();
        $this->assertNoAdmission($session);
    }

    public function test_authenticated_foreign_organization_cannot_upload_to_the_target_session(): void
    {
        [$rights, $project, $session] = $this->fixture();
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]],
            $this->headers($rights->foreignOwner))->assertForbidden()->assertJsonPath('message', trans_message('project.access_denied'));
        $this->assertNoAdmission($session);
    }

    public function test_upload_permission_does_not_replace_the_current_module_entitlement(): void
    {
        [$rights, $project, $session] = $this->fixture(packages: []);
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]],
            $this->headers($rights->member))->assertForbidden();
        $this->assertNoAdmission($session);
    }

    public function test_upload_permission_does_not_replace_admin_interface_access(): void
    {
        [$rights, $project, $session] = $this->fixture();
        $rights->memberRole->update(['interface_access' => ['lk']]);
        self::assertFalse(app(AuthorizationService::class)->canAccessInterface($rights->member, 'admin',
            AuthorizationContext::getOrganizationContext((int) $rights->organization->id)));
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]],
            $this->headers($rights->member))->assertForbidden()->assertJsonPath('message', trans_message('auth.access_denied'));
        $this->assertNoAdmission($session);
    }

    public function test_stale_upload_and_forged_retry_source_do_not_change_the_current_document(): void
    {
        [$rights, $project, $session] = $this->fixture();
        $headers = $this->headers($rights->member);
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]], $headers)->assertOk();
        $document = $session->documents()->sole();
        $before = [$document->getAttributes(), $session->fresh()->getAttributes(), $this->publicationCounts($session), $this->storageWrites];
        $this->post($this->url($project, $session), ['state_version' => 0, 'files' => [$this->workbook()]], $headers)
            ->assertConflict();
        $this->postJson($this->url($project, $session).'/'.$document->id.'/retry', [
            'state_version' => 1, 'source_version' => 'sha256:'.str_repeat('f', 64),
            'idempotency_key' => (string) Str::uuid(), 'reason' => 'Проверка текущего источника',
        ], $headers)->assertConflict()->assertJsonPath('disposition', 'stale_source');
        self::assertSame($before, [$document->fresh()->getAttributes(), $session->fresh()->getAttributes(), $this->publicationCounts($session), $this->storageWrites]);
        self::assertSame(1, $session->documents()->count());
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        Queue::assertNotPushed(ProcessEstimateGenerationUnitJob::class);
    }

    private function fixture(bool $upload = true, array $packages = ['working-entry']): array
    {
        config(['cache.default' => 'array', 'estimate-generation.universal_enabled' => true,
            'web_auth.origins.admin' => ['https://admin.fixture.test']]);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->storageWrites = 0;
        (new InMemoryS3HttpStorage)->install(function (RequestInterface $request) {
            if ($request->getMethod() === 'PUT') {
                $this->storageWrites++;
            }

            return null;
        });
        if (app(EstimateGenerationSettingsService::class)->currentSnapshot('global', null) === null) {
            app(EstimateGenerationSettingsService::class)->change((int) SystemAdmin::factory()->create()->id,
                EstimateGenerationSettingsData::fromArray([
                    'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
                    'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'fixture/classification', 'normative_matching' => 'fixture/normative'],
                    'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
                    'timeouts' => ['vision' => 80, 'classification' => 80, 'normative_matching' => 80],
                    'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
                    'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
                    'enabled_formats' => ['xlsx'], 'manual_review' => ['low_confidence' => true],
                    'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
                ]));
        }
        $rights = AssistantRealAuthorizationFixture::create($packages);
        $permissions = ['estimate_generation.view', 'estimate_generation.review'];
        if ($upload) {
            $permissions[] = 'estimate_generation.upload_documents';
        }
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => $permissions], 'system_permissions' => ['admin.access']]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false, 'address' => null]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id,
            'project_id' => $project->id, 'user_id' => $rights->member->id, 'status' => 'draft', 'state_version' => 0,
            'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [
                'evaluation_mode' => 'universal', 'selected_sections' => ['equipment'],
            ]]);

        return [$rights, $project, $session];
    }

    private function workbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Оборудование')->fromArray([
            ['entity_key', 'parameter', 'value', 'unit'],
            ['equipment:boiler', 'equipment_specification', 'Электрический котёл отопления массой до 30 кг.', ''],
            ['equipment:boiler', 'equipment_count', 3, 'шт'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'most-http-xlsx-');
        self::assertIsString($path);
        try {
            (new Xlsx($spreadsheet))->save($path);

            return UploadedFile::fake()->createWithContent('equipment.xlsx', file_get_contents($path));
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        $session = UserAuthSession::query()->create(['user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid), 'device_name' => 'MOST HTTP acceptance',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $session->session_uuid,
            (int) $actor->current_organization_id, false);

        return ['Accept' => 'application/json', 'Origin' => 'https://admin.fixture.test', 'Authorization' => 'Bearer '.$tokens->accessToken];
    }

    private function baseUrl(Project $project, EstimateGenerationSession $session): string
    {
        return '/api/v1/admin/projects/'.$project->id.'/estimate-generation/sessions/'.$session->id;
    }

    private function url(Project $project, EstimateGenerationSession $session): string
    {
        return $this->baseUrl($project, $session).'/documents';
    }

    private function snapshot(EstimateGenerationSession $session): \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot
    {
        return app(ProjectModelRepository::class)->snapshotForPlanning((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 100)['snapshot'];
    }

    private function publicationCounts(EstimateGenerationSession $session): array
    {
        $counts = [];
        foreach (['estimate_generation_project_model_assertions', 'estimate_generation_evidence', 'estimate_generation_document_facts'] as $table) {
            $counts[$table] = DB::table($table)->where('session_id', $session->id)->count();
        }

        return $counts;
    }

    private function assertNoAdmission(EstimateGenerationSession $session): void
    {
        self::assertSame(0, $session->documents()->count());
        self::assertSame(0, DB::table(DocumentAdmissionOperation::TABLE)->where('session_id', $session->id)->count());
        self::assertSame(0, $session->fresh()->state_version);
        self::assertSame(0, $this->storageWrites);
        Queue::assertNothingPushed();
    }
}
