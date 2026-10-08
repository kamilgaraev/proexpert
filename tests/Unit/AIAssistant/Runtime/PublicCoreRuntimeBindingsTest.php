<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecutePublicCoreTestJob;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreGatewayModelDriver;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\Models\User;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\Contracts\GatewayModelTransport;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender;
use App\Services\Privacy\Gateway\GatewayPublicCoreTransport;
use App\Services\Privacy\PublicCore\PublicCoreProcessor;
use App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness;
use App\Services\Privacy\PublicCore\PublicCoreReceiptStore;
use App\Services\Privacy\PublicCore\PublicCoreSessionAuthority;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use LogicException;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\Loop\OfflineLoopFixtures;
use Tests\Unit\Privacy\PublicCore\PublicCoreAuthorityTest;

final class PublicCoreRuntimeBindingsTest extends TestCase
{
    protected function setUp(): void
    {
        self::createIsolatedApplication();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    public static function createIsolatedApplication(): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'logging' => ['default' => 'public-core-test', 'channels' => [
                'public-core-test' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
            ]],
        ]));
        $translator = new Translator(new FileLoader(new Filesystem(), dirname(__DIR__, 4).'/lang'), 'ru');
        $app->instance('translator', $translator);
        $validator = new Factory($translator, $app);
        $app->instance('validator', $validator);
        $app->instance(ValidationFactoryContract::class, $validator);
        $app->instance('request', Request::create('/'));
        $app->bind('db', static function (): never { throw new LogicException('test_database_forbidden'); });
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(static fn ($data = [], $status = 200, $headers = [], $options = 0): JsonResponse =>
            new JsonResponse($data, $status, $headers, $options));
        $app->instance(ResponseFactory::class, $responses);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    public function testNativeTerminalCustodyKeepsKernelChannelTransferAndProjectionTuple(): void
    {
        $binding = ['requestRef' => 'ref_'.str_repeat('a', 32), 'attemptRef' => 'ref_'.str_repeat('b', 32), 'projectionDigest' => str_repeat('c', 64)];
        $peer = ['pid' => 123, 'uid' => 1001, 'gid' => 1001];
        $pending = ['schemaVersion' => 'public-core-native-upload-custody/1', 'qualification' => 'actual-native',
            'channelRef' => 'ref_'.str_repeat('d', 32), 'gatewayPeer' => $peer, 'transferRef' => 'ref_'.str_repeat('e', 32),
            'requestRef' => $binding['requestRef'], 'attemptRef' => $binding['attemptRef'], 'projectionDigest' => $binding['projectionDigest'],
            'event' => 'pending', 'eventSequence' => null, 'completionRef' => null];
        $completion = 'ref_'.str_repeat('f', 32);
        self::assertTrue(PublicCoreContextBindings::nativeCustodyMatches($pending, $binding, $peer));
        foreach (['uploaded', 'stopped'] as $event) {
            $terminal = array_replace($pending, ['event' => $event, 'eventSequence' => 4, 'completionRef' => $completion]);
            self::assertTrue(PublicCoreContextBindings::nativeCustodyMatches($terminal, $binding, $peer, $pending, $completion));
            $changes = ['schemaVersion' => 'legacy', 'qualification' => 'local-source-test', 'channelRef' => 'ref_'.str_repeat('1', 32),
                'transferRef' => 'ref_'.str_repeat('2', 32), 'requestRef' => 'ref_'.str_repeat('3', 32), 'attemptRef' => 'ref_'.str_repeat('4', 32),
                'projectionDigest' => str_repeat('5', 64), 'gatewayPeer' => ['pid' => 124, 'uid' => 1001, 'gid' => 1001],
                'event' => 'pending', 'eventSequence' => null, 'completionRef' => 'ref_'.str_repeat('6', 32)];
            foreach ($changes as $key => $value) {
                self::assertFalse(PublicCoreContextBindings::nativeCustodyMatches(array_replace($terminal, [$key => $value]), $binding, $peer, $pending, $completion), $key);
                $missing = $terminal; unset($missing[$key]);
                self::assertFalse(PublicCoreContextBindings::nativeCustodyMatches($missing, $binding, $peer, $pending, $completion), 'missing '.$key);
            }
            foreach ([1, 1025, '4', -1] as $sequence) {
                self::assertFalse(PublicCoreContextBindings::nativeCustodyMatches(array_replace($terminal, ['eventSequence' => $sequence]), $binding, $peer, $pending, $completion));
            }
            self::assertFalse(PublicCoreContextBindings::nativeCustodyMatches($terminal + ['manualProof' => true], $binding, $peer, $pending, $completion));
            self::assertFalse(PublicCoreContextBindings::nativeCustodyMatches($terminal, $binding, $peer, array_replace($pending, ['event' => 'uploaded']), $completion));
        }
    }

    public function testNativeGuardConsumerCannotTurnSourceOnlyReaderIntoTerminalProof(): void
    {
        $binding = ['requestRef' => 'ref_'.str_repeat('a', 32), 'attemptRef' => 'ref_'.str_repeat('b', 32)];
        $port = PublicCoreContextBindings::sourceAppControlPort('ref_'.str_repeat('c', 32), 'Processor',
            static fn (): array => ['terminal' => 'uploaded']);
        self::assertTrue($port->sourceOnly());
        self::assertFalse($port->nativeTerminalProof([], $binding, 'ref_'.str_repeat('d', 32)));
        self::assertNull($port->nativePublicationResponse([], new PublicCoreBackendAuthorityFence()));
        $this->expectExceptionMessage('authorization_changed');
        $port->consumeNativeControlFrame([], 'authorize_write', $binding, time() + 60);
    }

    public function testNativeProcessorSourceFactoryRemainsClosedWithoutProtectedReadersAndActiveOperation(): void
    {
        $called = 0;
        $deny = static function () use (&$called): never { $called++; throw new LogicException('unconfigured source'); };
        $processor = \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRuntimeComposition::nativeProcessor(
            new PublicCoreReceiptStore(), new PublicCoreRuntimeReadiness(RegisteredPublicFixtureRegistry::compiled()),
            $deny, $deny, $deny, $deny);
        self::assertNull($processor->normalDispatchExpiry());
        self::assertSame('runtime_not_activated', $processor->executeOwned('ref_'.str_repeat('a', 32))['reasonCode']);
        self::assertSame(0, $called);
        $this->expectExceptionMessage('receipt_changed');
        $processor->exchangeNormalControl('upload_complete', ['terminal' => 'uploaded'], null, time() + 20);
    }

    public function testSignedOwnedRequestCacheRechecksWorkerViewerUuidTenantAndMac(): void
    {
        $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());
        $fence = new class($cache) extends PublicCoreBackendAuthorityFence {
            public bool $revoked = false;
            public int $freshChecks = 0;
            public function __construct(\Illuminate\Contracts\Cache\Repository $cache) { parent::__construct(null, null, $cache, str_repeat('fixture-control-key', 3)); }
            public function inspectSourceCandidate(User $actor, int $organizationId, Request $origin, Closure $inspect): mixed { return $inspect([]); }
            public function viewerTicketBinding(array $payload, int $frameExpiresAt): array {
                $this->freshChecks++;
                if ($this->revoked) { throw new LogicException('authorization_changed'); }
                return ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1', 'viewerTicketRef' => $payload['viewerTicketRef'],
                    'currentViewer' => ['authorized' => true, 'viewerRef' => 'fixture-only-actor', 'organizationRef' => 'fixture-only-tenant',
                        'authorizationRevision' => 'fixture-auth/1', 'policyRevision' => 'fixture-policy/1']];
            }
        };
        $viewer = new User(); $viewer->setRawAttributes(['id' => 7]);
        $foreign = new User(); $foreign->setRawAttributes(['id' => 8]);
        $selection = self::command() + ['public_session_ref' => null];
        $ticket = $fence->issueViewerTicket($viewer, 11, Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']), time() + 150);
        $opened = ['status' => 'accepted', 'request_ref' => 'ref_'.str_repeat('a', 32), 'public_session_ref' => 'ref_'.str_repeat('b', 32),
            'process_ref' => 'ref_'.str_repeat('c', 32), 'original_expires_at' => time() + 60];
        $row = $fence->rememberOwnedRequest($ticket, $selection, $opened);
        self::assertSame($row, $fence->findOwnedSelection($viewer, 11, $selection));
        self::assertSame($row, $fence->ownedRequest($row['requestRef']));
        self::assertSame($row, $fence->ownedRequest($row['requestRef'], $viewer, 11));
        self::assertGreaterThanOrEqual(4, $fence->freshChecks);
        foreach ([[$foreign, 11], [$viewer, 12]] as [$user, $tenant]) {
            try { $fence->ownedRequest($row['requestRef'], $user, $tenant); self::fail('Owner/tenant mismatch must deny'); }
            catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
        }
        try { $fence->findOwnedSelection($viewer, 11, array_replace($selection, ['input_id' => 'different-input'])); self::fail('UUID cannot change selectors'); }
        catch (LogicException $error) { self::assertSame('receipt_changed', $error->getMessage()); }
        $fence->revoked = true;
        try { $fence->ownedRequest($row['requestRef']); self::fail('Worker must recheck current revocation'); }
        catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
        $fence->revoked = false;
        $key = 'ai-public-core:owned-request:'.$row['requestRef'];
        $original = $cache->get($key);
        foreach (['actorId' => 8, 'organizationId' => 12, 'expiresAt' => time() + 600, 'process_ref' => 'ref_'.str_repeat('d', 32)] as $field => $value) {
            $changed = $original; $changed['record'][$field] = $value; $cache->put($key, $changed, 60);
            try { $fence->ownedRequest($row['requestRef']); self::fail('Unsigned mutation cannot grant ownership'); }
            catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
        }
        $cache->put($key, $original, 60);
        $cache->forget('ai-public-core:viewer:'.$ticket);
        $this->expectExceptionMessage('authorization_changed');
        $fence->ownedRequest($row['requestRef']);
    }

    public function testAppViewerBootstrapFrameRequiresOwnedChannelSequencePhaseAndRole(): void
    {
        $reference = 'viewer_'.str_repeat('a', 48);
        $channel = 'channel_'.str_repeat('b', 32);
        $expiry = time() + 60;
        $frame = ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $channel, 'sequence' => 2,
            'command' => 'check_binding', 'requestRef' => null, 'attemptRef' => null, 'expiresAt' => $expiry,
            'payload' => ['schemaVersion' => 'public-core-app-viewer-ticket-check/1', 'viewerTicketRef' => $reference]];
        self::assertSame($frame['payload'], PublicCoreContextBindings::appViewerBootstrapPayload($frame, $channel, 2, $expiry, 'Processor'));
        $invalid = [
            [$frame, $channel, 2, $expiry, 'Gateway'],
            [$frame, 'channel_'.str_repeat('c', 32), 2, $expiry, 'Processor'],
            [$frame, $channel, 3, $expiry, 'Processor'],
            [$frame, $channel, 2, $expiry + 1, 'Processor'],
            [array_replace($frame, ['command' => 'authorize_write']), $channel, 2, $expiry, 'Processor'],
            [array_replace($frame, ['requestRef' => 'request_'.str_repeat('d', 32)]), $channel, 2, $expiry, 'Processor'],
            [array_replace($frame, ['attemptRef' => 'attempt_'.str_repeat('e', 32)]), $channel, 2, $expiry, 'Processor'],
            [array_replace($frame, ['sequence' => 1]), $channel, 1, $expiry, 'Processor'],
            [array_replace($frame, ['payload' => ['schemaVersion' => 'public-core-app-upload-acquire/1', 'viewerTicketRef' => $reference]]),
                $channel, 2, $expiry, 'Processor'],
            [$frame + ['authorized' => true], $channel, 2, $expiry, 'Processor'],
        ];
        foreach ($invalid as [$value, $expectedChannel, $expectedSequence, $expectedExpiry, $role]) {
            try {
                PublicCoreContextBindings::appViewerBootstrapPayload($value, $expectedChannel, $expectedSequence, $expectedExpiry, $role);
                self::fail('Bootstrap cannot accept wrong channel, role, sequence or upload phase');
            } catch (LogicException $error) {
                self::assertSame('authorization_changed', $error->getMessage());
            }
        }
    }

    public function testIsolatedHarnessResolvesExplicitLoggingChannel(): void
    {
        $application = Facade::getFacadeApplication();
        self::assertInstanceOf(Application::class, $application);
        $manager = $application->make('log');
        self::assertSame('public-core-test', $manager->getDefaultDriver());
        $logger = $manager->channel();
        self::assertInstanceOf(\Illuminate\Log\Logger::class, $logger);
        $logger->warning('Public Core isolated fixture logging probe.');
    }

    public function testSourceTerminalFramePreservesOriginalLifetimeAndCannotRenewOrdinaryWrite(): void
    {
        $expiry = time() - 1;
        $binding = ['requestRef' => 'request_'.str_repeat('a', 32), 'attemptRef' => 'attempt_'.str_repeat('b', 32)];
        $port = PublicCoreContextBindings::sourceAppControlPort('channel_'.str_repeat('c', 32), 'Processor', static fn (): null => null);
        $frame = ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $port->sourceChannel(), 'sequence' => 2,
            'command' => 'upload_complete', 'requestRef' => $binding['requestRef'], 'attemptRef' => $binding['attemptRef'],
            'expiresAt' => $expiry, 'payload' => ['completionRef' => 'completion_'.str_repeat('d', 48)]];
        foreach ([array_replace($frame, ['expiresAt' => time() + 60]), array_replace($frame, ['command' => 'authorize_write']),
            array_replace($frame, ['attemptRef' => 'attempt_'.str_repeat('e', 32)]), array_replace($frame, ['sequence' => 3])] as $invalid) {
            try { $port->consumeSourceReleaseFrame($invalid, $binding, $expiry); self::fail('Cleanup must retain original frame correlation'); }
            catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
        }
        try { $port->consumeSourceFrame($frame, 'upload_complete', $binding, $expiry); self::fail('Ordinary lifetime remains expired'); }
        catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
        self::assertSame($frame['payload'], $port->consumeSourceReleaseFrame($frame, $binding, $expiry));
        self::assertNull($port->sourceCompletion($frame['payload']['completionRef']));
    }

    #[DataProvider('nativeAppBootstrapCases')]
    public function testNativeAppProcessorBootstrapUsesActualPeerAndFailsClosed(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('App–Processor bootstrap needs Linux SCM_CREDENTIALS; Windows is not native PASS.');
        }
        $directory = sys_get_temp_dir().'/assist-app-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $socketPath = $directory.'/control.sock';
        $identityFile = $directory.'/identity.json';
        $listener = AuthenticatedPublicCoreChannel::listen($socketPath);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $parent = getmypid();
        $ticket = 'viewer_'.str_repeat('a', 48);
        $wireCases = ['valid', 'canonical-identity', 'denial', 'missing-fence', 'replay'];
        $child = pcntl_fork();
        if ($child === 0) {
            socket_close($listener);
            $peer = null;
            try {
                $peer = AuthenticatedPublicCoreChannel::connect($socketPath, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 3000);
                $expiry = min(time() + 2, $peer->deadlineExpiresAt());
                if ($mode === 'wrong-phase') {
                    $peer->send('readiness', 'request:unowned-bootstrap', 'attempt:unowned-bootstrap', [], $expiry);
                } else {
                    $peer->send('check_binding', null, null,
                        ['schemaVersion' => 'public-core-app-viewer-ticket-check/1', 'viewerTicketRef' => $ticket], $expiry);
                }
                $result = $peer->receive();
                if (!in_array($mode, $wireCases, true) || $result['command'] !== 'binding'
                    || $result['requestRef'] !== null || $result['attemptRef'] !== null || $result['expiresAt'] !== $expiry
                    || $result['payload']['viewerTicketRef'] !== $ticket
                    || $result['payload']['schemaVersion'] !== (in_array($mode, ['denial', 'missing-fence'], true)
                        ? 'public-core-app-viewer-ticket-denial/1' : 'public-core-app-viewer-ticket-binding/1')) { exit(91); }
                exit(0);
            } catch (\Throwable) {
                exit(in_array($mode, $wireCases, true) ? 92 : 0);
            } finally {
                $peer?->close();
            }
        }
        self::assertGreaterThan(0, $child);
        $channel = null;
        $called = 0;
        try {
            $identity = ['schemaVersion' => 'public-core-app-processor-identity/1', 'localRole' => 'App', 'peerRole' => 'Processor',
                'localIdentityRef' => 'app:local-source', 'peerIdentityRef' => 'processor:local-source',
                'localKernel' => ['pid' => $parent, 'uid' => $uid, 'gid' => $gid],
                'peerKernel' => ['pid' => $child, 'uid' => $uid, 'gid' => $gid]];
            if ($mode === 'wrong-role') { $identity['peerRole'] = 'Gateway'; }
            if ($mode === 'wrong-peer') { $identity['peerKernel']['pid']++; }
            if ($mode === 'same-identity') { $identity['peerIdentityRef'] = $identity['localIdentityRef']; }
            if ($mode === 'extra-identity') { $identity['authorized'] = true; }
            file_put_contents($identityFile, $mode === 'canonical-identity'
                ? GatewayModelRequest::canonicalJson($identity) : json_encode($identity, JSON_THROW_ON_ERROR));
            chmod($identityFile, $mode === 'world-readable' ? 0644 : 0600);
            $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 3000);
            $binding = ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1', 'viewerTicketRef' => $ticket,
                'currentViewer' => ['authorized' => true, 'viewerRef' => 'actor:source', 'organizationRef' => 'organization:source',
                    'authorizationRevision' => 'revision:source', 'policyRevision' => 'policy:source']];
            $fence = Mockery::mock(PublicCoreBackendAuthorityFence::class);
            $fence->shouldReceive('viewerTicketBinding')->andReturnUsing(function (array $payload, int $expiresAt) use (&$called,
                $mode, $binding, $identityFile, $identity): array {
                $called++;
                self::assertSame($binding['viewerTicketRef'], $payload['viewerTicketRef']);
                self::assertGreaterThan(time(), $expiresAt);
                if ($mode === 'denial') { throw new LogicException('authorization_changed'); }
                if ($mode === 'identity-mutation') {
                    file_put_contents($identityFile, json_encode(array_replace($identity, ['peerIdentityRef' => 'processor:changed']), JSON_THROW_ON_ERROR));
                }

                return $mode === 'wrong-ticket' ? array_replace($binding, ['viewerTicketRef' => 'viewer:wrong']) : $binding;
            });
            try {
                if ($mode === 'replay') {
                    $port = PublicCoreContextBindings::authenticatedAppControlPort($channel, $identityFile);
                    self::assertFalse($port->sourceOnly());
                    $bootstrap = $port->receiveAppBootstrap();
                    self::assertNull($port->sourceCompletion('completion:caller-supplied'));
                    try {
                        $port->consumeSourceFrame([], 'authorize_write', [], $bootstrap['expiresAt']);
                        self::fail('Native bootstrap cannot be upgraded to a source-array upload guard');
                    } catch (LogicException $error) {
                        self::assertSame('authorization_changed', $error->getMessage());
                    }
                    $port->replyAppBootstrap($binding);
                    try { $port->replyAppBootstrap($binding); self::fail('Native bootstrap reply cannot be reused'); }
                    catch (LogicException $error) { self::assertSame('authorization_changed', $error->getMessage()); }
                } else {
                    (new PublicCoreAssistantRuntime($mode === 'missing-fence' ? null : $fence))
                        ->serveAppProcessorBootstrap($channel, $mode === 'missing-identity' ? $directory.'/missing.json' : $identityFile);
                }
                self::assertContains($mode, $wireCases);
            } catch (LogicException $error) {
                self::assertNotContains($mode, $wireCases, $error->getMessage().' at '.$error->getFile().':'.$error->getLine());
                self::assertContains($error->getMessage(), ['authorization_changed', 'gateway_identity_unavailable']);
            }
            self::assertSame(in_array($mode, ['valid', 'canonical-identity', 'denial', 'identity-mutation', 'wrong-ticket'], true) ? 1 : 0, $called);
        } finally {
            $channel?->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
            if (is_file($identityFile)) { unlink($identityFile); }
            unlink($socketPath);
            rmdir($directory);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public static function nativeAppBootstrapCases(): array
    {
        $cases = ['valid', 'canonical-identity', 'denial', 'missing-fence', 'replay', 'wrong-role', 'wrong-peer', 'same-identity',
            'extra-identity', 'world-readable', 'missing-identity', 'identity-mutation', 'wrong-ticket', 'wrong-phase'];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('normalSourceConsumerCases')]
    public function testNormalSourceConsumerUsesImportedProducerAndFreshActualChannelFrames(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Normal caller source tests require Linux SCM; SourceSim owner is not actual authority.');
        }
        $directory = sys_get_temp_dir().'/assist-normal-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $path = $directory.'/control.sock';
        $identityFile = $directory.'/identity.json';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $parent = getmypid();
        $now = time();
        $ticket = 'viewer_'.str_repeat('a', 48);
        $peerProcess = pcntl_fork();
        if ($peerProcess === 0) {
            socket_close($listener);
            $channel = null;
            try {
                $channel = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 30000);
                $registry = RegisteredPublicFixtureRegistry::compiled();
                $store = new PublicCoreReceiptStore($directory.'/producer', str_repeat('s', 32));
                $processorCell = new class { public ?PublicCoreProcessor $processor = null; };
                $sessions = new PublicCoreSessionAuthority($registry, $store,
                    static function (array $binding) use ($processorCell): ?array {
                        return $processorCell->processor instanceof PublicCoreProcessor
                            ? $processorCell->processor->currentNormalViewer($binding) : null;
                    }, static fn (): int => $now);
                $peer = $channel->peer();
                $processor = new PublicCoreProcessor($registry, $store, $sessions, new PublicCoreRuntimeReadiness($registry),
                    static fn (array $actual): ?array => $actual === $peer
                        ? ['role' => 'app', 'identityRef' => 'ref_source_only_app_role', 'kernelPeer' => $actual] : null,
                    static fn (string $reference): array => ['viewerTicketRef' => $reference],
                    static function () use ($directory): array { file_put_contents($directory.'/factory-called', '1'); return []; });
                $processorCell->processor = $processor;
                $processor->serveAppChannel($channel);
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($directory.'/peer-error', $failure->getMessage());
                exit(91);
            } finally { $channel?->close(); }
        }
        self::assertGreaterThan(0, $peerProcess);
        $channel = null;
        $runtime = null;
        $port = null;
        $input = null;
        $checks = 0;
        $sourceOwner = new class { public bool $revoke = false; };
        $invocation = new class {
            public ?PublicCoreAssistantRuntime $runtime = null;
            public ?PublicCoreContextBindings $port = null;
            public ?array $input = null;
            public int $expiry = 0;
        };
        $expiry = 0;
        try {
            $identity = ['schemaVersion' => 'public-core-app-processor-identity/1', 'localRole' => 'App', 'peerRole' => 'Processor',
                'localIdentityRef' => 'ref_source_only_app_identity', 'peerIdentityRef' => 'ref_source_only_processor_identity',
                'localKernel' => ['pid' => $parent, 'uid' => $uid, 'gid' => $gid],
                'peerKernel' => ['pid' => $peerProcess, 'uid' => $uid, 'gid' => $gid]];
            file_put_contents($identityFile, GatewayModelRequest::canonicalJson($identity));
            chmod($identityFile, 0600);
            $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $peerProcess], 30000);
            $port = PublicCoreContextBindings::sourceNormalAppPort($channel, $identityFile);
            $invocation->port = $port;
            try { PublicCoreContextBindings::sourceNormalAppPort($channel, $identityFile); self::fail('Same actual Channel cannot have concurrent source pumps'); }
            catch (LogicException $failure) { self::assertSame('receipt_changed', $failure->getMessage()); }
            $fence = Mockery::mock(PublicCoreBackendAuthorityFence::class);
            $fence->shouldReceive('viewerTicketBinding')->andReturnUsing(function (array $check, int $wireExpiry) use (&$checks,
                $invocation, $sourceOwner, $ticket, $mode): array {
                $checks++;
                self::assertSame($ticket, $check['viewerTicketRef']);
                self::assertSame($invocation->expiry, $wireExpiry);
                if ($mode === 'reentry' && $invocation->runtime !== null && $invocation->input !== null) {
                    try { $invocation->runtime->callNormalSource($invocation->port, 'open_or_resume', $invocation->input, $invocation->expiry); }
                    catch (LogicException) {}
                }
                if ($mode === 'revoked' || $sourceOwner->revoke) { throw new LogicException('authorization_changed'); }
                return ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1', 'viewerTicketRef' => $ticket,
                    'currentViewer' => ['authorized' => true, 'viewerRef' => 'real-source-principal', 'organizationRef' => 'real-source-organization',
                        'authorizationRevision' => 'source-revision-stable', 'policyRevision' => 'source-policy-stable']];
            });
            $runtime = new PublicCoreAssistantRuntime($fence);
            $invocation->runtime = $runtime;
            $expiry = min(time() + 20, $channel->deadlineExpiresAt());
            $invocation->expiry = $expiry;
            $readiness = $runtime->callNormalSource($port, 'readiness', [], $expiry);
            self::assertSame('unavailable', $readiness['status']);
            self::assertFalse($readiness['model_enabled']);
            self::assertSame(0, $checks);
            $input = ['viewer_ticket_ref' => $ticket, 'fixture_id' => 'material-search-v1', 'fixture_version' => 'public-material/1',
                'input_id' => 'price-b25', 'request_id' => 'cc5b0d36-5c63-4a8c-bcfa-53c41e43ed0c', 'public_session_ref' => null];
            $invocation->input = $input;
            if ($mode === 'reentry') {
                try { $runtime->callNormalSource($port, 'open_or_resume', $input, $expiry); self::fail('Reentry poisons exact active operation'); }
                catch (LogicException $failure) { self::assertSame('receipt_changed', $failure->getMessage()); }
            } else {
                $opened = $runtime->callNormalSource($port, 'open_or_resume', $input, $expiry);
                if ($mode === 'revoked') {
                    self::assertSame('blocked', $opened['status']);
                    self::assertSame('authorization_changed', $opened['reasonCode']);
                } else {
                    self::assertSame('accepted', $opened['status']);
                    self::assertSame($now + 120, $opened['original_expires_at']);
                    self::assertGreaterThanOrEqual(4, $checks);
                    if ($mode === 'later-revoke') {
                        $sourceOwner->revoke = true;
                        $lookup = $runtime->callNormalSource($port, 'lookup_owned',
                            ['viewer_ticket_ref' => $ticket, 'request_ref' => $opened['request_ref']], $expiry, $opened['original_expires_at']);
                        self::assertSame('blocked', $lookup['status']);
                        self::assertSame('authorization_changed', $lookup['reasonCode']);
                    } else {
                        $replayed = $runtime->callNormalSource($port, 'open_or_resume', $input, $expiry, $opened['original_expires_at']);
                        self::assertSame($opened, $replayed);
                        $beforePoll = $checks;
                        $ownedInput = ['viewer_ticket_ref' => $ticket, 'request_ref' => $opened['request_ref']];
                        $pending = $runtime->callNormalSource($port, 'lookup_owned', $ownedInput, $expiry, $opened['original_expires_at']);
                        self::assertSame('accepted', $pending['status']);
                        self::assertGreaterThan($beforePoll, $checks);
                        $blocked = $runtime->callNormalSource($port, 'execute_owned', $ownedInput, $expiry, $opened['original_expires_at']);
                        self::assertSame('blocked', $blocked['status']);
                        self::assertSame('runtime_not_activated', $blocked['reasonCode']);
                        self::assertArrayNotHasKey('reply', $blocked);
                        self::assertArrayNotHasKey('trace', $blocked);
                        try { $runtime->callNormalSource($port, 'lookup_owned', $ownedInput, $expiry + 1, $expiry); self::fail('Stored original E cannot be refreshed'); }
                        catch (LogicException $failure) { self::assertSame('expired', $failure->getMessage()); }
                    }
                }
            }
            self::assertFileDoesNotExist($directory.'/factory-called');
        } finally {
            $channel?->close();
            socket_close($listener);
            pcntl_waitpid($peerProcess, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), is_file($directory.'/peer-error') ? file_get_contents($directory.'/peer-error') : '');
            foreach (glob($directory.'/producer/*') ?: [] as $file) { unlink($file); }
            if (is_dir($directory.'/producer')) { rmdir($directory.'/producer'); }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public static function normalSourceConsumerCases(): array
    {
        return [['valid'], ['revoked'], ['later-revoke'], ['reentry']];
    }

    #[DataProvider('normalConsumerMalformedReplies')]
    public function testNormalSourceConsumerRejectsActualMalformedResultAndForeignPhase(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Malformed normal source frames require actual local Linux SCM.');
        }
        $directory = sys_get_temp_dir().'/assist-normal-deny-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $socketPath = $directory.'/control.sock';
        $identityFile = $directory.'/identity.json';
        $listener = AuthenticatedPublicCoreChannel::listen($socketPath);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $parent = getmypid();
        $child = pcntl_fork();
        if ($child === 0) {
            socket_close($listener);
            $peer = null;
            try {
                $peer = AuthenticatedPublicCoreChannel::connect($socketPath, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 10000);
                $request = $peer->receive();
                if ($mode === 'foreign-phase') {
                    $peer->send('check_binding', null, null, ['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                        'viewerTicketRef' => 'viewer_'.str_repeat('a', 48)], $request['expiresAt']);
                } else {
                    $output = (new PublicCoreRuntimeReadiness(RegisteredPublicFixtureRegistry::compiled()))->resolve();
                    $payload = ['schemaVersion' => 'public-core-processor-operation-result/1-proposal',
                        'operationRef' => $request['payload']['operationRef'], 'output' => $output];
                    if ($mode === 'schema') { $payload['schemaVersion'] = 'unknown-normal/1'; }
                    if ($mode === 'operation') { $payload['operationRef'] = 'ref_'.str_repeat('f', 32); }
                    if ($mode === 'extra') { $payload['raw'] = 'private reply'; }
                    if ($mode === 'raw-core') { $payload['output'] = ['status' => 'completed', 'reply' => 'private cached reply']; }
                    if ($mode === 'receipt') { $payload['output'] = ['schemaVersion' => 'public-core-result-publication/1', 'publicationRef' => 'ref_'.str_repeat('e', 32)]; }
                    if ($mode === 'ready-tag') { $payload['output']['status'] = 'ready'; $payload['output']['model_enabled'] = true; }
                    $peer->send('result', $mode === 'request' ? 'ref_'.str_repeat('b', 32) : $request['requestRef'],
                        $mode === 'attempt' ? 'ref_'.str_repeat('c', 32) : $request['attemptRef'], $payload,
                        $mode === 'expiry' ? $request['expiresAt'] + 1 : $request['expiresAt']);
                }
                usleep(10000);
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($directory.'/peer-error', $failure->getMessage());
                exit(91);
            } finally { $peer?->close(); }
        }
        self::assertGreaterThan(0, $child);
        $channel = null;
        try {
            $identity = ['schemaVersion' => 'public-core-app-processor-identity/1', 'localRole' => 'App', 'peerRole' => 'Processor',
                'localIdentityRef' => 'ref_source_only_app_identity', 'peerIdentityRef' => 'ref_source_only_processor_identity',
                'localKernel' => ['pid' => $parent, 'uid' => $uid, 'gid' => $gid],
                'peerKernel' => ['pid' => $child, 'uid' => $uid, 'gid' => $gid]];
            file_put_contents($identityFile, GatewayModelRequest::canonicalJson($identity));
            chmod($identityFile, 0600);
            $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 10000);
            $port = PublicCoreContextBindings::sourceNormalAppPort($channel, $identityFile);
            $fence = Mockery::mock(PublicCoreBackendAuthorityFence::class);
            $fence->shouldNotReceive('viewerTicketBinding');
            try {
                (new PublicCoreAssistantRuntime($fence))->callNormalSource($port, 'readiness', [], time() + 5);
                self::fail('Only exact normal reply/phase/correlation may complete a source call');
            } catch (LogicException $failure) {
                self::assertContains($failure->getMessage(), ['receipt_changed', 'source_unavailable', 'authorization_changed']);
            }
        } finally {
            $channel?->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), is_file($directory.'/peer-error') ? file_get_contents($directory.'/peer-error') : '');
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public static function normalConsumerMalformedReplies(): array
    {
        return array_map(static fn (string $mode): array => [$mode],
            ['schema', 'operation', 'extra', 'raw-core', 'receipt', 'ready-tag', 'request', 'attempt', 'expiry', 'foreign-phase']);
    }

    public function testProcessorFactoryComposesNativeContextLoopAndMaterialPortsWithoutQualifyingTransport(): void
    {
        $fixture = new OfflineLoopFixtures();
        $tokenizer = static fn (string $json, array $identity): array => $identity + ['tokens' => strlen($json)];
        $context = $fixture->context->service();
        $loop = PublicCoreContextBindings::processorLoop($context,
            static fn (?string $ref): array => $fixture->authority($ref), $tokenizer,
            static function (array $input) use ($fixture): array {
                return $fixture->driverCalls++ === 0 ? OfflineLoopFixtures::searchAction() : OfflineLoopFixtures::priceAnswer($input);
            }, $fixture->adapter(), $fixture->validator(), static fn (): int => $fixture->now,
            static function (array $binding, array $conditions, array $evidence) use ($fixture): ?array {
                if (!$fixture->gateAllowed || $fixture->corpus->guard($fixture->corpus->context()) !== null) { return null; }

                return ['authority' => $fixture->authority($binding['receipt']['contextRef']),
                    'now' => $fixture->now, 'privateContext' => $fixture->corpus->context()];
            });
        $result = $loop->run('offline', $fixture->context->request());
        self::assertSame('READY', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('Бетон В25 стоит 7800.00 RUB за м³.', $result['reply']);
        self::assertFalse($result['transportAllowed']);
        self::assertSame('offline-synthetic', $fixture->context->profile['qualification']);
        self::assertSame(2, $fixture->driverCalls);
    }

    public function testFrozenLoopRejectsCalculatedQuoteClaimBeforePermissiveSemanticCallback(): void
    {
        $fixture = new OfflineLoopFixtures();
        $text = 'Расчёт для 12 м³: 93600.00 RUB.';
        $fixture->acceptedTexts[] = $text;
        $fixture->actions = [OfflineLoopFixtures::searchAction(), static function (array $input) use ($text): array {
            $answer = OfflineLoopFixtures::priceAnswer($input);
            $answer['text'] = $text;
            $answer['claims'][0]['value'] = '93600.00';

            return $answer;
        }];
        $result = $fixture->loop()->run('offline', $fixture->context->request());
        self::assertNotSame('READY', $result['status']);
        self::assertSame([], $fixture->validatedActions);
        self::assertSame('7800.00', $fixture->corpus->records()[0]->decimal);
    }

    public function testCompletedEnvelopeStagingAndCommittedResponsePreserveExactWholeBodyBytes(): void
    {
        $dto = self::completed();
        $dto['reply'] = 'Проверка «МОСТ»: /тест и emoji 🔎.';
        $stage = PublicCoreRuntimeResource::stageCompletedEnvelope($dto);
        $response = PublicCoreRuntimeResource::committedEnvelopeResponse($stage['envelopeBytes'], $stage['envelopeDigest']);
        self::assertSame($stage['envelopeBytes'], $response->getContent());
        self::assertSame($stage['envelopeDigest'], hash('sha256', $response->getContent()));
        self::assertSame(['success' => true, 'message' => null, 'data' => $dto], $response->getData(true));
        $this->expectExceptionMessage('public_core_response_invalid');
        PublicCoreRuntimeResource::committedEnvelopeResponse($stage['envelopeBytes'].' ', $stage['envelopeDigest']);
    }

    public function testCompletedEvidenceSurvivesCanonicalCoreStagingAndEnvelopeDigest(): void
    {
        [, , , , , , $candidate] = self::sourcePublicationFixture();
        $core = json_decode($candidate['resultBytes'], true, 64, JSON_THROW_ON_ERROR);
        $call = 'ref_'.str_repeat('1', 32);
        $core['actual_model'] = 'observed-response-model/1';
        $core['tools'] = [['label' => 'Поиск материалов', 'call_ref' => $call]];
        $core['sources'] = [['ref' => 'ref_'.str_repeat('2', 32), 'label' => 'Учебный каталог']];
        array_unshift($core['trace'], ['action' => 'tool', 'step' => 1, 'tokens' => 20, 'callRef' => $call]);
        $bytes = json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $binding = array_replace($candidate['binding'], ['resultDigest' => hash('sha256', $bytes)]);
        $stage = PublicCoreRuntimeResource::stageCoreCompletedEnvelope($bytes, $binding);
        $response = PublicCoreRuntimeResource::committedEnvelopeResponse($stage['bodyBytes'], $stage['envelopeDigest']);
        $data = $response->getData(true)['data'];
        self::assertSame($core['actual_model'], $data['actual_model']);
        self::assertSame($core['tools'], $data['tools']);
        self::assertSame($core['sources'], $data['sources']);
        self::assertNotSame($candidate['binding']['resultDigest'], $stage['resultDigest']);
        self::assertSame($stage['envelopeDigest'], hash('sha256', $response->getContent()));
        $this->expectExceptionMessage('public_core_response_invalid');
        PublicCoreRuntimeResource::stageCoreCompletedEnvelope($bytes, $candidate['binding']);
    }

    public function testNonCompletedStatesCannotPublishResponseEvidence(): void
    {
        foreach (['accepted', 'running', 'blocked'] as $status) {
            $dto = array_replace(PublicCoreRuntimeResource::blocked(), [
                'status' => $status, 'reason_code' => $status === 'blocked' ? 'runtime_not_activated' : 'none',
                'request_ref' => 'ref_'.str_repeat('1', 32), 'public_session_ref' => 'ref_'.str_repeat('2', 32),
            ]);
            $safe = (new PublicCoreRuntimeResource($dto))->resolve();
            self::assertNull($safe['actual_model']);
            self::assertSame([], $safe['tools']);
            self::assertSame([], $safe['sources']);
            foreach (['actual_model' => 'observed-response-model/1',
                'tools' => [['label' => 'Поиск материалов', 'call_ref' => 'ref_'.str_repeat('3', 32)]],
                'sources' => [['label' => 'Учебный каталог', 'ref' => 'ref_'.str_repeat('4', 32)]]] as $key => $value) {
                try {
                    (new PublicCoreRuntimeResource(array_replace($dto, [$key => $value])))->resolve();
                    self::fail('Response evidence may be published only after completion');
                } catch (LogicException $failure) {
                    self::assertSame('public_core_response_invalid', $failure->getMessage());
                }
            }
        }
    }

    #[DataProvider('unsafeCompletedEvidence')]
    public function testCompletedEvidenceRejectsUnsafeShapeBoundsAndUnrelatedCalls(array $patch): void
    {
        $this->expectExceptionMessage('public_core_response_invalid');
        PublicCoreRuntimeResource::stageCompletedEnvelope(array_replace(self::completed(), $patch));
    }

    public static function unsafeCompletedEvidence(): array
    {
        $call = 'ref_'.str_repeat('a', 32);
        $source = ['ref' => 'ref_'.str_repeat('b', 32), 'label' => 'Учебный каталог'];
        $tool = ['label' => 'Поиск материалов', 'call_ref' => $call];
        $trace = [['action' => 'tool', 'step' => 1, 'tokens' => 10, 'callRef' => $call]];
        return [
            [['actual_model' => "model\nPRIVATE"]],
            [['actual_model' => str_repeat('m', 129)]],
            [['actual_model' => ['id' => 'model']]],
            [['tools' => [$tool]]],
            [['tools' => [$tool + ['raw_output' => 'PRIVATE']], 'trace' => $trace]],
            [['tools' => [$tool, $tool], 'trace' => $trace]],
            [['tools' => array_fill(0, 65, $tool), 'trace' => $trace]],
            [['tools' => ['call' => $tool], 'trace' => $trace]],
            [['sources' => [$source + ['private_id' => 123]]]],
            [['sources' => [$source, $source]]],
            [['sources' => array_fill(0, 65, $source)]],
            [['sources' => ['source' => $source]]],
            [['sources' => [['ref' => 'private/123', 'label' => 'PRIVATE']]]],
        ];
    }

    public function testCompletedEnvelopeRejectsPrivateOrUnknownFieldsBeforeStaging(): void
    {
        $this->expectExceptionMessage('public_core_response_invalid');
        PublicCoreRuntimeResource::stageCompletedEnvelope(self::completed() + ['publicationRef' => 'publication_'.str_repeat('a', 32)]);
    }

    public function testSourcePublicationReturnsPrivateReceiptAndResolvesWholeBodyOnlyForCurrentInflightDelivery(): void
    {
        [$runtime, $delivery, $context, $input, $retained, $port, $candidate] = self::sourcePublicationFixture();
        $calls = (object) ['prepare' => 0];
        $publish = $runtime->sourcePublicationCallback($delivery, $port);
        $receipt = $publish($input, $retained, static function () use ($calls, $candidate): array { $calls->prepare++; return $candidate; });
        self::assertIsArray($receipt);
        self::assertSame(['schemaVersion', 'binding', 'publicationRef'], array_keys($receipt));
        self::assertSame(1, $calls->prepare);
        $stage = PublicCoreRuntimeResource::stageCoreCompletedEnvelope($candidate['resultBytes'], $candidate['binding']);
        self::assertNotSame($stage['resultDigest'], $stage['envelopeDigest']);
        $wrongFrame = array_replace($context, ['sequence' => $context['sequence'] + 1]);
        self::assertNull($runtime->resolveSourcePublication($delivery, $receipt, $wrongFrame));
        $response = $runtime->resolveSourcePublication($delivery, $receipt, $context);
        self::assertNotNull($response);
        self::assertSame($stage['bodyBytes'], $response->getContent());
        self::assertSame([], $response->getData(true)['data']['sources']);
        self::assertArrayNotHasKey('publicationRef', $response->getData(true)['data']);
        self::assertNull($runtime->resolveSourcePublication($delivery, $receipt, $context));
        self::assertNull($publish($input, $retained, static fn (): array => $candidate));
    }

    public function testSourcePublicationDeniesLastPrepareMutationReentryMissingPortAndAlteredDigest(): void
    {
        foreach (['source', 'owner', 'reentry', 'digest', 'missing'] as $failure) {
            [$runtime, $delivery, $context, $input, $retained, $port, $candidate, $live, $owner] = self::sourcePublicationFixture();
            $publish = $runtime->sourcePublicationCallback($delivery, $failure === 'missing' ? null : $port);
            $receipt = $publish($input, $retained, static function () use ($failure, $live, $owner, $publish, $input, $retained, $candidate): array {
                if ($failure === 'source') { $live->guardInput['resultBinding']['registryDigest'] = str_repeat('f', 64); }
                if ($failure === 'owner') { $owner->viewer['authorized'] = false; }
                if ($failure === 'reentry') { $publish($input, $retained, static fn (): array => $candidate); }
                if ($failure === 'digest') { $candidate['resultBytes'] .= ' '; }

                return $candidate;
            });
            self::assertNull($receipt);
            self::assertNull($runtime->resolveSourcePublication($delivery,
                ['schemaVersion' => 'public-core-result-publication/1', 'binding' => $input['resultBinding'],
                    'publicationRef' => 'publication_'.str_repeat('a', 48)], $context));
        }
    }

    public function testMalformedCoreTraceCannotBePublishedAsUiTrace(): void
    {
        [, , , , , , $candidate] = self::sourcePublicationFixture();
        $core = json_decode($candidate['resultBytes'], true, 64, JSON_THROW_ON_ERROR);
        $core['trace'][0]['privatePlan'] = 'PRIVATE';
        $bytes = json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $binding = array_replace($candidate['binding'], ['resultDigest' => hash('sha256', $bytes)]);
        $this->expectExceptionMessage('public_core_response_invalid');
        PublicCoreRuntimeResource::stageCoreCompletedEnvelope($bytes, $binding);
    }

    private static function sourcePublicationFixture(): array
    {
        $owner = (object) ['viewer' => ['authorized' => true, 'viewerRef' => 'actor_'.str_repeat('a', 64),
            'organizationRef' => 'organization_'.str_repeat('b', 64), 'authorizationRevision' => str_repeat('c', 64),
            'policyRevision' => str_repeat('d', 64)]];
        $fence = new class($owner) extends PublicCoreBackendAuthorityFence {
            public function __construct(private readonly object $sourceTcbOwner) { parent::__construct(); }
            public function viewerTicketBinding(array $payload, int $frameExpiresAt): array
            {
                return ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1',
                    'viewerTicketRef' => $payload['viewerTicketRef'], 'currentViewer' => $this->sourceTcbOwner->viewer];
            }
        };
        $runtime = new PublicCoreAssistantRuntime($fence);
        $context = ['viewerTicketRef' => 'viewer_'.str_repeat('a', 48), 'requestRef' => 'request_'.str_repeat('b', 48),
            'sessionRef' => 'session_'.str_repeat('c', 48), 'channelRef' => 'channel_'.str_repeat('d', 48),
            'sequence' => 2, 'genuineExpiresAt' => time() + 60];
        $core = ['status' => 'completed', 'reasonCode' => 'none', 'request_ref' => $context['requestRef'],
            'reply' => 'Проверенный ответ МОСТ / пример.', 'trace' => [['action' => 'ready', 'step' => 2, 'tokens' => 30, 'callRef' => null]],
            'transportAllowed' => false, 'actual_model' => null, 'tools' => [], 'sources' => []];
        $bytes = json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $binding = ['schemaVersion' => 'public-core-result-binding/1', 'requestRef' => $context['requestRef'],
            'sessionRef' => $context['sessionRef'], 'processRef' => 'process_'.str_repeat('e', 48), 'ownerDigest' => str_repeat('a', 64),
            'profileFingerprint' => str_repeat('b', 64), 'registryDigest' => str_repeat('c', 64),
            'manifestGenerationRef' => 'source-manifest/1', 'runtimeGenerationRef' => 'source-runtime/1',
            'runtimeInstanceRef' => 'instance_'.str_repeat('f', 48), 'resultDigest' => hash('sha256', $bytes)];
        $input = ['schemaVersion' => 'public-core-publication-operation/1', 'operationRef' => 'operation_'.bin2hex(random_bytes(24)),
            'viewerBinding' => ['viewerTicketRef' => $context['viewerTicketRef']], 'resultBinding' => $binding,
            'genuineExpiresAt' => $context['genuineExpiresAt'], 'maxDurationMs' => 250];
        $retained = new \stdClass();
        $live = (object) ['guardInput' => $input, 'retainedRuntime' => $retained];
        $port = PublicCoreContextBindings::sourcePublicationPort($context['channelRef'], $live);
        $delivery = $runtime->openSourceDelivery($context);

        return [$runtime, $delivery, $context, $input, $retained, $port,
            ['binding' => $binding, 'resultBytes' => $bytes, 'resultDigest' => $binding['resultDigest']], $live, $owner];
    }

    public function testAcceptedCatalogDoesNotMakeDefaultRuntimeOrModelAvailable(): void
    {
        $runtime = new PublicCoreAssistantRuntime();
        $viewer = Mockery::mock(User::class);
        $readiness = (new PublicCoreRuntimeResource($runtime->readiness($viewer, 37)))->resolve();
        self::assertSame('unavailable', $readiness['status']);
        self::assertFalse($readiness['model_enabled']);
        self::assertFalse($readiness['private_ready']);
        self::assertNull($readiness['actual_model']);
        self::assertCount(2, $readiness['fixtures']);
        self::assertSame(7, array_sum(array_map(static fn (array $fixture): int => count($fixture['inputs']), $readiness['fixtures'])));
        self::assertSame('blocked', $runtime->submit($viewer, 37, self::command())['status']);
        self::assertSame($runtime->poll($viewer, 37, 'request_'.str_repeat('a', 32)),
            $runtime->poll($viewer, 37, 'request_'.str_repeat('b', 32)));
        $this->expectExceptionMessage('runtime_not_activated');
        $runtime->dispatchOwnedRequest('request_'.str_repeat('a', 32));
    }

    #[DataProvider('forbiddenKeys')]
    public function testServiceRejectsForbiddenKeysOutsideHttpBeforeAnyProducerCall(string $key): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(true);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('submit');
        $service = new PublicCoreRequestService($permissions, $runtime);
        $this->expectException(ValidationException::class);
        $service->submit($viewer, 37, self::command() + [$key => 'PRIVATE']);
    }

    public static function forbiddenKeys(): array
    {
        return array_map(static fn (string $key): array => [$key],
            ['message', 'context', 'conversation_id', 'history', 'attachment_ids', 'actions', 'project_id', 'organization_id']);
    }

    public function testCurrentViewerAuthorizationIsEnforcedOnServicePollWithoutHttp(): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(false);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('poll');
        $this->expectException(AuthorizationException::class);
        (new PublicCoreRequestService($permissions, $runtime))->poll($viewer, 37, 'request_'.str_repeat('a', 32));
    }

    public function testMissingOrganizationAndNonOpaqueReferenceCannotReachProducer(): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(true);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('readiness');
        $runtime->shouldNotReceive('poll');
        $service = new PublicCoreRequestService($permissions, $runtime);
        try {
            $service->readiness($viewer, 0);
            self::fail('Missing organization must fail');
        } catch (AuthorizationException) {
        }
        $this->expectException(ValidationException::class);
        $service->poll($viewer, 37, '../private');
    }

    public function testPublicResourceExcludesRealEntityNavigationAndPlanText(): void
    {
        $result = self::completed() + ['private_map' => 'PRIVATE'];
        $result['trace'][0]['plan'] = 'PRIVATE plan';
        $public = (new PublicCoreRuntimeResource($result))->resolve();
        self::assertSame($result['reply'], $public['reply']);
        self::assertSame(['ref', 'label'], array_keys($public['sources'][0]));
        self::assertSame(['action', 'step', 'tokens', 'callRef'], array_keys($public['trace'][0]));
        self::assertStringNotContainsString('PRIVATE', json_encode($public, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/projects/', json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function testBufferedReplyIsRejectedUntilCompletedAndUnavailableCannotAdvertiseModelEnabled(): void
    {
        try {
            (new PublicCoreRuntimeResource(array_replace(self::completed(), ['status' => 'running'])))->resolve();
            self::fail('Running request cannot publish reply');
        } catch (LogicException) {
        }
        $this->expectExceptionMessage('public_core_response_invalid');
        (new PublicCoreRuntimeResource(array_replace(PublicCoreRuntimeResource::unavailable(), ['model_enabled' => true])))->resolve();
    }

    public function testDedicatedJobCarriesOnlyOpaqueReferenceAndNeverRetriesDefaultUnavailableDispatch(): void
    {
        $job = new ExecutePublicCoreTestJob('request_'.str_repeat('a', 32));
        self::assertSame('ai-public-core', $job->queue);
        self::assertSame(1, $job->tries);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldNotReceive('canUseAssistant');
        $this->expectExceptionMessage('runtime_not_activated');
        $job->handle(new PublicCoreRequestService($permissions, new PublicCoreAssistantRuntime()));
    }

    public function testGatewaySixteenFieldsMapExplicitlyToCoreElevenAndNativeReceiptEight(): void
    {
        $gateway = self::gatewayProfile();
        $mapped = PublicCoreContextBindings::coreProfile($gateway);
        $core = AssistantModelContextProfile::resolve($mapped['profileRef'], static fn (string $ref): array => $mapped);
        self::assertCount(16, $gateway->values());
        self::assertCount(11, $mapped);
        self::assertCount(8, $core->modelPayload());
        self::assertSame('offline-synthetic', $mapped['qualification']);
        self::assertSame($gateway->inputBudget(), $core->inputBudget());
        self::assertSame($gateway->values()['maxOutputTokens'], $core->modelPayload()['maxOutputTokens']);
        self::assertSame($gateway->values()['toolReserve'], $core->modelPayload()['toolReserve']);
        self::assertNotSame($gateway->fingerprint(), $core->fingerprint());
        self::assertNotSame($core->fingerprint(), hash('sha256', AssistantContextSourceBinding::canonical($core->modelPayload())));
        self::assertArrayNotHasKey('endpoint', $mapped);
        self::assertArrayNotHasKey('mappingEvidenceRef', $mapped);
        self::assertFalse($gateway->isActualProfile());
        $this->expectExceptionMessage('receipt_unavailable');
        (new PublicCoreGatewayModelDriver($gateway))(['schemaVersion' => 'assistant-loop-input/1']);
    }

    public function testUnqualifiedGatewayProfileCannotProduceCoreProfileOrBody(): void
    {
        try {
            PublicCoreContextBindings::coreProfile(GatewayModelProfile::unqualified());
            self::fail('Unqualified profile must fail');
        } catch (LogicException) {
        }
        $this->expectExceptionMessage('model_profile_unqualified');
        (new PublicCoreGatewayModelDriver(GatewayModelProfile::unqualified()))->bodyBytes([]);
    }

    public function testDriverBodyPreservesCompleteLoopInputAndMatchesAcceptedGatewayBodyContract(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $input = ['schemaVersion' => 'assistant-loop-input/1', 'context' => ['currentRef' => 'ref_'.str_repeat('a', 32)],
            'contextScope' => ['kind' => 'selected_entity'], 'tools' => [['name' => 'material.search']],
            'toolReferences' => null, 'repair' => ['reason' => 'claims_invalid']];
        $body = $driver->bodyBytes($input);
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(GatewayModelRequest::canonicalJson($input), $decoded['messages'][1]['content']);
        self::assertFalse($decoded['stream']);
        self::assertFalse($decoded['store']);
        self::assertSame($profile->values()['maxOutputTokens'], $decoded['max_completion_tokens']);
        $request = self::gatewayRequest($profile, $body);
        self::assertNull((new GatewayPublicCoreRequestValidator())->validate($request, $profile, 1000));
        self::assertSame(hash('sha256', $body), $request->projectionDigest);
    }

    #[DataProvider('conditionalDriverState')]
    public function testConditionalDriverUsesNativeCommittedProjectionAndBothFences(string $mode, ?string $reason): void
    {
        $fixture = new PublicCoreAuthorityTest('testNativeCommittedProjectionUsesSeparateGatewayFingerprintAndSingleDurableAttempt');
        (new \ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        try {
            [$dispatch, , $readiness, , $input, $binding] = (new \ReflectionMethod($fixture, 'dispatchPreparation'))->invoke($fixture);
            $profile = $readiness->qualifiedProfile();
            self::assertInstanceOf(GatewayModelProfile::class, $profile);
            self::assertFalse($readiness->resolve()['model_enabled']);
            $state = new class {
                public int $writes = 0;
                public ?GatewayModelRequest $packet = null;
            };
            if ($mode === 'input') { $input['context']['messages'][0]['content'] = 'replacement'; }
            if ($mode === 'binding') { $binding['receipt']['payloadDigest'] = str_repeat('f', 64); }
            if ($mode === 'viewer') {
                $viewer = (new \ReflectionProperty($fixture, 'viewer'))->getValue($fixture);
                $viewer['authorized'] = false;
                (new \ReflectionProperty($fixture, 'viewer'))->setValue($fixture, $viewer);
            }
            if ($mode === 'source') {
                $source = (new \ReflectionProperty($fixture, 'runtimeSource'))->getValue($fixture);
                $source['runtimeGenerationRef'] = 'ref_changed_source_generation';
                (new \ReflectionProperty($fixture, 'runtimeSource'))->setValue($fixture, $source);
            }
            if ($mode === 'native') { (new \ReflectionProperty($fixture, 'nativeQualification'))->setValue($fixture, 'unknown'); }
            if ($mode === 'grant') { (new \ReflectionProperty($fixture, 'grantBudget'))->setValue($fixture, 0); }
            $gateway = (new \ReflectionMethod($fixture, 'localGateway'))->invoke($fixture, $dispatch, $readiness,
                static function (GatewayModelProfile $current, string $bytes) use ($fixture, $dispatch, $state): array {
                    $state->writes++;
                    self::assertInstanceOf(GatewayModelRequest::class, $state->packet);
                    self::assertSame($state->packet->bodyBytes, $bytes);
                    self::assertTrue((new \ReflectionProperty($fixture, 'appHeld'))->getValue($fixture));
                    (new \ReflectionProperty($fixture, 'nativeEvent'))->setValue($fixture, 'uploaded');
                    self::assertArrayNotHasKey('reasonCode', $dispatch->uploadComplete($state->packet));
                    self::assertFalse((new \ReflectionProperty($fixture, 'appHeld'))->getValue($fixture));

                    return ['actionBytes' => GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Выбрать материал.']), 'usage' => null];
                });
            $transport = new class($gateway, $state) implements GatewayModelTransport {
                public function __construct(private GatewayModelTransport $gateway, private object $state) {}

                public function send(GatewayModelRequest $request): GatewayModelResponse
                {
                    $this->state->packet = $request;

                    return $this->gateway->send($request);
                }
            };
            $driver = new PublicCoreGatewayModelDriver($profile, $dispatch, $transport,
                static function (string $contextRef) use ($binding, $mode): ?array {
                    if ($mode === 'binding-throws') { throw new LogicException('private source unavailable'); }

                    return $mode !== 'binding-unavailable' && $contextRef === $binding['receipt']['contextRef'] ? $binding : null;
                });
            try {
                $action = $driver($input);
                self::assertNull($reason);
                self::assertEquals(['type' => 'plan', 'plan' => 'Выбрать материал.'], $action);
                self::assertSame(1, $state->writes);
                self::assertInstanceOf(GatewayModelRequest::class, $state->packet);
                self::assertSame(hash('sha256', $state->packet->bodyBytes), $state->packet->projectionDigest);
                self::assertEquals($input, json_decode(json_decode($state->packet->bodyBytes, true, 64, JSON_THROW_ON_ERROR)['messages'][1]['content'], true, 64, JSON_THROW_ON_ERROR));
            } catch (LogicException $error) {
                self::assertNotNull($reason, $error->getMessage());
                self::assertSame($reason, $error->getMessage());
                self::assertSame(0, $state->writes);
            }
        } finally {
            (new \ReflectionMethod($fixture, 'tearDown'))->invoke($fixture);
        }
    }

    public static function conditionalDriverState(): array
    {
        return [
            ['valid', null], ['input', 'receipt_changed'], ['binding', 'receipt_changed'],
            ['viewer', 'receipt_unavailable'], ['source', 'receipt_unavailable'], ['native', 'gateway_unavailable'],
            ['grant', 'gateway_unavailable'], ['binding-unavailable', 'receipt_unavailable'], ['binding-throws', 'receipt_unavailable'],
        ];
    }

    #[DataProvider('nativeDriverCases')]
    public function testNativeDriverUsesOneFenceWithRealLocalTransferAndSourceOnlyAppGuard(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork') || !extension_loaded('curl')) {
            self::markTestSkipped('Single native fence needs Linux SCM/cURL; Windows is not native proof.');
        }
        $fixture = new PublicCoreAuthorityTest('testNativeCommittedProjectionUsesSeparateGatewayFingerprintAndSingleDurableAttempt');
        (new \ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $directory = (new \ReflectionProperty($fixture, 'directory'))->getValue($fixture);
        mkdir($directory, 0700);
        (new \ReflectionProperty($fixture, 'now'))->setValue($fixture, time());
        $profile = (new \ReflectionMethod($fixture, 'nativeFixtureProfile'))->invoke($fixture);
        $config = $directory.'/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:api.timeweb.ai\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'api.timeweb.ai'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privateKey);
        openssl_x509_export($cert, $certificate);
        $pem = $directory.'/server.pem';
        $ca = $directory.'/ca.pem';
        file_put_contents($pem, $privateKey.$certificate);
        file_put_contents($ca, $certificate);
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]));
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $tls = pcntl_fork();
        if ($tls === 0) {
            try {
                $connection = @stream_socket_accept($server, 3);
                if ($connection === false) {
                    if ($mode === 'stopped') { file_put_contents($directory.'/body', ''); }
                    exit($mode === 'valid' ? 71 : 0);
                }
                stream_set_timeout($connection, 3);
                if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) { exit(72); }
                $headers = '';
                while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 16384) { $headers .= fread($connection, 1); }
                if (preg_match('/Content-Length: (\d+)/i', $headers, $length) !== 1) { exit(73); }
                $body = '';
                while (strlen($body) < (int) $length[1] && !feof($connection)) {
                    $part = fread($connection, (int) $length[1] - strlen($body));
                    if ($part === false || $part === '') { break; }
                    $body .= $part;
                }
                file_put_contents($directory.'/body', $body);
                if ($mode === 'stopped') { fclose($connection); exit(0); }
                $payload = GatewayModelRequest::canonicalJson(['id' => 'local-source-native-fence', 'object' => 'chat.completion',
                    'created' => time(), 'model' => 'source-fixture-model', 'choices' => [['index' => 0,
                        'message' => ['role' => 'assistant', 'content' => '{"type":"plan","plan":"Read public facts."}'], 'finish_reason' => 'stop']]]);
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n".$payload);
                fclose($connection);
                exit(0);
            } catch (\Throwable) { exit(74); }
        }
        self::assertGreaterThan(0, $tls);
        fclose($server);
        $path = $directory.'/driver.sock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $parent = getmypid();
        $gateway = pcntl_fork();
        if ($gateway === 0) {
            try {
                $wire = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 30000);
                $sender = new GatewayPublicCoreHttpSender(static function () use ($directory): string {
                    file_put_contents($directory.'/credentials-read', '1');
                    return 'local-source-no-provider-secret';
                }, null, static function ($handle) use ($port, $ca, $mode): void {
                    curl_setopt($handle, CURLOPT_CONNECT_TO, ['api.timeweb.ai:443:127.0.0.1:'.$port]);
                    curl_setopt($handle, CURLOPT_CAINFO, $ca);
                    if ($mode === 'stopped') { curl_setopt($handle, CURLOPT_MAX_SEND_SPEED_LARGE, 512); }
                });
                GatewayPublicCoreTransport::handleAuthenticatedChannel($wire, $profile, $sender,
                    static fn (): array => ['inputTokens' => 42, 'tokenizerId' => $profile->values()['tokenizerId'],
                        'tokenizerRevision' => $profile->values()['tokenizerRevision'], 'mappingEvidenceRef' => $profile->values()['mappingEvidenceRef']],
                    static fn (): string => 'none');
                $wire->close();
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($directory.'/gateway-error', $failure->getMessage());
                exit($mode === 'valid' ? 75 : 0);
            }
        }
        self::assertGreaterThan(0, $gateway);
        $channel = null;
        $expectedBodyLength = null;
        try {
            $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $gateway], 30000);
            $pins = ['appPeer' => ['pid' => 111, 'uid' => 1001, 'gid' => 1001], 'appChannelRef' => 'channel_app_source_test',
                'gatewayPeer' => $channel->peer(), 'gatewayChannelRef' => $channel->channelRef()];
            if ($mode === 'channel-mismatch') { $pins['gatewayChannelRef'] = 'channel_wrong_source_pair'; }
            (new \ReflectionProperty($fixture, 'nativePins'))->setValue($fixture, $pins);
            if ($mode === 'stopped') { (new \ReflectionProperty($fixture, 'grantBudget'))->setValue($fixture, 200); }
            [$authority, $preparedFixturePacket, $readiness, , $input, $binding] =
                (new \ReflectionMethod($fixture, 'dispatchPreparation'))->invoke($fixture, $profile);
            $expectedBodyLength = strlen($preparedFixturePacket->bodyBytes);
            $store = (new \ReflectionProperty($fixture, 'store'))->getValue($fixture);
            $sessions = (new \ReflectionProperty($fixture, 'sessions'))->getValue($fixture);
            $registry = (new \ReflectionProperty($fixture, 'registry'))->getValue($fixture);
            $store->transaction(static function (array &$state) use ($preparedFixturePacket): array {
                unset($state['requests'][$preparedFixturePacket->requestRef]['dispatchAttempts'][$preparedFixturePacket->attemptRef]);
                return ['sourceFixtureSetup' => true];
            });
            $processor = new PublicCoreProcessor($registry, $store, $sessions,
                $mode === 'processor-mismatch' ? new PublicCoreRuntimeReadiness($registry) : $readiness);
            $lookups = (object) ['count' => 0];
            $driver = new PublicCoreGatewayModelDriver($profile, $authority, null,
                static function (string $contextRef) use ($binding, $lookups): array {
                    $lookups->count++;
                    self::assertSame($binding['receipt']['contextRef'], $contextRef);
                    return $binding;
                }, $processor, $channel);
            self::assertSame('source-fixture-model', $profile->values()['modelId']);
            try {
                $action = $driver($input);
                self::assertSame('valid', $mode);
                self::assertEquals(['type' => 'plan', 'plan' => 'Read public facts.'], $action);
            } catch (LogicException $failure) {
                self::assertNotSame('valid', $mode, $failure->getMessage());
                self::assertContains($failure->getMessage(), $mode === 'stopped'
                    ? ['expired', 'gateway_unavailable'] : ['gateway_identity_unavailable']);
            }
            $disk = json_decode(file_get_contents($directory.'/authority.json'), true, flags: JSON_THROW_ON_ERROR)['state'];
            $attempts = $disk['requests'][$preparedFixturePacket->requestRef]['dispatchAttempts'];
            if ($mode === 'channel-mismatch') {
                self::assertSame(0, $lookups->count);
                self::assertSame([], $attempts);
            } else {
                self::assertSame(1, $lookups->count);
                self::assertCount(1, $attempts);
            }
            if (in_array($mode, ['valid', 'stopped'], true)) {
                $attempt = array_values($attempts)[0];
                self::assertSame('consumed', $attempt['status']);
                self::assertSame($mode === 'valid' ? 'uploaded' : 'stopped', $attempt['uploadEvent']['event']);
                self::assertTrue(GatewayModelRequest::isReference($attempt['uploadEvent']['completionRef']));
                if ($mode === 'valid') {
                    self::assertSame($attempt['binding']['projectionDigest'], hash('sha256', file_get_contents($directory.'/body')));
                }
                self::assertFalse((new \ReflectionProperty($fixture, 'appHeld'))->getValue($fixture));
                self::assertFalse($authority->uploadPending());
                $beforeReplay = $lookups->count;
                try { $driver($input); self::fail('Closed native channel cannot be replayed'); }
                catch (LogicException $failure) { self::assertSame('gateway_identity_unavailable', $failure->getMessage()); }
                self::assertSame($beforeReplay, $lookups->count);
            } else {
                self::assertFileDoesNotExist($directory.'/credentials-read');
                self::assertFileDoesNotExist($directory.'/body');
            }
        } finally {
            $channel?->close();
            socket_close($listener);
            pcntl_waitpid($gateway, $gatewayStatus);
            pcntl_waitpid($tls, $tlsStatus);
            self::assertTrue(pcntl_wifexited($gatewayStatus));
            self::assertSame(0, pcntl_wexitstatus($gatewayStatus), is_file($directory.'/gateway-error') ? file_get_contents($directory.'/gateway-error') : '');
            self::assertTrue(pcntl_wifexited($tlsStatus));
            self::assertSame(0, pcntl_wexitstatus($tlsStatus));
            if ($mode === 'stopped' && $expectedBodyLength !== null) {
                self::assertLessThan($expectedBodyLength, strlen(file_get_contents($directory.'/body')));
            }
            (new \ReflectionMethod($fixture, 'tearDown'))->invoke($fixture);
        }
    }

    public static function nativeDriverCases(): array
    {
        return ['valid' => ['valid'], 'stopped' => ['stopped'], 'channel-mismatch' => ['channel-mismatch'], 'processor-mismatch' => ['processor-mismatch']];
    }

    #[DataProvider('missingNativeDriverPorts')]
    public function testNativeDriverRejectsHalfUnqualifiedAndFallbackPortsBeforeBinding(string $mode): void
    {
        $fixture = new PublicCoreAuthorityTest('testNativeCommittedProjectionUsesSeparateGatewayFingerprintAndSingleDurableAttempt');
        (new \ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        try {
            $profile = (new \ReflectionMethod($fixture, 'nativeFixtureProfile'))->invoke($fixture);
            [$authority, , $readiness, , $input] = (new \ReflectionMethod($fixture, 'dispatchPreparation'))->invoke($fixture, $profile);
            $processor = new PublicCoreProcessor((new \ReflectionProperty($fixture, 'registry'))->getValue($fixture),
                (new \ReflectionProperty($fixture, 'store'))->getValue($fixture),
                (new \ReflectionProperty($fixture, 'sessions'))->getValue($fixture), $readiness);
            $unqualified = (new \ReflectionClass(AuthenticatedPublicCoreChannel::class))->newInstanceWithoutConstructor();
            $state = (object) ['bindings' => 0, 'sends' => 0];
            $transport = new class($state) implements GatewayModelTransport {
                public function __construct(private object $state) {}
                public function send(GatewayModelRequest $request): GatewayModelResponse
                {
                    $this->state->sends++;
                    throw new LogicException('unqualified_transport_must_not_run');
                }
            };
            $driver = new PublicCoreGatewayModelDriver($profile, $authority,
                in_array($mode, ['mixed', 'actual-transport-fallback'], true) ? $transport : null,
                static function () use ($state): null { $state->bindings++; return null; },
                in_array($mode, ['processor-only', 'unqualified-pair', 'mixed'], true) ? $processor : null,
                in_array($mode, ['channel-only', 'unqualified-pair', 'mixed'], true) ? $unqualified : null);
            try { $driver($input); self::fail('Incomplete or unknown native origin cannot dispatch'); }
            catch (LogicException $failure) {
                self::assertSame(in_array($mode, ['missing', 'actual-transport-fallback'], true)
                    ? 'receipt_unavailable' : 'gateway_identity_unavailable', $failure->getMessage());
            }
            self::assertSame(0, $state->bindings);
            self::assertSame(0, $state->sends);
        } finally {
            (new \ReflectionMethod($fixture, 'tearDown'))->invoke($fixture);
        }
    }

    public static function missingNativeDriverPorts(): array
    {
        $cases = ['missing', 'processor-only', 'channel-only', 'unqualified-pair', 'mixed', 'actual-transport-fallback'];
        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    public function testDriverDecodesExactCanonicalActionAndRejectsForeignResponseBindings(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $action = ['type' => 'plan', 'plan' => 'Выбрать разрешённый инструмент.'];
        $response = GatewayModelResponse::completed($request, GatewayModelRequest::canonicalJson($action), null);
        self::assertSame(json_decode(GatewayModelRequest::canonicalJson($action), true, 64, JSON_THROW_ON_ERROR), $driver->action($request, $response));
        $other = GatewayModelResponse::fromArray(array_replace($response->values(), ['attemptRef' => 'attempt_'.str_repeat('d', 32)]));
        $this->expectExceptionMessage('profile_changed');
        $driver->action($request, $other);
    }

    public function testDriverCannotPublishGuessedOrStaleActualModelEvidence(): void
    {
        $profile = GatewayModelProfile::fromArray(array_replace(self::gatewayProfile()->values(), [
            'qualification' => 'actual', 'apiMethod' => 'chat_completions', 'endpoint' => GatewayPublicCoreHttpSender::ENDPOINT,
            'modelId' => 'source-fixture-model', 'modelRevision' => 'source-fixture-v1', 'tokenizerId' => 'source-fixture-tokenizer',
        ]));
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $ref = 'ref_'.str_repeat('a', 32);
        $final = ['type' => 'final', 'text' => 'Публичный ответ.', 'claims' => [], 'sourceRefs' => [$ref],
            'claimScope' => ['kind' => 'selected_entity', 'scopeRef' => $ref, 'sourceGenerationRef' => $ref, 'unitRefs' => [$ref]]];
        $bytes = GatewayModelRequest::canonicalJson($final);
        $model = $profile->values()['modelId'];
        $usage = ['inputTokens' => 10, 'outputTokens' => 10, 'totalTokens' => 20];
        self::assertSame(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR), $driver->action($request, GatewayModelResponse::completed($request, $bytes, $usage, $model)));
        self::assertSame($model, $driver->actualModel());
        self::assertSame('public-gateway-actual', PublicCoreContextBindings::coreProfile($profile)['qualification']);
        foreach ([null, 'wrong-provider-model'] as $invalid) {
            try {
                $driver->action($request, GatewayModelResponse::completed($request, $bytes, $usage, $invalid));
                self::fail('Profile identity cannot replace observed response model');
            } catch (LogicException $error) {
                self::assertSame('invalid_model_output', $error->getMessage());
                self::assertNull($driver->actualModel());
            }
        }
        $stub = self::gatewayProfile();
        $local = new PublicCoreGatewayModelDriver($stub);
        $packet = self::gatewayRequest($stub, $local->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $this->expectExceptionMessage('invalid_model_output');
        $local->action($packet, GatewayModelResponse::completed($packet, $bytes, null, $model));
    }

    public function testReadyResourceCannotUseProfileGuessAsObservedResponseModel(): void
    {
        $state = array_replace(PublicCoreRuntimeResource::unavailable(), ['status' => 'ready', 'reason_code' => 'none',
            'model_enabled' => true, 'capabilities' => ['text'], 'actual_model' => null]);
        self::assertNull((new PublicCoreRuntimeResource($state))->toArray(Request::create('/'))['actual_model']);
        $state['actual_model'] = 'profile-model-guess';
        $this->expectExceptionMessage('public_core_response_invalid');
        (new PublicCoreRuntimeResource($state))->toArray(Request::create('/'));
    }

    public function testUnavailableGatewayResponseCannotBecomeModelAction(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $this->expectExceptionMessage('runtime_not_activated');
        $driver->action($request, GatewayModelResponse::unavailable($request, 'runtime_not_activated'));
    }

    public function testTypedUsageCannotBypassQualifiedProfileOutputBudget(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $response = GatewayModelResponse::completed($request, GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Шаг']),
            ['inputTokens' => 1, 'outputTokens' => 257, 'totalTokens' => 258]);
        $this->expectExceptionMessage('budget_exceeded');
        $driver->action($request, $response);
    }

    public function testPublicCatalogUsesExactAcceptedRowsAndExposesOnlySelectorsAndReviewedLabels(): void
    {
        $registry = RegisteredPublicFixtureRegistry::compiled();
        self::assertSame('f6bfc3c523c792ab9a80dbe2c3a950aebec1dfad65456243bbaadfcdb50a85fe', $registry->manifestDigest());
        $readiness = (new PublicCoreAssistantRuntime())->readiness(Mockery::mock(User::class), 37);
        $actual = [];
        foreach ($readiness['fixtures'] as $fixture) {
            self::assertSame(['fixture_id', 'fixture_version', 'label', 'inputs'], array_keys($fixture));
            foreach ($fixture['inputs'] as $input) {
                self::assertSame(['input_id', 'label'], array_keys($input));
                $row = $registry->resolve($fixture['fixture_id'], $fixture['fixture_version'], $input['input_id']);
                self::assertNotNull($row);
                self::assertSame($row['scenario_step'], $input['input_id']);
                self::assertSame($row['display_text'], $input['label']);
                $actual[] = $input['input_id'];
            }
        }
        self::assertSame(array_column($registry->catalog(), 'input_id'), $actual);
        $encoded = json_encode($readiness, JSON_THROW_ON_ERROR);
        foreach (['record_sha256', 'canonical_question_sha256', 'source_generation_ref', 'records', 'transcript', '7800', '8250'] as $privateField) {
            self::assertStringNotContainsString($privateField, $encoded);
        }
        self::assertFalse($readiness['model_enabled']);
        self::assertSame('unavailable', $readiness['status']);
    }

    public function testUnknownCatalogTupleRemainsBlockedWithoutAuthorityFallback(): void
    {
        $runtime = new PublicCoreAssistantRuntime();
        $viewer = Mockery::mock(User::class);
        self::assertSame('source_unavailable', $runtime->submit($viewer, 37, self::command())['reason_code']);
        self::assertSame('runtime_not_activated', $runtime->submit($viewer, 37,
            array_replace(self::command(), ['fixture_id' => 'material-search-v1', 'fixture_version' => 'public-material/1', 'input_id' => 'price-b25']))['reason_code']);
    }

    public static function gatewayProfile(): GatewayModelProfile
    {
        return GatewayModelProfile::fromArray([
            'profileRef' => 'profile_'.str_repeat('a', 32), 'qualification' => 'local-stub',
            'adapterRevision' => 'unit-public-adapter/1', 'apiMethod' => 'local_action', 'endpoint' => 'local://public-core-stub',
            'modelId' => 'local-action-stub', 'modelRevision' => 'unit/1', 'tokenizerId' => 'unit-tokenizer', 'tokenizerRevision' => 'unit/1',
            'mappingEvidenceRef' => 'mapping_'.str_repeat('b', 32), 'capabilityEvidenceRef' => 'capability_'.str_repeat('b', 32),
            'capacityEvidenceRef' => 'capacity_'.str_repeat('b', 32), 'contextWindow' => 32768,
            'maxOutputTokens' => 256, 'answerReserve' => 1024, 'toolReserve' => 512,
        ]);
    }

    private static function gatewayRequest(GatewayModelProfile $profile, string $body): GatewayModelRequest
    {
        return GatewayModelRequest::fromArray([
            'schemaVersion' => GatewayModelRequest::SCHEMA_VERSION, 'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'purpose' => GatewayModelRequest::PURPOSE, 'requestRef' => 'request_'.str_repeat('a', 32),
            'attemptRef' => 'attempt_'.str_repeat('b', 32), 'publicAdmissionRef' => 'admission_'.str_repeat('a', 32),
            'contextReceiptRef' => 'context_'.str_repeat('a', 32), 'corePayloadDigest' => str_repeat('a', 64),
            'coreReceiptDigest' => str_repeat('b', 64), 'projectionRef' => 'projection_'.str_repeat('a', 32),
            'projectionDigest' => hash('sha256', $body), 'profileRef' => $profile->values()['profileRef'],
            'profileFingerprint' => $profile->fingerprint(), 'expiresAt' => 2000, 'bodyBytes' => $body,
        ]);
    }

    public static function command(): array
    {
        return ['fixture_id' => 'unit-fixture', 'fixture_version' => '1', 'input_id' => 'unit-input',
            'request_id' => 'daaf7245-7a63-4cbd-8fd0-2f98580c7255'];
    }

    public static function completed(): array
    {
        return array_replace(PublicCoreRuntimeResource::blocked(), [
            'status' => 'completed', 'reason_code' => 'none', 'request_ref' => 'request_'.str_repeat('a', 32),
            'public_session_ref' => 'session_'.str_repeat('b', 32), 'reply' => 'Проверенный тестовый ответ.',
            'sources' => [['ref' => 'ref_'.str_repeat('c', 32), 'label' => 'Тестовый источник']],
            'trace' => [['action' => 'ready', 'step' => 1, 'tokens' => 40, 'callRef' => null]],
        ]);
    }
}
