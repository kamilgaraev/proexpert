<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationSessionControllerTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function snapshot_returns_safe_not_found_when_session_disappears_during_consistent_read(): void
    {
        $builder = new class implements SessionOperationalSnapshotBuilder
        {
            public function handle(EstimateGenerationSession $boundSession, array $permissions): SessionSnapshotData
            {
                throw (new ModelNotFoundException)->setModel(EstimateGenerationSession::class, [$boundSession->getKey()]);
            }
        };
        $stateStore = $this->createMock(SessionStateStore::class);
        $controller = new EstimateGenerationSessionController(
            new CreateEstimateGenerationSession($stateStore),
            new EstimateGenerationRegionalContextResolver,
            $builder,
        );
        $request = Request::create('/snapshot');
        $user = new User;
        $user->forceFill(['id' => 11, 'current_organization_id' => 7]);
        $request->setUserResolver(static fn (): User => $user);
        $project = new Project;
        $project->forceFill(['id' => 5]);
        $session = new EstimateGenerationSession;
        $session->forceFill(['id' => 13, 'organization_id' => 7, 'project_id' => 5]);

        $response = $controller->snapshot($request, $project, $session);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(trans_message('errors.resource_not_found'), $response->getData(true)['message']);
    }
}
