<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

class PublicCoreBackendAuthorityFence
{
    private const ACQUIRE_MS = 250;
    private const GUARD_MS = 2000;
    private const TUPLE_KEYS = ['viewerTicketRef', 'requestRef', 'attemptRef', 'projectionDigest',
        'profileFingerprint', 'registryDigest', 'manifestGenerationRef'];
    private ?array $sourceHeld = null;

    public function __construct(private readonly ?Connection $connection = null,
        private readonly ?LoggingService $logging = null, private readonly ?Repository $tickets = null,
        private readonly ?string $ticketKey = null)
    {
    }

    public function available(): bool
    {
        try {
            $this->assertTicketStore();
            if ($this->connection === null || $this->logging === null) { return false; }
            $this->assertDatasource($this->connection);
            $this->assertTopology($this->connection);
            return true;
        } catch (Throwable) { return false; }
    }

    public function withCurrent(array $ownedTicket, Closure $operation): mixed
    {
        throw new LogicException('authorization_changed');
    }

    public function liveSnapshot(): array
    {
        throw new LogicException('authorization_changed');
    }

    public function issueViewerTicket(User $actor, int $organizationId, Request $origin, int $expiresAt): string
    {
        $this->assertTicketStore();
        if ($expiresAt <= time()) { throw new LogicException('expired'); }
        $this->inspectSourceCandidate($actor, $organizationId, $origin, static fn (): null => null);
        if ($expiresAt <= time()) { throw new LogicException('expired'); }
        $reference = 'viewer_'.bin2hex(random_bytes(24));
        $record = ['actorId' => $actor->id, 'organizationId' => $organizationId,
            'originIp' => $origin->ip(), 'expiresAt' => $expiresAt];
        if (!$this->tickets->put('ai-public-core:viewer:'.$reference,
            ['record' => $record, 'mac' => $this->ticketMac($reference, $record)], $expiresAt - time())) {
            throw new LogicException('authorization_changed');
        }

        return $reference;
    }

    public function viewerTicketBinding(array $payload, int $frameExpiresAt): array
    {
        if (count($payload) !== 2 || array_diff(array_keys($payload), ['schemaVersion', 'viewerTicketRef']) !== []
            || ($payload['schemaVersion'] ?? null) !== 'public-core-app-viewer-ticket-check/1'
            || !is_string($payload['viewerTicketRef'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $reference = $payload['viewerTicketRef'];
        $record = $this->ownedViewerTicket($reference);
        if ($frameExpiresAt <= time() || $frameExpiresAt > $record['expiresAt']) { throw new LogicException('expired'); }
        if ($this->sourceHeld !== null) {
            $held = $this->sourceHeld;
            if ($held['binding']['viewerTicketRef'] !== $reference || $held['scope']['snapshot']['actorId'] !== $record['actorId']
                || $held['scope']['snapshot']['organizationId'] !== $record['organizationId'] || $frameExpiresAt > $held['expiresAt']) {
                throw new LogicException('authorization_changed');
            }
            $this->assertSourceScopeCurrent($held['scope']);
            return ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1', 'viewerTicketRef' => $reference,
                'currentViewer' => $this->sourceCurrentViewer($held['scope']['snapshot'])];
        }
        $actor = User::query()->find($record['actorId']);
        if (!$actor instanceof User) { throw new LogicException('authorization_changed'); }
        $origin = Request::create('/public-core/owned-viewer', 'GET', [], [], [], ['REMOTE_ADDR' => $record['originIp']]);
        $viewer = $this->inspectSourceCandidate($actor, $record['organizationId'], $origin,
            fn (array $candidate): array => $this->sourceCurrentViewer($candidate));
        if ($frameExpiresAt <= time() || $record !== $this->ownedViewerTicket($reference)) {
            throw new LogicException('authorization_changed');
        }

        return ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1',
            'viewerTicketRef' => $reference, 'currentViewer' => $viewer];
    }

    public function viewerTicketControl(array $frame, string $channelRef, int $sequence, int $expiresAt,
        string $peerRole): array
    {
        $payload = PublicCoreContextBindings::appViewerBootstrapPayload($frame, $channelRef, $sequence, $expiresAt, $peerRole);
        try {
            return $this->viewerTicketBinding($payload, $expiresAt);
        } catch (LogicException $error) {
            return ['schemaVersion' => 'public-core-app-viewer-ticket-denial/1',
                'viewerTicketRef' => $payload['viewerTicketRef'],
                'reasonCode' => $error->getMessage() === 'expired' ? 'expired' : 'authorization_changed'];
        }
    }

    public function revokeViewerTicket(string $reference): void
    {
        $this->ownedViewerTicket($reference);
        if (!$this->tickets->forget('ai-public-core:viewer:'.$reference)) {
            throw new LogicException('authorization_changed');
        }
    }

    public function ownedRequest(string $reference, ?User $viewer = null, ?int $organizationId = null): array
    {
        $this->assertTicketStore();
        if (!\App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource::opaqueRef($reference)) {
            throw new LogicException('authorization_changed');
        }
        $value = $this->tickets->get('ai-public-core:owned-request:'.$reference);
        if (!is_array($value) || count($value) !== 2 || !is_array($value['record'] ?? null) || !is_string($value['mac'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $row = $value['record'];
        if (array_keys($row) !== ['schemaVersion', 'viewerTicketRef', 'actorId', 'organizationId', 'selection', 'requestRef',
            'public_session_ref', 'process_ref', 'expiresAt'] || $row['schemaVersion'] !== 'public-core-app-owned-request/1'
            || $row['requestRef'] !== $reference || !is_int($row['expiresAt']) || $row['expiresAt'] <= time()
            || !hash_equals($this->ownedRequestMac($row), $value['mac'])
            || ($viewer !== null && $viewer->id !== $row['actorId'])
            || ($organizationId !== null && $organizationId !== $row['organizationId'])) { throw new LogicException('authorization_changed'); }
        $ticket = $this->ownedViewerTicket($row['viewerTicketRef']);
        if ($ticket['actorId'] !== $row['actorId'] || $ticket['organizationId'] !== $row['organizationId'] || $row['expiresAt'] > $ticket['expiresAt']) {
            throw new LogicException('authorization_changed');
        }
        $this->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1', 'viewerTicketRef' => $row['viewerTicketRef']], $row['expiresAt']);
        return $row;
    }

    public function findOwnedSelection(User $viewer, int $organizationId, array $selection): ?array
    {
        $this->assertTicketStore();
        $reference = $this->tickets->get($this->selectionKey($viewer->id, $organizationId, $selection));
        if ($reference === null) { return null; }
        if (!is_string($reference)) { throw new LogicException('receipt_changed'); }
        $owned = $this->ownedRequest($reference, $viewer, $organizationId);
        if ($owned['selection'] !== $selection) { throw new LogicException('receipt_changed'); }
        return $owned;
    }

    public function withOwnedSelection(User $viewer, int $organizationId, array $selection, Closure $operation): mixed
    {
        return $this->withOwnedLock($this->selectionKey($viewer->id, $organizationId, $selection), $operation);
    }

    public function ownedSelectionTicket(User $viewer, int $organizationId, array $selection, Request $origin): array
    {
        $key = $this->selectionKey($viewer->id, $organizationId, $selection).':opening';
        $stored = $this->tickets->get($key);
        if ($stored === null) {
            $ticket = $this->issueViewerTicket($viewer, $organizationId, $origin, time() + 150);
            $row = ['viewerTicketRef' => $ticket, 'actorId' => $viewer->id, 'organizationId' => $organizationId,
                'selection' => $selection, 'expiresAt' => $this->ownedViewerTicket($ticket)['expiresAt']];
            if (!$this->tickets->add($key, ['record' => $row, 'mac' => $this->ticketMac($key, $row)], $row['expiresAt'] - time())) {
                throw new LogicException('receipt_changed');
            }
            $stored = $this->tickets->get($key);
        }
        $row = $this->signedOwnedRecord($key, $stored);
        if (array_keys($row) !== ['viewerTicketRef', 'actorId', 'organizationId', 'selection', 'expiresAt']
            || $row['actorId'] !== $viewer->id || $row['organizationId'] !== $organizationId || $row['selection'] !== $selection
            || !is_int($row['expiresAt']) || $row['expiresAt'] <= time()
            || $this->ownedViewerTicket($row['viewerTicketRef'])['expiresAt'] !== $row['expiresAt']) {
            throw new LogicException('receipt_changed');
        }
        $this->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
            'viewerTicketRef' => $row['viewerTicketRef']], $row['expiresAt']);
        return ['viewerTicketRef' => $row['viewerTicketRef'], 'expiresAt' => $row['expiresAt']];
    }

    public function ensureOwnedRequestEnqueued(string $reference, Closure $enqueue): void
    {
        $this->withOwnedLock('ai-public-core:enqueue:'.$reference, function () use ($reference, $enqueue): void {
            $owned = $this->ownedRequest($reference);
            $key = 'ai-public-core:enqueued:'.$reference;
            $stored = $this->tickets->get($key);
            if ($stored !== null) {
                $row = $this->signedOwnedRecord($key, $stored);
                if (array_keys($row) !== ['requestRef', 'viewerTicketRef', 'expiresAt', 'jobId']
                    || $row['requestRef'] !== $reference || $row['viewerTicketRef'] !== $owned['viewerTicketRef']
                    || $row['expiresAt'] !== $owned['expiresAt'] || !is_string($row['jobId']) || $row['jobId'] === '') {
                    throw new LogicException('receipt_changed');
                }
                return;
            }
            try { $jobId = $enqueue($reference); }
            catch (Throwable $error) { throw new LogicException('receipt_unavailable', 0, $error); }
            $current = $this->ownedRequest($reference);
            if ($current !== $owned || (!is_string($jobId) && !is_int($jobId)) || (string) $jobId === ''
                || strlen((string) $jobId) > 200 || $owned['expiresAt'] <= time()) {
                throw new LogicException('receipt_unavailable');
            }
            $row = ['requestRef' => $reference, 'viewerTicketRef' => $owned['viewerTicketRef'],
                'expiresAt' => $owned['expiresAt'], 'jobId' => (string) $jobId];
            if (!$this->tickets->add($key, ['record' => $row, 'mac' => $this->ticketMac($key, $row)], $owned['expiresAt'] - time())) {
                throw new LogicException('receipt_changed');
            }
        });
    }

    private function signedOwnedRecord(string $key, mixed $value): array
    {
        if (!is_array($value) || array_keys($value) !== ['record', 'mac'] || !is_array($value['record'])
            || !is_string($value['mac']) || !hash_equals($this->ticketMac($key, $value['record']), $value['mac'])) {
            throw new LogicException('receipt_changed');
        }
        return $value['record'];
    }

    private function withOwnedLock(string $key, Closure $operation): mixed
    {
        $this->assertTicketStore();
        $store = $this->tickets instanceof \Illuminate\Cache\Repository ? $this->tickets->getStore() : null;
        if (!$store instanceof LockProvider) { throw new LogicException('receipt_unavailable'); }
        $lock = $store->lock($key.':lock', 60);
        if (!$lock->get()) { throw new LogicException('receipt_changed'); }
        try { return $operation(); }
        finally { $lock->release(); }
    }

    public function rememberOwnedRequest(string $ticketRef, array $selection, array $opened): array
    {
        $ticket = $this->ownedViewerTicket($ticketRef);
        foreach (['request_ref', 'public_session_ref', 'process_ref'] as $key) {
            if (!\App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource::opaqueRef($opened[$key] ?? null)) {
                throw new LogicException('receipt_changed');
            }
        }
        if (($opened['status'] ?? null) !== 'accepted' || !is_int($opened['original_expires_at'] ?? null)
            || $opened['original_expires_at'] <= time() || $opened['original_expires_at'] > $ticket['expiresAt']) { throw new LogicException('expired'); }
        $row = ['schemaVersion' => 'public-core-app-owned-request/1', 'viewerTicketRef' => $ticketRef,
            'actorId' => $ticket['actorId'], 'organizationId' => $ticket['organizationId'], 'selection' => $selection,
            'requestRef' => $opened['request_ref'], 'public_session_ref' => $opened['public_session_ref'],
            'process_ref' => $opened['process_ref'], 'expiresAt' => $opened['original_expires_at']];
        $ttl = $row['expiresAt'] - time();
        if ($ttl <= 0) { throw new LogicException('expired'); }
        $requestKey = 'ai-public-core:owned-request:'.$row['requestRef'];
        $signed = ['record' => $row, 'mac' => $this->ownedRequestMac($row)];
        if (!$this->tickets->add($requestKey, $signed, $ttl) && $this->tickets->get($requestKey) !== $signed) {
            throw new LogicException('receipt_changed');
        }
        $selectionKey = $this->selectionKey($ticket['actorId'], $ticket['organizationId'], $selection);
        if (!$this->tickets->add($selectionKey, $row['requestRef'], $ttl) && $this->tickets->get($selectionKey) !== $row['requestRef']) {
            throw new LogicException('receipt_changed');
        }
        return $this->ownedRequest($row['requestRef']);
    }

    private function selectionKey(int $actorId, int $organizationId, array $selection): string
    {
        $uuid = $selection['request_id'] ?? null;
        if (!is_string($uuid) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $uuid) !== 1) {
            throw new LogicException('receipt_changed');
        }
        return 'ai-public-core:selection:'.hash_hmac('sha256', $actorId.':'.$organizationId.':'.$uuid, $this->ownedTicketKey());
    }

    private function ownedRequestMac(array $record): string
    {
        return hash_hmac('sha256', json_encode(['public-core-owned-request', $record], JSON_THROW_ON_ERROR), $this->ownedTicketKey());
    }

    private function ownedViewerTicket(string $reference): array
    {
        $this->assertTicketStore();
        if (preg_match('/\Aviewer_[a-f0-9]{48}\z/D', $reference) !== 1) {
            throw new LogicException('authorization_changed');
        }
        $value = $this->tickets->get('ai-public-core:viewer:'.$reference);
        if (!is_array($value) || count($value) !== 2 || !is_array($value['record'] ?? null)
            || !is_string($value['mac'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $record = $value['record'];
        if (count($record) !== 4 || array_diff(array_keys($record), ['actorId', 'organizationId', 'originIp', 'expiresAt']) !== []
            || !is_int($record['actorId'] ?? null) || $record['actorId'] <= 0
            || !is_int($record['organizationId'] ?? null) || $record['organizationId'] <= 0
            || !is_string($record['originIp'] ?? null) || filter_var($record['originIp'], FILTER_VALIDATE_IP) === false
            || !is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= time()) {
            throw new LogicException('authorization_changed');
        }
        if (!hash_equals($this->ticketMac($reference, $record), $value['mac'])) {
            throw new LogicException('authorization_changed');
        }

        return $record;
    }

    private function assertTicketStore(): void
    {
        if ($this->tickets === null || $this->ticketKey === null || strlen($this->ticketKey) < 32) {
            throw new LogicException('authorization_changed');
        }
    }

    private function ticketMac(string $reference, array $record): string
    {
        $this->assertTicketStore();

        return hash_hmac('sha256', json_encode([$reference, $record], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->ownedTicketKey());
    }

    private function ownedTicketKey(): string
    {
        $this->assertTicketStore();

        return $this->ticketKey ?? throw new LogicException('authorization_changed');
    }

    public function inspectSourceCandidate(User $actor, int $organizationId, Request $origin, Closure $inspect): mixed
    {
        $scope = $this->startSourceScope($actor, $organizationId, $origin);
        try {
            $result = $inspect($scope['snapshot']);
            $this->assertSourceScopeCurrent($scope);

            return $result;
        } catch (Throwable $error) {
            throw new LogicException('authorization_changed', 0, $error);
        } finally {
            $this->connection->rollBack();
        }
    }

    private function startSourceScope(User $actor, int $organizationId, Request $origin): array
    {
        if ($this->connection === null || $this->logging === null || $organizationId <= 0
            || $actor->id <= 0 || filter_var($origin->ip(), FILTER_VALIDATE_IP) === false) {
            throw new LogicException('authorization_changed');
        }
        $connection = $this->connection;
        $this->assertDatasource($connection);
        $generation = $this->policyGeneration();
        $policy = $this->freshPolicy($this->logging);
        $started = hrtime(true);
        $acquireDeadline = $started + self::ACQUIRE_MS * 1000000;
        $guardDeadline = $started + self::GUARD_MS * 1000000;
        $connection->beginTransaction();
        try {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->setAcquireBudget($connection, $acquireDeadline);
            $connection->statement('LOCK TABLE public.authorization_contexts IN SHARE MODE');
            $this->assertTopology($connection);
            $state = $this->lockReadSet($connection, $actor->id, $organizationId, $acquireDeadline);
            $freshActor = User::query()->find($actor->id);
            if (!$freshActor instanceof User || $generation !== $this->policyGeneration()) {
                throw new LogicException('authorization_changed');
            }
            $wallExpiry = $this->predicateExpiry($state);
            $remainingMs = min($this->remainingMs($guardDeadline, hrtime(true)), (int) floor(($wallExpiry - microtime(true)) * 1000));
            if ($remainingMs <= 0 || $this->remainingMs($acquireDeadline, hrtime(true)) <= 0) {
                throw new LogicException('authorization_changed');
            }
            $connection->selectOne("SELECT set_config('statement_timeout', ?, true)", [(string) $remainingMs], false);
            $allowed = $policy->withCurrentChecks($freshActor, $organizationId,
                fn (): bool => $policy->canReadDomain($freshActor, $organizationId, 'assistant'), true);
            if (!$allowed || $generation !== $this->policyGeneration()
                || hrtime(true) >= $guardDeadline || microtime(true) >= $wallExpiry) {
                throw new LogicException('authorization_changed');
            }
            $snapshot = ['actorId' => $freshActor->id, 'organizationId' => $organizationId,
                'policyGeneration' => $generation,
                'authorizationRevision' => hash('sha256', json_encode([$state, $origin->ip()], JSON_THROW_ON_ERROR)),
                'remainingMs' => min($this->remainingMs($guardDeadline, hrtime(true)),
                    (int) floor(($wallExpiry - microtime(true)) * 1000)), 'runtimeQualified' => false];

            return ['snapshot' => $snapshot, 'guardDeadline' => $guardDeadline, 'wallExpiry' => $wallExpiry];
        } catch (Throwable $error) {
            $connection->rollBack();
            throw new LogicException('authorization_changed', 0, $error);
        }
    }

    public function registerSourceAttempt(array $binding, int $expiresAt): void
    {
        $this->assertSourceTuple($binding);
        $ticket = $this->ownedViewerTicket($binding['viewerTicketRef']);
        if ($expiresAt <= time() || $expiresAt > $ticket['expiresAt']) { throw new LogicException('expired'); }
        $record = ['binding' => $binding, 'expiresAt' => $expiresAt];
        $key = 'ai-public-core:source-attempt:'.hash('sha256', $binding['requestRef'].':'.$binding['attemptRef']);
        if (!$this->tickets->add($key, ['record' => $record, 'mac' => $this->sourceAttemptMac($record)], $expiresAt - time())) {
            throw new LogicException('authorization_changed');
        }
    }

    public function acquireSourceGuard(array $frame, PublicCoreContextBindings $port): array
    {
        if ($this->sourceHeld !== null) { throw new LogicException('authorization_changed'); }
        $binding = $frame['payload']['binding'] ?? null;
        if (!is_array($binding)) { throw new LogicException('authorization_changed'); }
        $attempt = $this->ownedSourceAttempt($binding);
        $payload = $port->sourceOnly() ? $port->consumeSourceFrame($frame, 'authorize_write', $binding, $attempt['expiresAt'])
            : $port->consumeNativeControlFrame($frame, 'authorize_write', $binding, $attempt['expiresAt']);
        if (count($payload) !== 2 || array_diff(array_keys($payload), ['schemaVersion', 'binding']) !== []
            || ($payload['schemaVersion'] ?? null) !== 'public-core-app-upload-acquire/1' || $payload['binding'] !== $binding) {
            throw new LogicException('authorization_changed');
        }
        $ticket = $this->ownedViewerTicket($binding['viewerTicketRef']);
        $actor = User::query()->find($ticket['actorId']);
        if (!$actor instanceof User) { throw new LogicException('authorization_changed'); }
        $origin = Request::create('/public-core/source-held', 'GET', [], [], [], ['REMOTE_ADDR' => $ticket['originIp']]);
        $scope = $this->startSourceScope($actor, $ticket['organizationId'], $origin);
        try {
            $this->assertSourceScopeCurrent($scope);
            $guard = 'guard_'.bin2hex(random_bytes(24));
            $coverage = 'coverage_'.hash_hmac('sha256', json_encode([$binding, $guard, $scope['snapshot']], JSON_THROW_ON_ERROR),
                $this->ownedTicketKey());
            $viewer = $this->sourceCurrentViewer($scope['snapshot']);
            $remaining = min($this->remainingMs($scope['guardDeadline'], hrtime(true)),
                (int) floor(($scope['wallExpiry'] - microtime(true)) * 1000), max(0, ($attempt['expiresAt'] - time()) * 1000));
            if ($remaining <= 0) { throw new LogicException('expired'); }
            $consumedKey = 'ai-public-core:source-consumed:'.hash('sha256', $binding['requestRef'].':'.$binding['attemptRef']);
            if (!$this->tickets->add($consumedKey, $guard, $attempt['expiresAt'] - time())) {
                throw new LogicException('authorization_changed');
            }
            $remaining = min($remaining, $this->remainingMs($scope['guardDeadline'], hrtime(true)),
                (int) floor(($scope['wallExpiry'] - microtime(true)) * 1000));
            if ($remaining <= 0) { throw new LogicException('expired'); }
            $this->sourceHeld = ['scope' => $scope, 'binding' => $binding, 'guardRef' => $guard,
                'expiresAt' => $attempt['expiresAt'], 'port' => $port];

            return ['schemaVersion' => 'public-core-app-upload-grant/1', 'binding' => $binding,
                'currentViewer' => $viewer, 'guardRef' => $guard, 'coverageEvidenceRef' => $coverage,
                'uploadTimeoutMs' => $remaining];
        } catch (Throwable $error) {
            $this->connection->rollBack();
            throw new LogicException('authorization_changed', 0, $error);
        }
    }

    public function checkSourceBinding(array $frame, PublicCoreContextBindings $port): array
    {
        $binding = $frame['payload']['binding'] ?? null;
        if (!is_array($binding)) { throw new LogicException('authorization_changed'); }
        $attempt = $this->ownedSourceAttempt($binding);
        $payload = $port->sourceOnly() ? $port->consumeSourceFrame($frame, 'check_binding', $binding, $attempt['expiresAt'])
            : $port->consumeNativeControlFrame($frame, 'check_binding', $binding, $attempt['expiresAt']);
        if (count($payload) !== 2 || array_diff(array_keys($payload), ['schemaVersion', 'binding']) !== []
            || ($payload['schemaVersion'] ?? null) !== 'public-core-app-viewer-check/1' || $payload['binding'] !== $binding) {
            throw new LogicException('authorization_changed');
        }
        $ticket = $this->ownedViewerTicket($binding['viewerTicketRef']);
        if ($this->sourceHeld !== null) {
            if ($this->sourceHeld['port'] !== $port || $this->sourceHeld['binding'] !== $binding
                || $this->sourceHeld['expiresAt'] !== $attempt['expiresAt']
                || $this->sourceHeld['scope']['snapshot']['actorId'] !== $ticket['actorId']
                || $this->sourceHeld['scope']['snapshot']['organizationId'] !== $ticket['organizationId']) {
                throw new LogicException('authorization_changed');
            }
            $this->assertSourceScopeCurrent($this->sourceHeld['scope']);
            $viewer = $this->sourceCurrentViewer($this->sourceHeld['scope']['snapshot']);
        } else {
            $viewer = $this->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                'viewerTicketRef' => $binding['viewerTicketRef']], $attempt['expiresAt'])['currentViewer'];
        }

        return ['schemaVersion' => 'public-core-app-viewer-binding/1', 'binding' => $binding, 'currentViewer' => $viewer];
    }

    public function cancelSourceGuard(string $reasonCode): array
    {
        if ($this->sourceHeld === null || $reasonCode === 'none'
            || !in_array($reasonCode, \App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource::REASONS, true)) {
            throw new LogicException('authorization_changed');
        }

        return ['schemaVersion' => 'public-core-app-upload-cancel-request/1', 'binding' => $this->sourceHeld['binding'],
            'guardRef' => $this->sourceHeld['guardRef'], 'reasonCode' => $reasonCode];
    }

    public function releaseSourceGuard(array $frame, PublicCoreContextBindings $port): array
    {
        $held = $this->sourceHeld;
        if ($held === null || $held['port'] !== $port) {
            throw new LogicException('authorization_changed');
        }
        if (!$port->sourceOnly()) {
            $payload = $port->consumeNativeControlFrame($frame, 'upload_complete', $held['binding'], $held['expiresAt']);
            if (!$port->nativeTerminalProof($payload, $held['binding'], $held['guardRef'])) { throw new LogicException('receipt_unavailable'); }
            $this->connection->rollBack();
            $this->sourceHeld = null;
            return ['schemaVersion' => 'public-core-app-upload-released/1', 'binding' => $held['binding'], 'guardRef' => $held['guardRef']];
        }
        $payload = $port->consumeSourceReleaseFrame($frame, $held['binding'], $held['expiresAt']);
        if (count($payload) !== 4 || array_diff(array_keys($payload), ['schemaVersion', 'binding', 'guardRef', 'completionRef']) !== []
            || ($payload['schemaVersion'] ?? null) !== 'public-core-app-upload-release/1'
            || ($payload['binding'] ?? null) !== $held['binding'] || ($payload['guardRef'] ?? null) !== $held['guardRef']
            || !\App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource::opaqueRef($payload['completionRef'] ?? null)) {
            throw new LogicException('receipt_unavailable');
        }
        $proof = $port->sourceCompletion($payload['completionRef']);
        if ($proof === null || count($proof) !== 4 || array_diff(array_keys($proof), ['binding', 'guardRef', 'completionRef', 'terminal']) !== []
            || ($proof['binding'] ?? null) !== $held['binding'] || ($proof['guardRef'] ?? null) !== $held['guardRef']
            || ($proof['completionRef'] ?? null) !== $payload['completionRef']
            || !in_array($proof['terminal'] ?? null, ['uploaded', 'stopped'], true)) {
            throw new LogicException('receipt_unavailable');
        }
        $this->connection->rollBack();
        $this->sourceHeld = null;

        return ['schemaVersion' => 'public-core-app-upload-released/1', 'binding' => $held['binding'], 'guardRef' => $held['guardRef']];
    }

    private function sourceCurrentViewer(array $candidate): array
    {
        return ['authorized' => true, 'viewerRef' => 'actor_'.hash_hmac('sha256', (string) $candidate['actorId'], $this->ownedTicketKey()),
            'organizationRef' => 'organization_'.hash_hmac('sha256', (string) $candidate['organizationId'], $this->ownedTicketKey()),
            'authorizationRevision' => $candidate['authorizationRevision'], 'policyRevision' => $candidate['policyGeneration']];
    }

    private function assertSourceScopeCurrent(array $scope): void
    {
        if ($scope['snapshot']['policyGeneration'] !== $this->policyGeneration() || hrtime(true) >= $scope['guardDeadline']
            || microtime(true) >= $scope['wallExpiry']) {
            throw new LogicException('authorization_changed');
        }
    }

    private function ownedSourceAttempt(array $binding): array
    {
        $this->assertSourceTuple($binding);
        $this->assertTicketStore();
        $value = $this->tickets->get('ai-public-core:source-attempt:'.hash('sha256', $binding['requestRef'].':'.$binding['attemptRef']));
        if (!is_array($value) || count($value) !== 2 || !is_array($value['record'] ?? null) || !is_string($value['mac'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $record = $value['record'];
        if (count($record) !== 2 || ($record['binding'] ?? null) !== $binding || !is_int($record['expiresAt'] ?? null)
            || $record['expiresAt'] <= time() || !hash_equals($this->sourceAttemptMac($record), $value['mac'])) {
            throw new LogicException('authorization_changed');
        }

        return $record;
    }

    private function sourceAttemptMac(array $record): string
    {
        return hash_hmac('sha256', json_encode(['ai-public-core-source-attempt', $record], JSON_THROW_ON_ERROR), $this->ownedTicketKey());
    }

    private function assertSourceTuple(array $binding): void
    {
        if (count($binding) !== 7 || array_diff(array_keys($binding), self::TUPLE_KEYS) !== []) {
            throw new LogicException('authorization_changed');
        }
        foreach (['viewerTicketRef', 'requestRef', 'attemptRef'] as $key) {
            if (!\App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource::opaqueRef($binding[$key])) {
                throw new LogicException('authorization_changed');
            }
        }
        foreach (['projectionDigest', 'profileFingerprint', 'registryDigest'] as $key) {
            if (!is_string($binding[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $binding[$key]) !== 1) {
                throw new LogicException('authorization_changed');
            }
        }
        if (!is_string($binding['manifestGenerationRef']) || strlen($binding['manifestGenerationRef']) > 160
            || preg_match('/\A[A-Za-z0-9._\/:\-]+\z/D', $binding['manifestGenerationRef']) !== 1) {
            throw new LogicException('authorization_changed');
        }
    }

    private function freshPolicy(LoggingService $logging): AssistantDataAccessPolicy
    {
        $scanner = new class extends RoleScanner {
            public function getRole(string $slug): ?array
            {
                return $this->getRoleUncached($slug);
            }
        };
        $entitlements = new OrganizationEntitlementService(new PackageCatalogService());
        $resolver = (new PermissionResolver($scanner, new ModulePermissionChecker(new AccessController()), $logging))
            ->withCurrentEntitlementSource($entitlements);

        return new AssistantDataAccessPolicy(new AuthorizationService($scanner, $resolver, $logging),
            new UserProjectAccessService(), $entitlements);
    }

    private function assertDatasource(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0
            || $connection->getConfig('read') !== null || $connection->getConfig('write') !== null) {
            throw new LogicException('authorization_changed');
        }
        foreach ([new User(), new Organization(), new Module(), new UserRoleAssignment(), new RoleCondition(),
            new AuthorizationContext(), new OrganizationCustomRole(), new OrganizationPackageSubscription(),
            new OrganizationCommercialAccount(), new Project()] as $model) {
            if ($model->getConnection() !== $connection) {
                throw new LogicException('authorization_changed');
            }
        }
        $settings = $connection->selectOne("SELECT current_setting('server_version_num')::int AS version,
            current_setting('transaction_read_only') AS read_only, current_setting('session_replication_role') AS replica,
            current_schema() AS schema_name, pg_is_in_recovery() AS recovery", [], false);
        if ($settings === null || (int) $settings->version < 160000 || (int) $settings->version >= 170000
            || $settings->read_only !== 'off' || $settings->replica !== 'origin' || $settings->schema_name !== 'public'
            || $settings->recovery) {
            throw new LogicException('authorization_changed');
        }
    }

    private function assertTopology(Connection $connection): void
    {
        $foreignKeys = $connection->select("SELECT child.relname AS child_table, parent.relname AS parent_table,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.id ORDER BY k.n))::text AS child_columns,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.id ORDER BY k.n))::text AS parent_columns,
            c.convalidated AND NOT c.condeferrable AND NOT c.condeferred
                AND child.relkind = 'r' AND parent.relkind = 'r'
                AND NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgconstraint = c.oid AND t.tgenabled NOT IN ('O', 'A')) AS valid
            FROM pg_constraint c JOIN pg_class child ON child.oid = c.conrelid
            JOIN pg_class parent ON parent.oid = c.confrelid
            JOIN pg_namespace ns ON ns.oid = child.relnamespace
            JOIN pg_namespace pns ON pns.oid = parent.relnamespace
            WHERE c.contype = 'f' AND ns.nspname = 'public' AND pns.nspname = 'public'
                AND child.relname IN ('organization_user', 'user_role_assignments', 'role_conditions',
                    'organization_custom_roles', 'project_user', 'organization_commercial_accounts',
                    'organization_package_subscriptions')", [], false);
        $required = [
            ['organization_user', ['user_id'], 'users', ['id']],
            ['organization_user', ['organization_id'], 'organizations', ['id']],
            ['user_role_assignments', ['user_id'], 'users', ['id']],
            ['user_role_assignments', ['context_id'], 'authorization_contexts', ['id']],
            ['role_conditions', ['assignment_id'], 'user_role_assignments', ['id']],
            ['organization_custom_roles', ['organization_id'], 'organizations', ['id']],
            ['project_user', ['user_id'], 'users', ['id']],
            ['project_user', ['project_id'], 'projects', ['id']],
            ['organization_commercial_accounts', ['organization_id'], 'organizations', ['id']],
            ['organization_package_subscriptions', ['organization_id'], 'organizations', ['id']],
            ['organization_package_subscriptions', ['commercial_account_id', 'organization_id'],
                'organization_commercial_accounts', ['id', 'organization_id']],
        ];
        foreach ($required as [$child, $childColumns, $parent, $parentColumns]) {
            $matches = array_filter($foreignKeys, static fn (object $fk): bool => $fk->child_table === $child
                && $fk->parent_table === $parent && $fk->valid
                && json_decode($fk->child_columns, true, 8, JSON_THROW_ON_ERROR) === $childColumns
                && json_decode($fk->parent_columns, true, 8, JSON_THROW_ON_ERROR) === $parentColumns);
            if (count($matches) !== 1) {
                throw new LogicException('authorization_changed');
            }
        }
        $indexes = $connection->select("SELECT t.relname AS table_name,
            array_to_json(ARRAY(SELECT a.attname FROM unnest(i.indkey::smallint[]) WITH ORDINALITY k(id, n)
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.id
                WHERE k.n <= i.indnkeyatts ORDER BY k.n))::text AS columns
            FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace
            WHERE ns.nspname = 'public' AND i.indisunique AND i.indisvalid AND i.indisready
                AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indimmediate
                AND t.relname IN ('users', 'organizations', 'modules', 'organization_user', 'organization_custom_roles',
                    'user_role_assignments', 'project_user', 'organization_commercial_accounts', 'organization_package_subscriptions')", [], false);
        foreach (['users' => ['id'], 'organizations' => ['id'], 'modules' => ['slug'],
            'organization_user' => ['user_id', 'organization_id'],
            'organization_custom_roles' => ['organization_id', 'slug'],
            'user_role_assignments' => ['user_id', 'role_slug', 'context_id'],
            'project_user' => ['project_id', 'user_id'],
            'organization_commercial_accounts' => ['organization_id'],
            'organization_package_subscriptions' => ['organization_id', 'package_slug']] as $table => $columns) {
            sort($columns);
            $matches = array_filter($indexes, static function (object $index) use ($table, $columns): bool {
                $actual = json_decode($index->columns, true, 8, JSON_THROW_ON_ERROR);
                sort($actual);

                return $index->table_name === $table && $actual === $columns;
            });
            if ($matches === []) {
                throw new LogicException('authorization_changed');
            }
        }
    }

    private function lockReadSet(Connection $connection, int $actorId, int $organizationId, int $deadline): array
    {
        $this->setAcquireBudget($connection, $deadline);
        $users = $connection->table('users')->where('id', $actorId)->lockForUpdate()->get();
        $organization = $connection->table('organizations')->where('id', $organizationId)->first();
        if ($users->count() !== 1 || $organization === null) {
            throw new LogicException('authorization_changed');
        }
        $organizationIds = array_values(array_unique(array_filter([$organizationId, $organization->parent_organization_id])));
        $this->setAcquireBudget($connection, $deadline);
        $organizations = $connection->table('organizations')->whereIn('id', $organizationIds)->orderBy('id')->lockForUpdate()->get();
        if ($organizations->count() !== count($organizationIds)
            || $organizations->firstWhere('id', $organizationId)->parent_organization_id !== $organization->parent_organization_id) {
            throw new LogicException('authorization_changed');
        }
        $this->setAcquireBudget($connection, $deadline);
        $memberships = $connection->table('organization_user')->where('user_id', $actorId)->orderBy('organization_id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $assignments = $connection->table('user_role_assignments')->where('user_id', $actorId)->orderBy('id')->lockForUpdate()->get();
        $contexts = $connection->table('authorization_contexts')->whereIn('id', $assignments->pluck('context_id'))
            ->orWhere(static fn ($query) => $query->where('type', 'organization')->whereIn('resource_id', $organizationIds))->get()->keyBy('id');
        foreach ($organizationIds as $id) {
            if ($contexts->where('type', 'organization')->where('resource_id', $id)->count() > 1) {
                throw new LogicException('authorization_changed');
            }
        }
        foreach ($contexts->keys()->all() as $contextId) {
            $visited = [];
            while ($contextId !== null) {
                if (isset($visited[$contextId]) || count($visited) >= 64) {
                    throw new LogicException('authorization_changed');
                }
                $visited[$contextId] = true;
                $context = $contexts->get($contextId);
                if ($context === null) {
                    $context = $connection->table('authorization_contexts')->where('id', $contextId)->first();
                    if ($context === null) { throw new LogicException('authorization_changed'); }
                    $contexts->put($contextId, $context);
                }
                if ($context->type === 'organization' && !in_array((int) $context->resource_id, $organizationIds, true)) {
                    throw new LogicException('authorization_changed');
                }
                $contextId = $context->parent_context_id;
            }
        }
        foreach ($assignments as $assignment) {
            $context = $contexts->get($assignment->context_id);
            if ($context === null || !in_array($context->type, ['organization', 'project'], true)
                || ($context->type === 'project' && $contexts->get($context->parent_context_id)?->type !== 'organization')) {
                throw new LogicException('authorization_changed');
            }
        }
        $this->setAcquireBudget($connection, $deadline);
        $conditions = $connection->table('role_conditions')->whereIn('assignment_id', $assignments->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        if ($conditions->where('is_active', true)->contains('condition_type', 'location')) {
            throw new LogicException('authorization_changed');
        }
        $this->setAcquireBudget($connection, $deadline);
        $customRoles = $connection->table('organization_custom_roles')->whereIn('organization_id', $organizationIds)->orderBy('id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $module = $connection->table('modules')->where('slug', 'ai-assistant')->lockForUpdate()->get();
        if ($module->count() !== 1) { throw new LogicException('authorization_changed'); }
        $this->setAcquireBudget($connection, $deadline);
        $subscriptions = $connection->table('organization_package_subscriptions')->whereIn('organization_id', $organizationIds)
            ->orderBy('id')->lockForUpdate()->get();
        $this->setAcquireBudget($connection, $deadline);
        $accounts = $connection->table('organization_commercial_accounts')->whereIn('organization_id', $organizationIds)
            ->orderBy('id')->lockForUpdate()->get();
        $links = collect();
        $projects = collect();
        if ($conditions->where('is_active', true)->contains('condition_type', 'project_count')) {
            $this->setAcquireBudget($connection, $deadline);
            $links = $connection->table('project_user')->where('user_id', $actorId)->orderBy('project_id')->lockForUpdate()->get();
            $this->setAcquireBudget($connection, $deadline);
            $projects = $connection->table('projects')->whereIn('id', $links->pluck('project_id'))->orderBy('id')->lockForUpdate()->get();
        }

        $contexts = $contexts->sortKeys();

        return compact('users', 'organizations', 'memberships', 'contexts', 'assignments', 'conditions',
            'customRoles', 'module', 'subscriptions', 'accounts', 'links', 'projects');
    }

    private function predicateExpiry(array $state): float
    {
        $now = microtime(true);
        $expiry = $now + self::GUARD_MS / 1000;
        foreach (['assignments' => ['expires_at'], 'subscriptions' => ['trial_ends_at', 'current_period_end_at'],
            'accounts' => ['grace_ends_at']] as $key => $columns) {
            foreach ($state[$key] as $row) {
                foreach ($columns as $column) {
                    if ($row->{$column} !== null) {
                        $boundary = (float) CarbonImmutable::parse($row->{$column})->format('U.u');
                        if ($boundary > $now) { $expiry = min($expiry, $boundary); }
                        if ($key === 'subscriptions' && $column === 'current_period_end_at') {
                            $renewalBoundary = $boundary + max(0, (int) config('commercial_offers.renewal_processing_window_minutes', 5)) * 60;
                            if ($renewalBoundary > $now) { $expiry = min($expiry, $renewalBoundary); }
                        }
                    }
                }
            }
        }
        if ($state['conditions']->where('is_active', true)->contains('condition_type', 'time')) {
            $expiry = min($expiry, floor($now) + 1);
            foreach ($state['conditions']->where('is_active', true)->where('condition_type', 'time') as $condition) {
                $data = json_decode($condition->condition_data, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($data)) { throw new LogicException('authorization_changed'); }
                foreach (['valid_from', 'valid_until'] as $key) {
                    if (isset($data[$key])) {
                        $boundary = (float) CarbonImmutable::parse($data[$key])->format('U.u');
                        if ($boundary > $now) { $expiry = min($expiry, $boundary); }
                    }
                }
            }
        }

        return $expiry;
    }

    private function policyGeneration(): string
    {
        $files = array_merge(glob(config_path('RoleDefinitions/*/*.json')) ?: [],
            glob(config_path('Packages/*.json')) ?: [], glob(config_path('ModuleList/*.json')) ?: []);
        foreach ([self::class, AssistantDataAccessPolicy::class, AuthorizationService::class, PermissionResolver::class,
            RoleScanner::class, RoleCondition::class, UserRoleAssignment::class, AuthorizationContext::class,
            OrganizationCustomRole::class, OrganizationEntitlementService::class, PackageCatalogService::class,
            \App\Domain\Authorization\Services\RolePermissionNormalizer::class,
            \App\Domain\Authorization\ValueObjects\ModulePermissionAliases::class,
            \App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog::class,
            \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::class,
            User::class, Organization::class, OrganizationPackageSubscription::class, OrganizationCommercialAccount::class,
            Module::class, Project::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file === false) { throw new LogicException('authorization_changed'); }
            $files[] = $file;
        }
        sort($files);
        $hashes = [];
        foreach ($files as $file) {
            $digest = hash_file('sha256', $file);
            if ($digest === false) { throw new LogicException('authorization_changed'); }
            $hashes[$file] = $digest;
        }

        return hash('sha256', json_encode([$hashes, config('module_packages'), config('commercial_offers.renewal_processing_window_minutes', 5),
            config('app.timezone'), date_default_timezone_get()], JSON_THROW_ON_ERROR));
    }

    private function setAcquireBudget(Connection $connection, int $deadline): void
    {
        $remaining = $this->remainingMs($deadline, hrtime(true));
        if ($remaining <= 0) { throw new LogicException('authorization_changed'); }
        $connection->selectOne("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)",
            [(string) $remaining, (string) $remaining], false);
    }

    private function remainingMs(int $deadline, int $sampledNs): int
    {
        return (int) floor(($deadline - $sampledNs) / 1000000);
    }
}
