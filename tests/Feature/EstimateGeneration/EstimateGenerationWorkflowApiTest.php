<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Responses\AdminResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationWorkflowApiTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function admin_response_wraps_snapshot_exactly_once(): void
    {
        $session = new EstimateGenerationSession();
        $session->forceFill([
            'id' => 41,
            'project_id' => 17,
            'status' => EstimateGenerationStatus::ReadyToApply,
            'processing_stage' => 'ready',
            'processing_progress' => 100,
            'state_version' => 9,
            'draft_payload' => ['quality_summary' => []],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);
        $session->setRelation('documents', collect());

        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: ['estimate_generation.apply'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );
        $response = AdminResponse::success(new EstimateGenerationSessionResource($snapshot));
        $payload = $response->getData(true);

        self::assertTrue($payload['success']);
        self::assertSame(41, $payload['data']['id']);
        self::assertSame('ready_to_apply', $payload['data']['status']);
        self::assertArrayNotHasKey('data', $payload['data']);
        self::assertSame(['apply'], array_column($payload['data']['available_actions'], 'action'));
    }
}
