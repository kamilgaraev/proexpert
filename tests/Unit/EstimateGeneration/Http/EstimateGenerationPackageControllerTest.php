<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPackageController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class EstimateGenerationPackageControllerTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function export_preserves_tenant_and_project_isolation_error(): void
    {
        foreach ([[8, 5], [7, 9]] as [$organizationId, $projectId]) {
            foreach (['csv', 'json', 'excel'] as $format) {
                [$request, $project, $session] = $this->context($organizationId, $projectId, EstimateGenerationStatus::Applied);
                $request->query->set('format', $format);
                try {
                    $this->app->make(EstimateGenerationPackageController::class)->export($request, $project, $session);
                    self::fail('Expected authorization error.');
                } catch (HttpExceptionInterface $exception) {
                    self::assertSame(403, $exception->getStatusCode());
                }
            }
        }
    }

    #[Test]
    public function export_rejects_a_status_not_published_by_the_snapshot(): void
    {
        [$request, $project, $session] = $this->context(7, 5, EstimateGenerationStatus::Generating);

        try {
            $this->app->make(EstimateGenerationPackageController::class)->export($request, $project, $session);
            self::fail('Expected status error.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
    }

    #[Test]
    public function export_returns_a_download_for_each_allowed_status(): void
    {
        foreach ([EstimateGenerationStatus::EstimateReviewRequired, EstimateGenerationStatus::ReadyToApply, EstimateGenerationStatus::Applied] as $status) {
            [$request, $project, $session] = $this->context(7, 5, $status);
            $response = $this->app->make(EstimateGenerationPackageController::class)->export($request, $project, $session);

            self::assertInstanceOf(StreamedResponse::class, $response);
            self::assertStringContainsString('estimate-generation-draft-13.json', (string) $response->headers->get('Content-Disposition'));
        }
    }

    #[Test]
    public function export_route_requires_the_export_permission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("middleware('authorize:estimate_generation.export,project,project')", $routes);
    }

    #[Test]
    public function legacy_json_keeps_the_raw_object_without_reading_universal_confirmation(): void
    {
        $reader = new class {
            public int $calls = 0;

            public function read(): never
            {
                $this->calls++;
                throw new \LogicException('Legacy export must not read a universal confirmation.');
            }
        };
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Application\Export\ReadConfirmedEvaluationExport::class, $reader);
        [$request, $project, $session] = $this->context(7, 5, EstimateGenerationStatus::EstimateReviewRequired);
        $raw = ['legacy_field' => 'Сохранённый черновик', 'local_estimates' => [], 'totals' => ['total_cost' => '12.34']];
        $session->forceFill(['input_payload' => ['evaluation_mode' => 'legacy'], 'draft_payload' => $raw]);
        $response = $this->app->make(EstimateGenerationPackageController::class)->export($request, $project, $session);
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame($raw, json_decode($body, true, 64, JSON_THROW_ON_ERROR));
        self::assertSame(0, $reader->calls);
    }

    /** @return array{Request, Project, EstimateGenerationSession} */
    private function context(int $organizationId, int $projectId, EstimateGenerationStatus $status): array
    {
        $request = Request::create('/export?format=json');
        $user = new User;
        $user->forceFill(['id' => 11, 'current_organization_id' => 7]);
        $request->setUserResolver(static fn (): User => $user);
        $project = new Project;
        $project->forceFill(['id' => 5]);
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 13,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'status' => $status,
            'draft_payload' => [],
        ]);

        return [$request, $project, $session];
    }
}
