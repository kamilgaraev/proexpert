<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgres-contract')]
final class AcceptedProductionEmptyRunTest extends TestCase
{
    protected $connectionsToTransact = [];

    public function test_empty_native_snapshot_can_complete_run_with_canonical_result(): void
    {
        $organization = Organization::factory()->verified()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope((int) $organization->id, [(int) $organization->id], [(int) $project->id], [], $timezone);
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor((int) $actor->id, 'active', ['reports.view', 'reports.run', 'reports.export']))
            ->scope($scope)
            ->authorization(new AuthorizationDecisionContext('queue', (int) $organization->id, [(int) $organization->id], [(int) $project->id], [], $timezone, 'empty-run-test', null))
            ->build();
        $definition = app(AcceptedProductionBuiltinPublishedReport::class)->definition()->payload();
        $asOf = new DateTimeImmutable('+3 days');
        $day = $asOf->modify('-1 day')->format('Y-m-d');
        $query = new ReportQuery($definition, $scope, new ReportFilterSet([
            'organization_id' => (string) $organization->id, 'project_id' => (string) $project->id,
            'period_from' => $day, 'period_to' => $day,
        ]), [], $asOf, 'ru-RU');
        $runs = app(ReportRunStore::class);
        $run = $runs->createOrReuse($context, $query, null, new IdempotencyKey('empty-native-run-'.Str::uuid()));
        $lease = (string) Str::uuid();
        $now = new DateTimeImmutable;
        $runs->claimMaterialization($context, $run->id, $lease, $now->modify('+10 minutes'), $now);

        $provider = app(AcceptedProductionReportProvider::class);
        $snapshot = $provider->materialize($context, $query, new ReportProgress(0));
        $result = $provider->result($context, $snapshot);
        self::assertSame(0, $result->metadata->rowCount);
        $sourceHash = app(CanonicalReportSourceHashBuilder::class)->build($query, $snapshot, $result);
        $snapshot = new ReportSnapshotRef(
            $snapshot->kind, $snapshot->id, $snapshot->scope, $snapshot->definitionHash, $snapshot->formulaVersion,
            $sourceHash, $snapshot->generatedAt, $snapshot->staleAt, $snapshot->watermarks, $snapshot->classification,
            $snapshot->seal, $snapshot->materializedSourceHash,
        );
        $result = $provider->result($context, $snapshot);
        self::assertSame($sourceHash->value, app(CanonicalReportSourceHashBuilder::class)->build($query, $snapshot, $result)->value);

        $ready = $runs->sealReady($context, $run->id, $lease, $snapshot, $result, $sourceHash, new DateTimeImmutable);

        self::assertSame(ReportRunStatus::READY, $ready->status);
        self::assertSame(0, $ready->rowCount);
        self::assertSame($sourceHash->value, $ready->sourceHash?->value);
    }
}
