<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Project;
use App\Services\Logging\LoggingService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class PublicCoreAuthorizationFenceTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private PublicCoreBackendAuthorityFence $fence;
    private Connection $writer;

    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = AssistantRealAuthorizationFixture::create();
        $logging = Mockery::mock(LoggingService::class);
        $logging->shouldReceive('security', 'technical')->andReturnNull();
        $this->fence = new PublicCoreBackendAuthorityFence(DB::connection(), $logging);
        config(['database.connections.mostai_fence_writer' => DB::connection()->getConfig()]);
        $this->writer = DB::connection('mostai_fence_writer');
        self::assertSame(DB::connection()->getDatabaseName(), $this->writer->getDatabaseName());
        $this->writer->statement("SET lock_timeout = '40ms'");
        $this->writer->statement("SET statement_timeout = '200ms'");
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->transactionLevel() > 0) { $this->writer->rollBack(); }
            DB::purge('mostai_fence_writer');
        }
        parent::tearDown();
    }

    public function testUnknownCoverageCannotAuthorizeOperationFromFlagsOrCachedRevision(): void
    {
        $fence = new PublicCoreBackendAuthorityFence();
        $operation = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $operation->shouldNotReceive('dispatch');
        self::assertFalse($fence->available());
        try {
            $fence->withCurrent(['authorized' => true, 'fresh' => true, 'authorization_roles_revision' => 99],
                static function () use ($operation): void { $operation->dispatch(new \stdClass()); });
            self::fail('Missing real fence cannot authorize operation');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
    }

    public function testDefaultFenceDoesNotIssueCurrentViewerSnapshot(): void
    {
        $this->expectExceptionMessage('authorization_changed');
        (new PublicCoreBackendAuthorityFence())->liveSnapshot();
    }

    public function testCandidateUsesRealUncachedRoleAndEntitlementGraphWithoutQualifyingRuntime(): void
    {
        $scanner = new RoleScanner();
        $roles = $scanner->getAllRoles()->map(static function (array $role): array {
            $role['system_permissions'] = [];
            $role['module_permissions'] = [];
            $role['interface_access'] = [];

            return $role;
        });
        Cache::put('authorization_roles:v2', $roles, 3600);
        self::assertSame([], $scanner->getSystemPermissions('organization_owner'));
        $snapshot = $this->inspect(static fn (array $candidate): array => $candidate);
        self::assertSame($this->fixture->owner->id, $snapshot['actorId']);
        self::assertSame($this->fixture->organization->id, $snapshot['organizationId']);
        self::assertGreaterThan(0, $snapshot['remainingMs']);
        self::assertLessThanOrEqual(2000, $snapshot['remainingMs']);
        self::assertFalse($snapshot['runtimeQualified']);
        self::assertFalse($this->fence->available());
        self::assertSame([], $scanner->getSystemPermissions('organization_owner'));
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    public function testFreshCandidateDeniesRevokedRoleAndExpiredSubscription(): void
    {
        $this->inspect(static fn (): null => null);
        $this->fixture->ownerAssignment->update(['is_active' => false]);
        $this->assertDenied();
        $this->fixture->ownerAssignment->update(['is_active' => true]);
        $this->fixture->subscription->update(['current_period_end_at' => now()->subDay()]);
        $this->assertDenied();
    }

    public function testCandidateBlocksExistingMembershipRevocationAndReleasesBeforeFollowingWork(): void
    {
        $write = fn (): int => $this->writer->table('organization_user')->where('user_id', $this->fixture->owner->id)
            ->where('organization_id', $this->fixture->organization->id)->update(['is_active' => false]);
        $this->inspect(function () use ($write): void { $this->assertWriterBlocked($write); });
        self::assertSame(1, $write());
        $this->assertDenied();
    }

    public function testCandidateBlocksNewDenyConditionAndInactiveConditionActivation(): void
    {
        $inactive = RoleCondition::query()->create(['assignment_id' => $this->fixture->ownerAssignment->id,
            'condition_type' => 'budget', 'condition_data' => ['max_amount' => -1], 'is_active' => false]);
        $this->inspect(function () use ($inactive): void {
            $this->assertWriterBlocked(fn (): bool => $this->writer->table('role_conditions')->insert([
                'assignment_id' => $this->fixture->ownerAssignment->id, 'condition_type' => 'budget',
                'condition_data' => json_encode(['max_amount' => -1], JSON_THROW_ON_ERROR), 'is_active' => true,
            ]));
            $this->assertWriterBlocked(fn (): int => $this->writer->table('role_conditions')->where('id', $inactive->id)
                ->update(['is_active' => true]));
        });
        $inactive->update(['is_active' => true]);
        $this->assertDenied();
    }

    public function testCandidateBlocksContextPhantomAndSoftDeletedCrossOrganizationProjectActivation(): void
    {
        $project = Project::factory()->create(['organization_id' => $this->fixture->foreignOrganization->id,
            'status' => 'active', 'deleted_at' => now()]);
        $project->users()->attach($this->fixture->owner->id, ['is_active' => false]);
        RoleCondition::query()->create(['assignment_id' => $this->fixture->ownerAssignment->id,
            'condition_type' => 'project_count', 'condition_data' => ['max_projects' => 1], 'is_active' => true]);
        $this->inspect(function () use ($project): void {
            $this->assertWriterBlocked(fn (): bool => $this->writer->table('authorization_contexts')->insert([
                'type' => 'organization', 'resource_id' => $this->fixture->organization->id,
            ]));
            $this->assertWriterBlocked(fn (): int => $this->writer->table('project_user')->where('user_id', $this->fixture->owner->id)
                ->where('project_id', $project->id)->update(['is_active' => true]));
            $this->assertWriterBlocked(fn (): int => $this->writer->table('projects')->where('id', $project->id)
                ->update(['deleted_at' => null]));
        });
        $project->users()->updateExistingPivot($this->fixture->owner->id, ['is_active' => true]);
        $project->restore();
        $this->assertDenied();
    }

    public function testCandidateDeniesOriginDependentConditionsRatherThanUsingQueueRequest(): void
    {
        RoleCondition::query()->create(['assignment_id' => $this->fixture->ownerAssignment->id,
            'condition_type' => 'location', 'condition_data' => ['allowed_ips' => ['127.0.0.1']], 'is_active' => true]);
        $this->assertDenied();
    }

    private function inspect(\Closure $operation): mixed
    {
        return $this->fence->inspectSourceCandidate($this->fixture->owner, $this->fixture->organization->id,
            Request::create('/public-core/source-candidate', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']), $operation);
    }

    private function assertDenied(): void
    {
        $operation = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $operation->shouldNotReceive('dispatch');
        try {
            $this->inspect(static function () use ($operation): void { $operation->dispatch(new \stdClass()); });
            self::fail('Changed or uncovered authority cannot run the inspector');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    private function assertWriterBlocked(\Closure $write): void
    {
        $this->writer->beginTransaction();
        try {
            $write();
            $this->writer->commit();
            self::fail('Concurrent mutation must not commit inside the source guard');
        } catch (QueryException $error) {
            self::assertSame('55P03', $error->getCode());
        } finally {
            if ($this->writer->transactionLevel() > 0) { $this->writer->rollBack(); }
        }
    }
}
