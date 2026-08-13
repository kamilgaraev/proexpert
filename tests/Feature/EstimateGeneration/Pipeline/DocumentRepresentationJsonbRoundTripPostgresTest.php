<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DocumentReadinessClassifier;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

#[Group('postgres-contract')]
final class DocumentRepresentationJsonbRoundTripPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function pdf_representation_survives_processing_unit_jsonb_round_trip(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();

        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $sourceVersion = 'sha256:'.str_repeat('a', 64);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => [],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'architectural-album.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => $sourceVersion,
            ]);
            $artifactPath = 'org-'.$organization->id.'/estimate-generation/tests/page-1.png';
            $geometryPath = 'org-'.$organization->id.'/estimate-generation/tests/page-1.json';
            $representation = [
                'schema_version' => 1,
                'source_version' => $sourceVersion,
                'format' => 'pdf',
                'native_structure' => [
                    'geometry_artifact_path' => $geometryPath,
                    'geometry_artifact_sha256' => str_repeat('b', 64),
                    'text_spans_artifact_path' => $geometryPath,
                    'vector_artifact_path' => $geometryPath,
                    'native_reference_registry' => [],
                    'resource_measurement' => [
                        'memory_metric' => 'unavailable',
                        'limitations' => ['resource_measurement_missing'],
                    ],
                ],
                'visual_artifact_path' => $artifactPath,
                'coordinate_space' => 'pdf_page_pixels',
                'source_bounds' => [0, 0, 2382, 1684],
                'capabilities' => [
                    'text_spans' => 'available',
                    'vectors' => 'available',
                    'page_render' => 'available',
                    'source_coordinates' => 'available',
                ],
                'resource_usage' => [
                    'pages' => 1,
                    'objects' => 19299,
                    'bytes' => 420000,
                    'peak_memory_bytes' => 0,
                    'duration_ms' => 0,
                ],
            ];

            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'locator' => [
                    'source_kind' => 'pdf',
                    'source_version' => $sourceVersion,
                    'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => $artifactPath,
                    'artifact_source_version' => 'sha256:'.str_repeat('c', 64),
                    'artifact_version_id' => 'postgres-contract-page-1',
                    'artifact_bytes' => 420000,
                    'artifact_sha256' => 'sha256:'.str_repeat('c', 64),
                    'content_type' => 'image/png',
                    'geometry_artifact_path' => $geometryPath,
                    'geometry_artifact_bytes' => 300000,
                    'geometry_artifact_sha256' => 'sha256:'.str_repeat('b', 64),
                    'document_representation' => $representation,
                ],
                'metadata' => (object) [],
            ]);

            $persisted = EstimateGenerationProcessingUnit::query()->findOrFail($unit->id)->locator;
            self::assertNotSame(
                array_keys($representation['capabilities']),
                array_keys($persisted['document_representation']['capabilities']),
            );
            self::assertNotSame(
                array_keys($representation['resource_usage']),
                array_keys($persisted['document_representation']['resource_usage']),
            );

            $roundTripped = DocumentRepresentation::fromArray($persisted['document_representation']);

            self::assertSame($sourceVersion, $roundTripped->source->value);
            self::assertSame($representation['capabilities'], $roundTripped->capabilities->toArray());
            self::assertSame($representation['resource_usage'], $roundTripped->resourceUsage);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function concurrent_scoped_systemic_failures_stop_pending_units_without_crossing_document_scope(): void
    {
        $this->requireEnvironment();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $sourceVersion = 'sha256:'.str_repeat('d', 64);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'systemic.pdf',
            'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion,
        ]);
        $otherDocument = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'unrelated.pdf',
            'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion,
        ]);
        $units = [];
        $attemptId = 'd173fcc2-5f5c-44b1-91f1-94034f1b0bb5';
        foreach (range(1, 5) as $index) {
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => $index,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'locator' => $this->locator($organization->id, $sourceVersion, $index),
                'metadata' => ['processing_attempt_id' => $attemptId],
            ]);
            EstimateGenerationDocumentPage::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'processing_unit_id' => $unit->id,
                'source_version' => $sourceVersion,
                'page_number' => $index,
                'status' => 'queued',
                'language_codes' => [],
                'normalized_payload' => [],
                'quality_flags' => [],
            ]);
            $units[] = $unit;
        }
        $unrelated = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $otherDocument->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 1,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => $this->locator($organization->id, $sourceVersion, 99),
            'metadata' => (object) [],
        ]);
        $differentLineage = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 6,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => $this->locator($organization->id, $sourceVersion, 100),
            'metadata' => ['processing_attempt_id' => '51462476-b870-44eb-b31d-1b5d74d511e9'],
        ]);
        $differentContractLocator = $this->locator($organization->id, $sourceVersion, 101);
        $differentContractLocator['content_type'] = 'application/pdf';
        $differentContract = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 7,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => $differentContractLocator,
            'metadata' => ['processing_attempt_id' => $attemptId],
        ]);
        $store = new EloquentDocumentProcessingUnitStore(DB::connection());
        $now = new DateTimeImmutable('2026-08-13T00:00:00+00:00');
        $claims = [];
        foreach (array_slice($units, 0, 2) as $unit) {
            $claims[] = $store->claim($unit->id, $sourceVersion, $now, $now->modify('+60 seconds'), ProcessDocumentUnit::MAX_ATTEMPTS);
        }
        $fingerprint = hash('sha256', 'shared-systemic-root');

        $processes = [];
        foreach ($claims as $claim) {
            $payload = json_encode([
                'unit_id' => $claim->unitId,
                'token' => $claim->token,
                'organization_id' => $claim->organizationId,
                'project_id' => $claim->projectId,
                'session_id' => $claim->sessionId,
                'document_id' => $claim->documentId,
                'source_version' => $claim->sourceVersion,
                'fingerprint' => $fingerprint,
                'failed_at' => $now->modify('+1 second')->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR);
            $process = new Process(
                [PHP_BINARY, 'tests/Runtime/fail-document-unit-contract-child.php'],
                dirname(__DIR__, 4),
                ['ESTIMATE_UNIT_FAILURE_CLAIM' => $payload],
            );
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        $persisted = EstimateGenerationProcessingUnit::query()
            ->where('document_id', $document->id)
            ->where('metadata->processing_attempt_id', $attemptId)
            ->where('locator->content_type', 'image/png')
            ->orderBy('unit_index')
            ->get();
        self::assertSame([3, 3, 3, 3, 3], $persisted->pluck('attempt_count')->all());
        self::assertSame(
            ['document_representation_contract_invalid', 'document_representation_contract_invalid', 'breaker_stopped', 'breaker_stopped', 'breaker_stopped'],
            $persisted->pluck('failure_code')->all(),
        );
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed'], $persisted->pluck('status')->map->value->all());
        self::assertSame([1, 1, 0, 0, 0], $persisted->map(
            static fn ($unit): int => (int) ($unit->metadata['actual_execution_count'] ?? -1),
        )->all());
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'failed'], EstimateGenerationDocumentPage::query()
            ->where('document_id', $document->id)
            ->orderBy('page_number')
            ->pluck('status')
            ->all());
        self::assertSame('pending', EstimateGenerationProcessingUnit::query()->findOrFail($unrelated->id)->status->value);
        self::assertSame('pending', EstimateGenerationProcessingUnit::query()->findOrFail($differentLineage->id)->status->value);
        self::assertSame('pending', EstimateGenerationProcessingUnit::query()->findOrFail($differentContract->id)->status->value);
    }

    #[Test]
    public function all_terminal_pages_persist_honest_document_outcome_and_stale_replay_is_inert(): void
    {
        $this->requireEnvironment();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $sourceVersion = 'sha256:'.str_repeat('e', 64);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'processing_documents',
            'processing_stage' => 'processing_documents',
            'processing_progress' => 30,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'terminal.pdf',
            'mime_type' => 'application/pdf',
            'source_version' => $sourceVersion,
            'status' => 'processing',
            'processing_stage' => 'preflight',
            'progress_percent' => 30,
            'meta' => [
                'processing_attempt_id' => 'd173fcc2-5f5c-44b1-91f1-94034f1b0bb5',
                'explicit_document_retry' => [
                    'idempotency_hash' => hash('sha256', 'terminal-replay-key'),
                    'attempt_id' => 'd173fcc2-5f5c-44b1-91f1-94034f1b0bb5',
                    'source_version' => $sourceVersion,
                    'status' => 'processing',
                    'requested_at' => now()->toISOString(),
                ],
            ],
        ]);
        foreach (range(1, 3) as $index) {
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => $index,
                'source_version' => $sourceVersion,
                'status' => 'failed',
                'attempt_count' => ProcessDocumentUnit::MAX_ATTEMPTS,
                'output_count' => 0,
                'failure_code' => 'document_systemic_failure',
                'failure_fingerprint' => hash('sha256', 'terminal-root'),
                'failed_at' => now(),
                'locator' => $this->locator($organization->id, $sourceVersion, $index),
                'metadata' => [
                    'failure_category' => 'terminal',
                    'processing_attempt_id' => 'd173fcc2-5f5c-44b1-91f1-94034f1b0bb5',
                    'actual_execution_count' => $index === 1 ? 1 : 0,
                    'resource_usage' => [
                        'duration_ms' => 50 * $index,
                        'peak_memory_bytes' => 1024 * $index,
                    ],
                ],
            ]);
            EstimateGenerationDocumentPage::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'processing_unit_id' => $unit->id,
                'source_version' => $sourceVersion,
                'page_number' => $index,
                'status' => 'failed',
                'language_codes' => [],
                'normalized_payload' => [],
                'quality_flags' => [],
            ]);
        }
        $reconciler = app(EloquentDocumentUnitAggregateReconciler::class);

        $reconciler->reconcile($document->id, 'sha256:'.str_repeat('f', 64));
        self::assertSame('processing', $document->fresh()->status);
        $reconciler->reconcile($document->id, $sourceVersion);
        $persisted = $document->fresh();

        self::assertSame('failed', $persisted->status);
        self::assertSame('completed', $persisted->processing_stage);
        self::assertSame(100, $persisted->progress_percent);
        self::assertSame(3, $persisted->page_count);
        self::assertSame(0, $persisted->processed_page_count);
        self::assertSame('document_processing_system_failed', $persisted->error_code);
        self::assertSame('estimate_generation.document_processing_system_failed', $persisted->error_message_key);
        self::assertSame(3, $persisted->facts_summary['processing_outcome']['counts']['system_failed']);
        self::assertSame(0, $persisted->facts_summary['processing_outcome']['counts']['terminal_system_failed']);
        self::assertSame(3, $persisted->facts_summary['processing_outcome']['counts']['breaker_stopped']);
        self::assertSame(0, $persisted->facts_summary['processing_outcome']['counts']['processing']);
        self::assertSame('failed', $persisted->meta['explicit_document_retry']['status']);
        self::assertNotNull($persisted->meta['explicit_document_retry']['completed_at']);
        self::assertSame('system_failure', $persisted->meta['explicit_document_retry']['terminal_reason']);
        self::assertSame(1, $persisted->meta['explicit_document_retry']['actual_execution_count']);
        self::assertSame('sha256:'.hash('sha256', 'terminal-root'), $persisted->meta['explicit_document_retry']['diagnostic_fingerprint']);
        self::assertEqualsCanonicalizing([
            'measured_units' => 3,
            'duration_ms_total' => 300,
            'duration_ms_max' => 150,
            'peak_memory_bytes_max' => 3072,
        ], $persisted->facts_summary['resource_usage']);
        $persistedSession = $session->fresh();
        self::assertSame('failed', $persistedSession->status->value);
        self::assertSame('processing_documents', $persistedSession->resume_status->value);
        self::assertSame('document_processing_system_failed', $persistedSession->failure_code);

        $updatedAt = $persisted->updated_at->toISOString();
        $reconciler->reconcile($document->id, $sourceVersion);
        self::assertSame($updatedAt, $document->fresh()->updated_at->toISOString());
    }

    #[Test]
    public function operational_sql_does_not_classify_legacy_current_source_systemic_failure_as_user_action(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();

        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'processing_documents',
                'processing_stage' => 'processing_documents',
                'processing_progress' => 30,
                'input_payload' => [],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'legacy-systemic.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => 'sha256:current',
                'status' => 'needs_review',
            ]);
            foreach (range(1, 3) as $index) {
                EstimateGenerationProcessingUnit::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'session_id' => $session->id,
                    'document_id' => $document->id,
                    'unit_type' => 'pdf_page',
                    'unit_index' => $index,
                    'source_version' => 'sha256:current',
                    'status' => 'failed',
                    'attempt_count' => 3,
                    'output_count' => 0,
                    'failure_code' => 'document_geometry_processing_failed',
                    'failure_fingerprint' => hash('sha256', 'legacy-root'),
                    'failed_at' => now(),
                    'locator' => $this->locator($organization->id, 'sha256:current', $index),
                    'metadata' => (object) [],
                ]);
            }

            $actionRequired = EstimateGenerationDocument::query()
                ->whereKey($document->id)
                ->whereRaw((new DocumentReadinessClassifier)->actionRequiredSql())
                ->count();

            self::assertSame(0, $actionRequired);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    #[DataProvider('documentProcessingSessionStatuses')]
    public function legacy_systemic_units_transition_session_to_resumable_system_failure(string $initialStatus): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();

        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => $initialStatus,
                'processing_stage' => $initialStatus === 'input_review_required' ? 'input_review_required' : 'processing_documents',
                'processing_progress' => 100,
                'input_payload' => [],
                'state_version' => 0,
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'legacy-systemic.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => 'sha256:current',
                'status' => 'needs_review',
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'page_count' => 3,
                'processed_page_count' => 0,
            ]);
            foreach (range(1, 3) as $index) {
                EstimateGenerationProcessingUnit::query()->create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'session_id' => $session->id,
                    'document_id' => $document->id,
                    'unit_type' => 'pdf_page',
                    'unit_index' => $index,
                    'source_version' => 'sha256:current',
                    'status' => 'failed',
                    'attempt_count' => 3,
                    'output_count' => 0,
                    'failure_code' => 'document_geometry_processing_failed',
                    'failure_fingerprint' => hash('sha256', 'legacy-root'),
                    'failed_at' => now(),
                    'locator' => $this->locator($organization->id, 'sha256:current', $index),
                    'metadata' => (object) [],
                ]);
            }

            app(ReconcileEstimateGenerationDocuments::class)->reconcile($session);
            $persisted = $session->fresh();

            self::assertSame('failed', $persisted->status->value);
            self::assertSame('processing_documents', $persisted->resume_status->value);
            self::assertSame('document_processing_system_failed', $persisted->failure_code);
        } finally {
            DB::rollBack();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function documentProcessingSessionStatuses(): iterable
    {
        yield 'processing documents' => ['processing_documents'];
        yield 'premature input review' => ['input_review_required'];
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }

    /** @return array<string, bool|int|string> */
    private function locator(int $organizationId, string $sourceVersion, int $index): array
    {
        $hash = hash('sha256', $sourceVersion.'|'.$index);

        return [
            'source_kind' => 'pdf',
            'source_version' => $sourceVersion,
            'coordinate_space' => 'pdf_page_pixels',
            'artifact_path' => sprintf('org-%d/estimate-generation/tests/page-%d.png', $organizationId, $index),
            'artifact_source_version' => 'sha256:'.$hash,
            'artifact_version_id' => 'postgres-contract-page-'.$index,
            'artifact_bytes' => 1,
            'artifact_sha256' => 'sha256:'.$hash,
            'content_type' => 'image/png',
        ];
    }
}
