<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Project;
use App\Services\Logging\LoggingService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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
    private Repository $tickets;
    private string $ticketKey;
    private LoggingService $logging;

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
        $this->logging = $logging;
        $this->tickets = new Repository(new ArrayStore());
        $this->ticketKey = bin2hex(random_bytes(32));
        $this->fence = new PublicCoreBackendAuthorityFence(DB::connection(), $logging, $this->tickets, $this->ticketKey);
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

    public function testBusyActorAcquireTimesOutWithoutRunningInspectorOrRollingBackWriter(): void
    {
        $this->writer->beginTransaction();
        $this->writer->table('users')->where('id', $this->fixture->owner->id)->update(['is_active' => false]);
        $started = hrtime(true);
        $this->assertDenied();
        self::assertLessThan(750, (hrtime(true) - $started) / 1000000);
        self::assertSame(1, $this->writer->transactionLevel());
        $this->writer->rollBack();
        $this->inspect(static fn (): null => null);
    }

    public function testAmbiguousOrganizationContextAndCyclicHierarchyDenyBeforeCanonicalTraversal(): void
    {
        $duplicateId = DB::table('authorization_contexts')->insertGetId([
            'type' => 'organization', 'resource_id' => $this->fixture->organization->id,
        ]);
        $this->assertDenied();
        DB::table('authorization_contexts')->where('id', $duplicateId)->delete();
        DB::table('authorization_contexts')->where('id', $this->fixture->ownerAssignment->context_id)
            ->update(['parent_context_id' => $this->fixture->ownerAssignment->context_id]);
        $this->assertDenied();
    }

    public function testInspectorFailureReleasesOnlyItsTransactionAndDoesNotIssueRuntimeAuthority(): void
    {
        try {
            $this->inspect(static function (): never { throw new LogicException('source_inspection_failed'); });
            self::fail('Inspector failure must not return a candidate');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
            self::assertSame('source_inspection_failed', $error->getPrevious()?->getMessage());
        }
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertSame(1, $this->writer->table('users')->where('id', $this->fixture->owner->id)->update(['is_active' => false]));
        self::assertFalse($this->fence->available());
        $this->assertDenied();
    }

    public function testOwnedViewerTicketBootstrapReloadsRealActorAcrossFenceInstances(): void
    {
        $expiry = time() + 60;
        $reference = $this->fence->issueViewerTicket($this->fixture->owner, $this->fixture->organization->id,
            Request::create('/public-core/ticket', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']), $expiry);
        $reopened = new PublicCoreBackendAuthorityFence(DB::connection(), $this->logging, $this->tickets, $this->ticketKey);
        $reply = $reopened->viewerTicketBinding(self::ticketCheck($reference), $expiry);
        self::assertSame(['schemaVersion', 'viewerTicketRef', 'currentViewer'], array_keys($reply));
        self::assertSame('public-core-app-viewer-ticket-binding/1', $reply['schemaVersion']);
        self::assertSame($reference, $reply['viewerTicketRef']);
        self::assertSame(['authorized', 'viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'],
            array_keys($reply['currentViewer']));
        self::assertTrue($reply['currentViewer']['authorized']);
        self::assertMatchesRegularExpression('/\Aactor_[a-f0-9]{64}\z/D', $reply['currentViewer']['viewerRef']);
        self::assertMatchesRegularExpression('/\Aorganization_[a-f0-9]{64}\z/D', $reply['currentViewer']['organizationRef']);
        self::assertSame($reply, $this->fence->viewerTicketBinding(self::ticketCheck($reference), $expiry));
        self::assertFalse($reopened->available());
        $channel = 'channel_'.str_repeat('b', 32);
        $frame = ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $channel, 'sequence' => 2,
            'command' => 'check_binding', 'requestRef' => null, 'attemptRef' => null, 'expiresAt' => $expiry,
            'payload' => self::ticketCheck($reference)];
        self::assertSame($reply, $reopened->viewerTicketControl($frame, $channel, 2, $expiry, 'Processor'));
        $this->fixture->ownerAssignment->update(['is_active' => false]);
        $this->assertTicketDenied(self::ticketCheck($reference), $expiry);
        self::assertSame(['schemaVersion' => 'public-core-app-viewer-ticket-denial/1', 'viewerTicketRef' => $reference,
            'reasonCode' => 'authorization_changed'], $reopened->viewerTicketControl($frame, $channel, 2, $expiry, 'Processor'));
    }

    public function testTicketBootstrapRejectsPrincipalFlagsUploadSchemaAndUnknownOwnership(): void
    {
        $expiry = time() + 60;
        $reference = $this->fence->issueViewerTicket($this->fixture->owner, $this->fixture->organization->id,
            Request::create('/public-core/ticket', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']), $expiry);
        $this->assertTicketDenied(self::ticketCheck($reference) + ['actorId' => $this->fixture->foreignOwner->id], $expiry);
        $this->assertTicketDenied(self::ticketCheck($reference) + ['authorized' => true], $expiry);
        $this->assertTicketDenied(['schemaVersion' => 'public-core-app-upload-acquire/1', 'viewerTicketRef' => $reference], $expiry);
        $this->assertTicketDenied(self::ticketCheck('viewer_'.str_repeat('0', 48)), $expiry);
        $this->assertTicketDenied(self::ticketCheck($reference), $expiry + 1);
        $this->assertTicketDenied(self::ticketCheck($reference), time() - 1);
    }

    public function testTicketTamperWrongKeyAndExplicitRevocationCannotReuseCurrentViewer(): void
    {
        $expiry = time() + 60;
        $reference = $this->fence->issueViewerTicket($this->fixture->owner, $this->fixture->organization->id,
            Request::create('/public-core/ticket', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']), $expiry);
        $stored = $this->tickets->get('ai-public-core:viewer:'.$reference);
        $tampered = $stored;
        $tampered['record']['actorId'] = $this->fixture->foreignOwner->id;
        $this->tickets->put('ai-public-core:viewer:'.$reference, $tampered, 60);
        $this->assertTicketDenied(self::ticketCheck($reference), $expiry);
        $this->tickets->put('ai-public-core:viewer:'.$reference, $stored, 60);
        $wrongKey = new PublicCoreBackendAuthorityFence(DB::connection(), $this->logging, $this->tickets, bin2hex(random_bytes(32)));
        try {
            $wrongKey->viewerTicketBinding(self::ticketCheck($reference), $expiry);
            self::fail('Foreign ticket key cannot authorize a viewer');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        $this->fence->revokeViewerTicket($reference);
        $this->assertTicketDenied(self::ticketCheck($reference), $expiry);
    }

    public function testSourceHeldGrantReleasesOnlyAfterPrivateCompletionBeforeDelayedResponse(): void
    {
        $proofs = (object) ['records' => []];
        [$binding, $expiry, $port] = $this->sourceAttempt($proofs);
        $grant = $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $port, 2, 'authorize_write',
            ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $port);
        self::assertSame(['schemaVersion', 'binding', 'currentViewer', 'guardRef', 'coverageEvidenceRef', 'uploadTimeoutMs'], array_keys($grant));
        self::assertSame('public-core-app-upload-grant/1', $grant['schemaVersion']);
        self::assertGreaterThan(0, $grant['uploadTimeoutMs']);
        self::assertLessThanOrEqual(2000, $grant['uploadTimeoutMs']);
        self::assertSame(1, DB::connection()->transactionLevel());
        self::assertTrue($port->sourceOnly());
        self::assertFalse($this->fence->available());
        $write = fn (): int => $this->writer->table('user_role_assignments')->where('id', $this->fixture->ownerAssignment->id)
            ->update(['is_active' => false]);
        $this->assertWriterBlocked($write);
        $completion = 'completion_'.bin2hex(random_bytes(24));
        $proofs->records[$completion] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
            'completionRef' => $completion, 'terminal' => 'uploaded'];
        $release = $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 3, 'upload_complete',
            self::sourceRelease($binding, $grant['guardRef'], $completion)), $port);
        self::assertSame(['schemaVersion' => 'public-core-app-upload-released/1', 'binding' => $binding,
            'guardRef' => $grant['guardRef']], $release);
        self::assertSame(0, DB::connection()->transactionLevel());
        usleep(50000);
        self::assertSame(1, $write());
        $this->assertDenied();
    }

    public function testSourceCancelAndUnknownManualCompletionKeepGuardUntilPrivateStoppedRecord(): void
    {
        $proofs = (object) ['records' => []];
        [$binding, $expiry, $port] = $this->sourceAttempt($proofs);
        $grant = $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $port, 2, 'authorize_write',
            ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $port);
        $cancel = $this->fence->cancelSourceGuard('gateway_unavailable');
        self::assertSame(['schemaVersion' => 'public-core-app-upload-cancel-request/1', 'binding' => $binding,
            'guardRef' => $grant['guardRef'], 'reasonCode' => 'gateway_unavailable'], $cancel);
        $manual = 'completion_'.str_repeat('0', 48);
        try {
            $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 3, 'upload_complete',
                self::sourceRelease($binding, $grant['guardRef'], $manual)), $port);
            self::fail('Manual completion cannot release a held scope');
        } catch (LogicException $error) {
            self::assertSame('receipt_unavailable', $error->getMessage());
        }
        self::assertSame(1, DB::connection()->transactionLevel());
        $this->assertWriterBlocked(fn (): int => $this->writer->table('users')->where('id', $this->fixture->owner->id)
            ->update(['is_active' => false]));
        $completion = 'completion_'.bin2hex(random_bytes(24));
        $proofs->records[$completion] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
            'completionRef' => $completion, 'terminal' => 'stopped'];
        $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 4, 'upload_complete',
            self::sourceRelease($binding, $grant['guardRef'], $completion)), $port);
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    public function testSourceExpiredLeaseReleasesOnlyAfterMatchingPrivateTerminalRecord(): void
    {
        foreach (['uploaded', 'stopped'] as $terminal) {
            $proofs = (object) ['records' => []];
            [$binding, $expiry, $port] = $this->sourceAttempt($proofs, time() + 2);
            $grant = $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $port, 2, 'authorize_write',
                ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $port);
            try {
                while (time() < $expiry) { usleep(20000); }
                self::assertSame(1, DB::connection()->transactionLevel());
                $write = fn (): int => $this->writer->table('user_role_assignments')->where('id', $this->fixture->ownerAssignment->id)
                    ->update(['is_active' => true]);
                $this->assertWriterBlocked($write);
                $unknown = 'completion_'.bin2hex(random_bytes(24));
                try {
                    $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 3, 'upload_complete',
                        self::sourceRelease($binding, $grant['guardRef'], $unknown)), $port);
                    self::fail('Expiry without private terminal proof cannot release the writer guard');
                } catch (LogicException $error) {
                    self::assertSame('receipt_unavailable', $error->getMessage());
                }
                self::assertSame(1, DB::connection()->transactionLevel());
                $this->assertWriterBlocked($write);
                $invalid = 'completion_'.bin2hex(random_bytes(24));
                $proofs->records[$invalid] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
                    'completionRef' => $invalid, 'terminal' => 'uncertain'];
                foreach ([4 => 'uncertain', 5 => 'wrong-binding'] as $sequence => $failure) {
                    if ($failure === 'wrong-binding') {
                        $proofs->records[$invalid]['terminal'] = $terminal;
                        $proofs->records[$invalid]['binding']['attemptRef'] = 'attempt_'.str_repeat('f', 48);
                    }
                    try {
                        $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, $sequence, 'upload_complete',
                            self::sourceRelease($binding, $grant['guardRef'], $invalid)), $port);
                        self::fail('Uncertain or foreign terminal record cannot release expired guard');
                    } catch (LogicException $error) {
                        self::assertSame('receipt_unavailable', $error->getMessage());
                    }
                    self::assertSame(1, DB::connection()->transactionLevel());
                }
                $completion = 'completion_'.bin2hex(random_bytes(24));
                $proofs->records[$completion] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
                    'completionRef' => $completion, 'terminal' => $terminal];
                $release = $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 6, 'upload_complete',
                    self::sourceRelease($binding, $grant['guardRef'], $completion)), $port);
                self::assertSame(['schemaVersion' => 'public-core-app-upload-released/1', 'binding' => $binding,
                    'guardRef' => $grant['guardRef']], $release);
                self::assertSame(0, DB::connection()->transactionLevel());
                self::assertSame(1, $write());
                self::assertFalse($this->fence->available());
                try {
                    $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $port, 7, 'authorize_write',
                        ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $port);
                    self::fail('Cleanup cannot refresh the expired original grant');
                } catch (LogicException $error) {
                    self::assertContains($error->getMessage(), ['authorization_changed', 'expired']);
                }
                self::assertSame(0, DB::connection()->transactionLevel());
            } finally {
                while (DB::connection()->transactionLevel() > 0) { DB::connection()->rollBack(); }
            }
        }
    }

    public function testSourceHeldProtocolRejectsWrongPeerAndReplayedConsumedAttempt(): void
    {
        $proofs = (object) ['records' => []];
        [$binding, $expiry, $port] = $this->sourceAttempt($proofs);
        $wrongPeer = PublicCoreContextBindings::sourceAppControlPort($port->sourceChannel(), 'Gateway', static fn (): null => null);
        try {
            $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $wrongPeer, 2, 'authorize_write',
                ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $wrongPeer);
            self::fail('Gateway role cannot acquire App backend scope');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        self::assertSame(0, DB::connection()->transactionLevel());
        $acquire = self::sourceFrame($binding, $expiry, $port, 2, 'authorize_write',
            ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]);
        $grant = $this->fence->acquireSourceGuard($acquire, $port);
        $completion = 'completion_'.bin2hex(random_bytes(24));
        $proofs->records[$completion] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
            'completionRef' => $completion, 'terminal' => 'uploaded'];
        $release = self::sourceFrame($binding, $expiry, $port, 3, 'upload_complete', self::sourceRelease($binding, $grant['guardRef'], $completion));
        $this->fence->releaseSourceGuard($release, $port);
        try {
            $this->fence->releaseSourceGuard($release, $port);
            self::fail('Released guard cannot release again');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        $newPort = PublicCoreContextBindings::sourceAppControlPort($port->sourceChannel(), 'Processor', static fn (): null => null);
        try {
            $this->fence->acquireSourceGuard($acquire, $newPort);
            self::fail('Consumed source attempt cannot acquire a second guard');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    public function testSourceOwnedBindingChecksFreshViewerBeforeAndDuringHeldScopeWithoutNestedTransaction(): void
    {
        $proofs = (object) ['records' => []];
        [$binding, $expiry, $port] = $this->sourceAttempt($proofs);
        $check = ['schemaVersion' => 'public-core-app-viewer-check/1', 'binding' => $binding];
        $before = $this->fence->checkSourceBinding(self::sourceFrame($binding, $expiry, $port, 2, 'check_binding', $check), $port);
        self::assertSame(['schemaVersion', 'binding', 'currentViewer'], array_keys($before));
        self::assertSame('public-core-app-viewer-binding/1', $before['schemaVersion']);
        self::assertSame($binding, $before['binding']);
        self::assertTrue($before['currentViewer']['authorized']);
        self::assertSame(0, DB::connection()->transactionLevel());
        $grant = $this->fence->acquireSourceGuard(self::sourceFrame($binding, $expiry, $port, 3, 'authorize_write',
            ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $binding]), $port);
        $during = $this->fence->checkSourceBinding(self::sourceFrame($binding, $expiry, $port, 4, 'check_binding', $check), $port);
        self::assertSame($before, $during);
        self::assertSame($grant['currentViewer'], $during['currentViewer']);
        self::assertSame(1, DB::connection()->transactionLevel());
        $completion = 'completion_'.bin2hex(random_bytes(24));
        $proofs->records[$completion] = ['binding' => $binding, 'guardRef' => $grant['guardRef'],
            'completionRef' => $completion, 'terminal' => 'uploaded'];
        $this->fence->releaseSourceGuard(self::sourceFrame($binding, $expiry, $port, 5, 'upload_complete',
            self::sourceRelease($binding, $grant['guardRef'], $completion)), $port);
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    public function testSourceOwnedBindingCannotReuseAuthorizationAfterRealAssignmentRevocation(): void
    {
        $proofs = (object) ['records' => []];
        [$binding, $expiry, $port] = $this->sourceAttempt($proofs);
        $check = ['schemaVersion' => 'public-core-app-viewer-check/1', 'binding' => $binding];
        $this->fence->checkSourceBinding(self::sourceFrame($binding, $expiry, $port, 2, 'check_binding', $check), $port);
        $this->writer->table('user_role_assignments')->where('id', $this->fixture->ownerAssignment->id)->update(['is_active' => false]);
        try {
            $this->fence->checkSourceBinding(self::sourceFrame($binding, $expiry, $port, 3, 'check_binding', $check), $port);
            self::fail('Revoked real assignment cannot retain owned viewer binding');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    private function sourceAttempt(object $proofs, ?int $originalExpiry = null): array
    {
        $expiry = $originalExpiry ?? time() + 60;
        $ticket = $this->fence->issueViewerTicket($this->fixture->owner, $this->fixture->organization->id,
            Request::create('/public-core/source-tcb', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']), $expiry);
        $binding = ['viewerTicketRef' => $ticket, 'requestRef' => 'request_'.bin2hex(random_bytes(24)),
            'attemptRef' => 'attempt_'.bin2hex(random_bytes(24)), 'projectionDigest' => str_repeat('a', 64),
            'profileFingerprint' => str_repeat('b', 64),
            'registryDigest' => 'f6bfc3c523c792ab9a80dbe2c3a950aebec1dfad65456243bbaadfcdb50a85fe',
            'manifestGenerationRef' => 'source-only-fixture-generation/1'];
        $this->fence->registerSourceAttempt($binding, $expiry);
        $port = PublicCoreContextBindings::sourceAppControlPort('channel_'.bin2hex(random_bytes(24)), 'Processor',
            static fn (string $ref): ?array => $proofs->records[$ref] ?? null);

        return [$binding, $expiry, $port];
    }

    private static function sourceFrame(array $binding, int $expiry, PublicCoreContextBindings $port, int $sequence,
        string $command, array $payload): array
    {
        return ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $port->sourceChannel(), 'sequence' => $sequence,
            'command' => $command, 'requestRef' => $binding['requestRef'], 'attemptRef' => $binding['attemptRef'],
            'expiresAt' => $expiry, 'payload' => $payload];
    }

    private static function sourceRelease(array $binding, string $guard, string $completion): array
    {
        return ['schemaVersion' => 'public-core-app-upload-release/1', 'binding' => $binding,
            'guardRef' => $guard, 'completionRef' => $completion];
    }

    private static function ticketCheck(string $reference): array
    {
        return ['schemaVersion' => 'public-core-app-viewer-ticket-check/1', 'viewerTicketRef' => $reference];
    }

    private function assertTicketDenied(array $payload, int $expiry): void
    {
        try {
            $this->fence->viewerTicketBinding($payload, $expiry);
            self::fail('Unowned or stale ticket cannot authorize a viewer');
        } catch (LogicException $error) {
            self::assertContains($error->getMessage(), ['authorization_changed', 'expired']);
        }
        self::assertSame(0, DB::connection()->transactionLevel());
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
