<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RunDocumentArbitration;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

final class DocumentArbitrationPostgresTest extends TestCase
{
    private int $organizationId;

    private int $projectId;

    private int $sessionId;

    private int $documentId;

    private int $pageId;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_arbiter_exact_replay_does_not_repeat_provider_call(): void
    {
        [$connection, $schema] = $this->fixture();
        try {
            $provider = new PostgresArbitrationProvider($this->pageId);
            $service = new RunDocumentArbitration(
                new EloquentAiRoleRunRepository($connection, 60),
                $provider,
                new ArbitrationInputBuilder,
                'openai/gpt-5.6-luna',
            );
            $source = $this->source();
            $observers = $this->observers();

            $first = $service->run($source, $observers);
            $second = $service->run($source, $observers);

            self::assertEquals($first->payload, $second->payload);
            self::assertSame(1, $provider->calls);
            self::assertSame(1, $connection->table('estimate_generation_ai_role_runs')->where('role', 'arbiter')->count());
            self::assertSame('completed', $connection->table('estimate_generation_ai_role_runs')->value('status'));
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    /** @return array{PostgresConnection,string} */
    private function fixture(): array
    {
        $connection = DB::connection();
        self::assertInstanceOf(PostgresConnection::class, $connection);
        $schema = 'public';
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'input_payload' => [], 'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id,
            'filename' => 'arbitration-contract.pdf', 'mime_type' => 'application/pdf',
        ]);
        $page = EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'session_id' => $session->id, 'page_number' => 4,
        ]);
        $this->organizationId = (int) $organization->id;
        $this->projectId = (int) $project->id;
        $this->sessionId = (int) $session->id;
        $this->documentId = (int) $document->id;
        $this->pageId = (int) $page->id;

        return [$connection, $schema];
    }

    private function cleanup(PostgresConnection $connection, string $schema): void
    {
        if ($schema !== 'public') {
            return;
        }
        foreach ([
            'estimate_generation_ai_role_runs', 'estimate_generation_vision_physical_attempts',
            'estimate_generation_document_pages', 'estimate_generation_documents',
            'estimate_generation_sessions', 'projects', 'users', 'organizations',
        ] as $table) {
            $connection->table($table)->delete();
        }
    }

    private function source(): VisionDocumentInput
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();

        return new VisionDocumentInput(
            $this->organizationId, $this->projectId, $this->sessionId, $this->documentId, $this->pageId,
            4, 19, 'sha256:'.str_repeat('a', 64),
            'sha256:'.hash('sha256', $content), 'image/png', $content, 'high',
            new AiOperationContext(
                '11111111-1111-5111-8111-111111111111', '22222222-2222-5222-8222-222222222222',
                $this->organizationId, $this->projectId, $this->sessionId,
                'understand_documents', 'vision', 1, $this->documentId, $this->pageId, 19,
            ),
            (new ProjectiveTransformFactory)->identity(),
        );
    }

    /** @return array<string,AiRoleRunResult> */
    private function observers(): array
    {
        $runs = [];
        foreach (['observer_literal', 'observer_construction', 'observer_risk'] as $role) {
            $short = str_replace('observer_', '', $role);
            $runs[$role] = new AiRoleRunResult([
                'role' => $role,
                'source' => ['document_id' => $this->documentId, 'page_id' => $this->pageId, 'source_version' => 'sha256:'.str_repeat('a', 64)],
                'claims' => [[
                    'entityKey' => 'wall-1', 'factType' => 'material',
                    'value' => ['type' => 'string', 'data' => 'газобетон'], 'unit' => null,
                    'evidenceRef' => 'note', 'sourcePolygonOrNativeRef' => 'pdf:page:4/text',
                ]],
                'evidence' => [[
                    'key' => 'note',
                    'locator' => ['page_id' => $this->pageId, 'page_number' => 4, 'processing_unit_id' => 19, 'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_derivative_v1'],
                ]],
            ], 'aaaaaaaa-aaaa-4aaa-8aaa-00000000000'.(count($runs) + 1));
        }

        return $runs;
    }
}

final class PostgresArbitrationProvider implements VisionProvider
{
    public int $calls = 0;

    public function __construct(private readonly int $pageId) {}

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $this->calls++;
        ($input->onPhysicalAttemptReserved)('bbbbbbbb-bbbb-4bbb-8bbb-000000000001');

        return new VisionAnalysisData(
            'detail', [VisionEvidenceData::fromArray(['key' => 'page', 'locator' => [
                'page_id' => $this->pageId, 'page_number' => 4, 'processing_unit_id' => 19,
                'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_derivative_v1',
            ]])], [], [], ['scale_missing'], 'timeweb', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna',
            'model:v1', 'measured', 1, 1, [], null, [], [[
                'claim_id' => 'literal:1', 'status' => 'accepted',
                'supporting_claim_ids' => ['literal:1'], 'evidence_refs' => ['literal:note'],
                'reason_code' => 'explicit_note',
            ]],
        );
    }
}
