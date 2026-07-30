<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Access\ReportEvidenceRedactor;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use DateTimeZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractorScorecardAccessIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_container_reader_abac_and_redaction_keep_foreign_source_identity_closed(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $sourceId = DB::table('acceptance_scopes')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $foreignProject->id,
            'title' => 'Foreign source',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $availableSourceId = DB::table('acceptance_scopes')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'title' => 'Available source',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('canAccessProject')->willReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(UserProjectAccessService::class, $projectAccess);

        $context = $this->context($actor, $organization, $project);
        $authorizer = $this->app->make(ReportSourceObjectAuthorizer::class);
        $redactor = $this->app->make(ReportEvidenceRedactor::class);
        self::assertInstanceOf(
            ReportSourceObjectReader::class,
            $this->app->make(ReportSourceObjectReader::class),
        );
        $availability = $authorizer->availability(
            $context,
            'acceptance_scope',
            $sourceId,
            (int) $organization->id,
            (int) $project->id,
        );
        $redacted = $redactor->reference(
            ['source_row_id' => $sourceId, 'source_row_key' => 'secret'],
            'acceptance_scope',
            $sourceId,
            $availability,
        );

        self::assertSame('missing', $availability);
        self::assertSame('redacted', $redacted['availability']);
        self::assertArrayNotHasKey('source_row_id', $redacted);
        self::assertArrayNotHasKey('source_row_key', $redacted);
        $available = $authorizer->availability(
            $context,
            'acceptance_scope',
            $availableSourceId,
            (int) $organization->id,
            (int) $project->id,
        );
        $visible = $redactor->reference(
            ['source_row_id' => $availableSourceId, 'source_row_key' => 'visible'],
            'acceptance_scope',
            $availableSourceId,
            $available,
        );
        self::assertSame('available', $available);
        self::assertSame($availableSourceId, $visible['source_row_id']);
        self::assertSame('visible', $visible['source_row_key']);
    }

    private function context(
        User $actor,
        Organization $organization,
        Project $project,
    ): ReportExecutionContext {
        return new ReportExecutionContext(
            new ReportActor((int) $actor->id, 'active', ['reports.project_readiness.view']),
            new ReportScope(
                (int) $organization->id,
                [(int) $organization->id],
                [(int) $project->id],
                [],
                new DateTimeZone('Europe/Moscow'),
            ),
            new ReportVisibility(true, true, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'http',
                (int) $organization->id,
                [(int) $organization->id],
                [(int) $project->id],
                [],
                new DateTimeZone('Europe/Moscow'),
                'contractor-access-integration',
                null,
            ),
        );
    }
}
