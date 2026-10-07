<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextPreparationService;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantSafeContextSegment;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreTransport;
use App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender;
use App\Services\Privacy\PublicCore\PublicCoreDispatchAuthority;
use App\Services\Privacy\PublicCore\PublicCoreProcessor;
use App\Services\Privacy\PublicCore\PublicCoreReceiptStore;
use App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness;
use App\Services\Privacy\PublicCore\PublicCoreSessionAuthority;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\Context\OfflineContextFixtures;

require_once __DIR__ . '/../../AIAssistant/Context/OfflineContextFixtures.php';

final class PublicCoreAuthorityTest extends TestCase
{
    private string $directory;
    private RegisteredPublicFixtureRegistry $registry;
    private PublicCoreReceiptStore $store;
    private PublicCoreSessionAuthority $sessions;
    private array $viewer;
    private int $now;
    private array $runtimeSource = [];
    private array $runtimeProof = [];
    private int $monoMs = 1000;
    private int $appSequence = 1;
    private int $rpcDelayMs = 0;
    private mixed $grantBudget = 2000;
    private int $predicateBudget = 2000;
    private int $loopBudget = 2000;
    private string $nativeEvent = 'pending';
    private string $nativeQualification = 'local-source-test';
    private bool $cancelAllowed = true;
    private bool $appHeld = false;
    private ?\Closure $controlMutator = null;
    private array $controlCalls = [];
    private ?\Throwable $callbackFailure = null;
    private array $nativeOverrides = [];
    private ?GatewayModelProfile $currentGatewayProfile = null;
    private bool $runtimeReaderAvailable = true;
    private int $cachedFactoryCalls = 0;
    private int $currentRuntimeChecks = 0;
    private ?\Closure $onRuntimeRead = null;
    private array $publications = [];
    private bool $publicationMutex = false;
    private bool $publicationQualified = true;
    private ?string $publicationFault = null;
    private ?array $nativePins = null;
    private bool $expectUnsolicitedHandler = false;
    private int $unsolicitedHandlerReleases = 0;
    private ?PublicCoreProcessor $observedNativeProcessor = null;
    private ?PublicCoreDispatchAuthority $observedNativeAuthority = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/public-core-authority-test-' . bin2hex(random_bytes(16));
        $this->registry = RegisteredPublicFixtureRegistry::compiled();
        $this->store = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        $this->now = 1700000000;
        $this->viewer = ['authorized' => true, 'viewerRef' => 'real-backend-viewer-A', 'organizationRef' => 'real-backend-organization-A',
            'authorizationRevision' => 'authorization/1', 'policyRevision' => 'public-policy/1'];
        $this->sessions = new PublicCoreSessionAuthority($this->registry, $this->store,
            fn (array $binding): ?array => in_array($binding, [['credential' => 'server-ticket'], ['viewerTicketRef' => 'ref_server_viewer_ticket']], true)
                ? $this->viewer : null,
            fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path) || filetype($path) === 'socket') {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        if ($this->callbackFailure !== null) {
            throw $this->callbackFailure;
        }
    }

    private function selection(string $input = 'price-b25', string $requestId = 'cc5b0d36-5c63-4a8c-bcfa-53c41e43ed0c'): array
    {
        return ['fixture_id' => 'material-search-v1', 'fixture_version' => 'public-material/1',
            'input_id' => $input, 'request_id' => $requestId];
    }

    private function open(): array
    {
        $opened = $this->sessions->openOrResume(['credential' => 'server-ticket'], $this->selection());
        self::assertSame('accepted', $opened['status']);
        self::assertFalse($opened['transportAllowed']);
        return $opened;
    }

    private function publication(): array
    {
        $opened = $this->open();
        $scope = ['actor' => 'PUBLIC_FIXTURE_ACTOR', 'tenant' => 'PUBLIC_FIXTURE_TENANT', 'project' => 'PUBLIC_FIXTURE_PROJECT',
            'acl' => 'fixture-acl/1', 'consent' => 'fixture-consent/1', 'policy' => 'fixture-policy/1'];
        $profile = ['profileRef' => 'offline', 'qualification' => 'offline-synthetic', 'adapterRevision' => 'fixture/1',
            'modelId' => 'fixture-only', 'modelRevision' => 'fixture/1', 'tokenizerId' => 'fixture-tokenizer',
            'tokenizerRevision' => 'fixture/1', 'contextWindow' => 1000, 'maxOutputTokens' => 100, 'answerReserve' => 100, 'toolReserve' => 100];
        $binding = ['scope' => $scope, 'snapshotHash' => hash('sha256', 'registered-snapshot'),
            'profileFingerprint' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($profile)),
            'registryDigest' => $this->registry->manifestDigest(), 'aliases' => ['public-current' => 'input-current'],
            'sources' => ['input-current' => ['version' => 'public-material/1']], 'trustedModelProfile' => $profile];
        $publisher = $this->sessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'], fn (array $request): array => $binding);
        $lineage = $publisher->publish('lineage', ['scope' => $scope, 'conversationRef' => $this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref'])['conversationRef']]);
        self::assertSame(['requestRef', 'requestRevision', 'conversationRef', 'issuedAt', 'expiresAt', 'now'], array_keys($lineage));
        $receipt = ['schemaVersion' => 'assistant-context-receipt/1', 'contextRef' => 'public-context', 'currentRef' => 'public-current',
            'payloadDigest' => hash('sha256', 'registered-public-payload'),
            'scopeHash' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($scope)), 'scope' => $scope,
            'conversationRef' => $lineage['conversationRef'], 'profileRef' => 'offline', 'profileFingerprint' => $binding['profileFingerprint'],
            'modelProfile' => AssistantModelContextProfile::resolve('offline', static fn (): array => $profile)->modelPayload(),
            'aliases' => $binding['aliases'], 'sources' => $binding['sources'],
            'lineage' => array_diff_key($lineage, ['now' => true])];
        $expected = ['contextRef' => $receipt['contextRef'], 'payloadDigest' => $receipt['payloadDigest'],
            'receiptDigest' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($receipt))];
        return [$publisher, $receipt, $expected, $opened, $binding];
    }

    public function testClosedManifestContainsExactMappingsAndDeterministicDigests(): void
    {
        self::assertCount(7, $this->registry->catalog());
        foreach ($this->registry->catalog() as $row) {
            foreach (['fixture_id', 'fixture_version', 'scenario_id', 'scenario_version', 'input_id', 'scenario_step',
                'display_text', 'canonical_question_sha256', 'record_sha256', 'source_generation_ref', 'corpus_id'] as $key) {
                self::assertArrayHasKey($key, $row);
            }
            self::assertSame($row['input_id'], $row['scenario_step']);
            self::assertSame(hash('sha256', $row['display_text']), $row['canonical_question_sha256']);
            $unsigned = $row;
            unset($unsigned['record_sha256']);
            self::assertSame(hash('sha256', RegisteredPublicFixtureRegistry::canonical($unsigned)), $row['record_sha256']);
        }
        self::assertSame($this->registry->manifestDigest(), RegisteredPublicFixtureRegistry::compiled()->manifestDigest());
        $copy = $this->registry->manifest();
        $copy['fixtures']['material-search-v1']['records'][0]['price'] = '4800.00';
        self::assertSame('7800.00', $this->registry->records('material-search-v1', 'public-material/1')[0]['price']);
        self::assertSame('8250.50', $this->registry->records('material-search-v1', 'public-material/1')[1]['price']);
        self::assertNull($this->registry->resolve('concrete-quantity-v1', '1', 'price-b25'));
        self::assertNull($this->registry->resolve('material-search-v1', 'latest', 'price-b25'));
        self::assertNull($this->registry->resolve('material-search-v1', 'public-material/1', 'raw private question'));
    }

    #[DataProvider('forbiddenInputs')]
    public function testPrivateFreeInputAndClaimedIdentityKeysAreRejected(string $key): void
    {
        $selection = $this->selection();
        $selection[$key] = 'PRIVATE raw input';
        self::assertSame('blocked', $this->sessions->openOrResume(['credential' => 'server-ticket'], $selection)['status']);
    }

    public static function forbiddenInputs(): array
    {
        return array_map(static fn (string $key): array => [$key], [
            'message', 'context', 'conversation_id', 'history', 'page', 'attachment_ids', 'uploads', 'actions', 'actor_id', 'is_safe',
        ]);
    }

    public function testCurrentRealViewerIsRequiredSeparatelyFromSyntheticRealmNumbers(): void
    {
        $unbound = new PublicCoreSessionAuthority($this->registry, $this->store);
        self::assertSame('blocked', $unbound->openOrResume(['actor' => 7, 'tenant' => 11, 'project' => 13, 'authorized' => true], $this->selection())['status']);
        self::assertSame('blocked', $this->sessions->openOrResume(['actor' => 7], $this->selection())['status']);
        $this->viewer['viewerRef'] = '7';
        $this->viewer['organizationRef'] = '11';
        self::assertSame('accepted', $this->sessions->openOrResume(['credential' => 'server-ticket'], $this->selection())['status']);
    }

    public function testIdempotencyAndSessionOwnershipStayServerOwned(): void
    {
        $opened = $this->open();
        $again = $this->sessions->openOrResume(['credential' => 'server-ticket'], $this->selection());
        self::assertSame($opened, $again);
        $uppercase = $this->selection(requestId: strtoupper($this->selection()['request_id']));
        self::assertSame($opened, $this->sessions->openOrResume(['credential' => 'server-ticket'], $uppercase));
        self::assertSame('blocked', $this->sessions->openOrResume(['credential' => 'server-ticket'], $this->selection('cement-price'), $opened['public_session_ref'])['status']);
        $this->viewer['viewerRef'] = 'other-real-viewer';
        self::assertNull($this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']));
        self::assertSame('blocked', $this->sessions->openOrResume(['credential' => 'server-ticket'], $this->selection(), $opened['public_session_ref'])['status']);
    }

    public function testCurrentAuthorizationRevisionAndExpiryAreNotCached(): void
    {
        $opened = $this->open();
        $this->viewer['authorizationRevision'] = 'authorization/01';
        self::assertNull($this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']));
        $this->viewer['authorizationRevision'] = 'authorization/1';
        $this->now += 120;
        self::assertNull($this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']));
    }

    public function testCurrentPolicyChangeAndMissingProofDoNotReuseSessionAuthority(): void
    {
        $opened = $this->open();
        $this->viewer['policyRevision'] = 'public-policy/01';
        self::assertNull($this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']));
        $this->viewer['policyRevision'] = 'public-policy/1';
        $this->viewer['authorized'] = false;
        self::assertNull($this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']));
        $malformed = new PublicCoreSessionAuthority($this->registry, $this->store, static fn (array $binding): bool => true);
        self::assertSame('blocked', $malformed->openOrResume(['authorized' => true], $this->selection())['status']);
    }

    public function testPublisherActuallyStagesCommitsAndReadsTheLedger(): void
    {
        [$publisher, $receipt, $expected, $opened, $binding] = $this->publication();
        self::assertSame([], $publisher->publish('final_guard', $receipt, $expected));
        self::assertSame('staged', $publisher->publish('stage', $receipt, $expected)['status']);
        self::assertSame([], $publisher->publish('final_guard', $receipt, $expected));
        $disk = json_decode(file_get_contents($this->directory . '/authority.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('staged', $disk['state']['receipts']['public-context']['status']);
        self::assertSame('committed', $publisher->publish('commit', $receipt, $expected)['status']);
        $reopened = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        $reopenedSessions = new PublicCoreSessionAuthority($this->registry, $reopened, fn (array $viewer): array => $this->viewer, fn (): int => $this->now);
        $fresh = $reopenedSessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'], fn (array $request): array => $binding);
        self::assertSame('committed', $fresh->publish('final_guard', $receipt, $expected)['status']);
        self::assertSame($receipt, $fresh->authority('public-context')['receipt']);
        self::assertSame([], $fresh->publish('commit', $receipt, $expected));
    }

    public function testMutableReceiptWrongDigestReplayAndAbortDoNotObtainCommit(): void
    {
        [$publisher, $receipt, $expected] = $this->publication();
        self::assertSame('staged', $publisher->publish('stage', $receipt, $expected)['status']);
        self::assertSame([], $publisher->publish('stage', $receipt, $expected));
        $mutated = $receipt;
        $mutated['payloadDigest'] = hash('sha256', 'app forged bytes');
        self::assertSame([], $publisher->publish('commit', $mutated, $expected));
        $changedExpected = $expected;
        $changedExpected['receiptDigest'] = hash('sha256', RegisteredPublicFixtureRegistry::canonical($mutated));
        $changedExpected['payloadDigest'] = $mutated['payloadDigest'];
        self::assertSame([], $publisher->publish('commit', $mutated, $changedExpected));
        self::assertSame([], $publisher->publish('abort', $receipt, $expected));
        self::assertNull($publisher->authority('public-context'));
        self::assertSame([], $publisher->publish('commit', $receipt, $expected));
        self::assertSame([], $publisher->publish('begin', $receipt, $expected));
        self::assertSame([], $publisher->publish('candidate', $receipt, $expected));
        self::assertSame([], $publisher->publish('ack', $receipt, $expected));
    }

    public function testRevocationInvalidatesCommittedReceiptAndFinalGuard(): void
    {
        [$publisher, $receipt, $expected, $opened] = $this->publication();
        $publisher->publish('stage', $receipt, $expected);
        $publisher->publish('commit', $receipt, $expected);
        self::assertTrue($this->sessions->revoke(['credential' => 'server-ticket'], $opened['request_ref']));
        self::assertSame([], $publisher->publish('final_guard', $receipt, $expected));
        self::assertNull($publisher->authority('public-context'));
    }

    public function testChangedCurrentProfileOrSourceBindingInvalidatesPublication(): void
    {
        [$publisher, $receipt, $expected, $opened, $binding] = $this->publication();
        $publisher->publish('stage', $receipt, $expected);
        $binding['snapshotHash'] = hash('sha256', 'changed-source');
        $changed = $this->sessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'], fn (array $request): array => $binding);
        self::assertSame([], $changed->publish('commit', $receipt, $expected));
        $binding['snapshotHash'] = hash('sha256', 'registered-snapshot');
        $binding['profileFingerprint'] = hash('sha256', 'changed-profile');
        self::assertSame([], $this->sessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'], fn (array $request): array => $binding)->publish('commit', $receipt, $expected));
    }

    private function nativePreparation(?string $negative = null): array
    {
        $opened = $this->open();
        $request = $this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']);
        $fixture = new OfflineContextFixtures(0);
        $fixture->snapshot['conversation']['ref'] = $request['conversationRef'];
        foreach ($fixture->snapshot['sources'] as &$source) {
            $source['conversationRef'] = $request['conversationRef'];
        }
        unset($source);
        $aliases = [];
        $sources = [];
        $trustedProfile = $fixture->profile;
        $publisher = $this->sessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'],
            function (array $request) use ($fixture, &$aliases, &$sources, &$trustedProfile): array {
                $profile = AssistantModelContextProfile::resolve('offline', fn (): array => $trustedProfile ?? $fixture->profile);
                return ['scope' => $fixture->snapshot['scope'],
                    'snapshotHash' => AssistantContextSourceBinding::snapshotHash($fixture->snapshot),
                    'profileFingerprint' => $profile->fingerprint(), 'registryDigest' => $this->registry->manifestDigest(),
                    'aliases' => $aliases, 'sources' => $sources, 'trustedModelProfile' => $trustedProfile];
            });
        $events = [];
        $captured = null;
        $expectedCaptured = null;
        $stageAck = null;
        $service = new AssistantContextPreparationService(
            fn (): array => $fixture->snapshot,
            fn (string $ref, array $snapshot): ?array => $fixture->artifacts[$ref] ?? null,
            fn (string $ref): array => $fixture->profile,
            static fn (string $bytes, array $identity): array => $identity + ['tokens' => strlen($bytes)],
            function (string $event, array $data, array $expected = []) use ($fixture, $publisher, $negative,
                &$aliases, &$sources, &$trustedProfile, &$events, &$captured, &$expectedCaptured, &$stageAck): array {
                $events[] = $event;
                if ($event === 'stage') {
                    foreach ($data['sources'] as $source) {
                        self::assertSame($fixture->snapshot['sources'][$source['sourceRef']], $source['source']);
                    }
                    foreach ($data['aliases'] as $alias) {
                        $segment = AssistantSafeContextSegment::project($alias['artifactRef'], $fixture->snapshot,
                            fn (string $ref, array $snapshot): ?array => $fixture->artifacts[$ref] ?? null);
                        self::assertSame($segment->kind(), $alias['kind']);
                        self::assertSame($segment->fields(), $alias['fields']);
                        self::assertSame($segment->metadata(), $alias['metadata']);
                    }
                    $aliases = AssistantContextSourceBinding::detached($data['aliases']);
                    $sources = AssistantContextSourceBinding::detached($data['sources']);
                    $captured = AssistantContextSourceBinding::detached($data);
                    $expectedCaptured = $expected;
                    if ($negative === 'missing_profile') {
                        $trustedProfile = null;
                    }
                    if ($negative === 'payload') {
                        $data['modelProfile']['modelId'] = 'forged-model';
                        $expected['receiptDigest'] = hash('sha256', AssistantContextSourceBinding::canonical($data));
                    }
                    if ($negative === 'fingerprint') {
                        $data['profileFingerprint'] = hash('sha256', AssistantContextSourceBinding::canonical($data['modelProfile']));
                        $expected['receiptDigest'] = hash('sha256', AssistantContextSourceBinding::canonical($data));
                    }
                }
                if ($event === 'commit' && $negative === 'changed_profile') {
                    $trustedProfile['modelRevision'] = 'changed/2';
                }
                if ($event === 'final_guard' && $negative === 'changed_final_profile') {
                    $trustedProfile['tokenizerRevision'] = 'changed/2';
                }
                $answer = $publisher->publish($event, $data, $expected);
                if ($event === 'stage') {
                    $stageAck = $answer;
                }
                return $answer;
            },
        );
        $result = $service->prepare('offline', $fixture->request());
        return [$result, $events, $captured, $expectedCaptured, $publisher, $stageAck, $fixture];
    }

    public function testFrozenCorePublishesNativePayloadWithOriginalFullProfileFingerprint(): void
    {
        [$result, $events, $receipt, $expected, $publisher, $stageAck, $fixture] = $this->nativePreparation();
        self::assertSame('READY', $result['status']);
        self::assertFalse($result['transportAllowed']);
        self::assertSame('offline-synthetic', $result['mode']);
        self::assertContains('stage', $events);
        self::assertContains('commit', $events);
        self::assertContains('final_guard', $events);
        self::assertSame('staged', $stageAck['status']);
        self::assertCount(8, $receipt['modelProfile']);
        $profile = AssistantModelContextProfile::resolve('offline', fn (): array => $fixture->profile);
        self::assertSame($profile->fingerprint(), $receipt['profileFingerprint']);
        self::assertNotSame(hash('sha256', AssistantContextSourceBinding::canonical($receipt['modelProfile'])), $receipt['profileFingerprint']);
        self::assertSame(hash('sha256', AssistantContextSourceBinding::canonical($receipt)), $expected['receiptDigest']);
        self::assertSame($receipt, $publisher->authority($receipt['contextRef'])['receipt']);
        self::assertSame($expected, $publisher->authority($receipt['contextRef'])['expected']);
    }

    #[DataProvider('nativeProfileDenials')]
    public function testFrozenCoreCannotCommitForgedOrUnavailableCurrentProfile(string $negative): void
    {
        [$result, , $receipt, , $publisher, $stageAck] = $this->nativePreparation($negative);
        self::assertSame('BLOCKED', $result['status']);
        self::assertArrayNotHasKey('payload', $result);
        if (!in_array($negative, ['changed_profile', 'changed_final_profile'], true)) {
            self::assertSame([], $stageAck);
        }
        self::assertNull($publisher->authority($receipt['contextRef']));
    }

    public static function nativeProfileDenials(): array
    {
        return [['payload'], ['fingerprint'], ['missing_profile'], ['changed_profile'], ['changed_final_profile']];
    }

    public function testMissingTrustedFullProfileKeyAndNonNativeReceiptDoNotStage(): void
    {
        [$publisher, $receipt, $expected, $opened, $binding] = $this->publication();
        $fullReceipt = $receipt;
        $fullReceipt['modelProfile'] = $binding['trustedModelProfile'];
        $fullExpected = $expected;
        $fullExpected['receiptDigest'] = hash('sha256', RegisteredPublicFixtureRegistry::canonical($fullReceipt));
        self::assertSame([], $publisher->publish('stage', $fullReceipt, $fullExpected));
        unset($binding['trustedModelProfile']);
        $missing = $this->sessions->publisher(['credential' => 'server-ticket'], $opened['request_ref'], fn (array $request): array => $binding);
        self::assertSame([], $missing->publish('stage', $receipt, $expected));
        self::assertNull($missing->authority($receipt['contextRef']));
    }

    private function qualifiedReadiness(?GatewayModelProfile $selected = null): PublicCoreRuntimeReadiness
    {
        $profile = $selected ?? GatewayModelProfile::fromArray([
            'profileRef' => 'ref_source_test_profile', 'qualification' => 'local-stub', 'adapterRevision' => 'fixture-adapter/1',
            'apiMethod' => 'local_action', 'endpoint' => 'local://public-core-stub', 'modelId' => 'local-action-stub',
            'modelRevision' => 'source/1', 'tokenizerId' => 'local-byte-counter', 'tokenizerRevision' => 'source/1',
            'mappingEvidenceRef' => 'ref_source_mapping_evidence', 'capabilityEvidenceRef' => 'ref_source_capability_evidence',
            'capacityEvidenceRef' => 'ref_source_capacity_evidence', 'contextWindow' => 100000,
            'maxOutputTokens' => 1024, 'answerReserve' => 1024, 'toolReserve' => 2048,
        ]);
        $this->runtimeProof = ['schemaVersion' => 'public-core-runtime-proof/1', 'qualification' => $profile->isActualProfile() ? 'actual' : 'local-source-test',
            'profileFingerprint' => $profile->fingerprint(), 'registryDigest' => $this->registry->manifestDigest(),
            'authorizationFenceEvidenceRef' => 'ref_simulated_fence_evidence', 'identityEvidenceRef' => 'ref_simulated_identity_evidence',
            'channelEvidenceRef' => 'ref_simulated_channel_evidence', 'egressEvidenceRef' => 'ref_simulated_egress_evidence',
            'secretEvidenceRef' => 'ref_simulated_secret_evidence', 'activationRef' => 'ref_simulated_activation_evidence'];
        $this->currentGatewayProfile = $profile;
        return new PublicCoreRuntimeReadiness($this->registry, fn (): ?GatewayModelProfile => $this->currentGatewayProfile,
            fn (): array => $this->runtimeProof,
            static fn (string $bytes, GatewayModelProfile $current): array => ['inputTokens' => strlen($bytes),
                'tokenizerId' => $current->values()['tokenizerId'], 'tokenizerRevision' => $current->values()['tokenizerRevision'],
                'mappingEvidenceRef' => $current->values()['mappingEvidenceRef']]);
    }

    private function dispatchPreparation(?GatewayModelProfile $selected = null): array
    {
        $readiness = $this->qualifiedReadiness($selected);
        $profile = $readiness->qualifiedProfile();
        $opened = $this->open();
        $request = $this->sessions->lookup(['credential' => 'server-ticket'], $opened['request_ref']);
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $this->runtimeSource = ['registryDigest' => $this->registry->manifestDigest(),
            'manifestGenerationRef' => $request['registered']['source_generation_ref'],
            'runtimeGenerationRef' => $corpus->records()[0]->generationRef];
        $this->store->transaction(function (array &$state) use ($request): array {
            $state['requests'][$request['requestRef']]['runtimeGenerationRef'] = $this->runtimeSource['runtimeGenerationRef'];
            return ['bound' => true];
        });
        $fixture = new OfflineContextFixtures(0);
        $fixture->profile = $readiness->coreProfile();
        $fixture->snapshot['conversation']['ref'] = $request['conversationRef'];
        $fixture->snapshot['sources'] = [];
        $fixture->artifacts = [];
        $fixture->addArtifact('system', 'system', 'Answer using only registered public facts.');
        $fixture->addArtifact('current', 'user', $request['registered']['display_text']);
        $aliases = [];
        $sources = [];
        $publisher = $this->sessions->publisher(['credential' => 'server-ticket'], $request['requestRef'],
            function () use ($fixture, $readiness, &$aliases, &$sources): array {
                $trusted = $readiness->coreProfile();
                $nativeProfile = AssistantModelContextProfile::resolve($trusted['profileRef'], fn (): array => $trusted);
                return ['scope' => $fixture->snapshot['scope'], 'snapshotHash' => AssistantContextSourceBinding::snapshotHash($fixture->snapshot),
                    'profileFingerprint' => $nativeProfile->fingerprint(), 'registryDigest' => $this->registry->manifestDigest(),
                    'aliases' => $aliases, 'sources' => $sources, 'trustedModelProfile' => $trusted];
            });
        $service = new AssistantContextPreparationService(fn (): array => $fixture->snapshot,
            fn (string $ref): ?array => $fixture->artifacts[$ref] ?? null, fn (): array => $readiness->coreProfile(),
            fn (string $bytes, array $identity): array => $readiness->count($bytes, $identity),
            function (string $event, array $data, array $expected = []) use ($fixture, $publisher, &$aliases, &$sources): array {
                if ($event === 'stage') {
                    foreach ($data['aliases'] as $alias) {
                        $segment = AssistantSafeContextSegment::project($alias['artifactRef'], $fixture->snapshot,
                            fn (string $ref): ?array => $fixture->artifacts[$ref] ?? null);
                        self::assertSame($segment->fields(), $alias['fields']);
                    }
                    foreach ($data['sources'] as $source) {
                        self::assertSame($fixture->snapshot['sources'][$source['sourceRef']], $source['source']);
                    }
                    $aliases = $data['aliases'];
                    $sources = $data['sources'];
                }
                return $publisher->publish($event, $data, $expected);
            });
        $prepared = $service->prepare($fixture->profile['profileRef'], $fixture->request());
        self::assertSame('READY', $prepared['status']);
        $saved = $publisher->authority($prepared['payload']['contextRef']);
        $authority = ['snapshot' => $fixture->snapshot, 'profile' => $fixture->profile, 'lineage' => $saved['binding']['lineage'],
            'stored' => ['status' => 'committed', 'receipt' => $saved['receipt'], 'digest' => $saved['expected']['receiptDigest']],
            'artifacts' => $fixture->artifacts];
        $native = AssistantContextReceipt::consume($prepared, $authority, $fixture->profile['profileRef']);
        $input = ['schemaVersion' => 'assistant-loop-input/1', 'context' => $native->payload(), 'contextScope' => $native->contextScope(),
            'tools' => [], 'toolReferences' => null, 'repair' => null];
        $dispatch = new PublicCoreDispatchAuthority($publisher, $readiness, $this->sessions, ['viewerTicketRef' => 'ref_server_viewer_ticket'],
            $request['requestRef'], fn (): array => $this->runtimeSource,
            $this->appControlSource(), fn (): int => $this->now, fn (): int => $this->nativePins === null ? $this->monoMs : intdiv(hrtime(true), 1000000),
            $this->nativePins === null ? $this->nativeSource() : null,
            fn (): array => ['predicateRemainingMs' => $this->predicateBudget, 'loopRemainingMs' => $this->loopBudget], $this->controlPins(),
            fn (): int => 1700000120);
        $packet = $dispatch->projectForDispatch($input, $native->privateBinding(), $profile);
        self::assertInstanceOf(GatewayModelRequest::class, $packet);
        return [$dispatch, $packet, $readiness, $publisher, $input, $native->privateBinding()];
    }

    private function controlPins(): array
    {
        return $this->nativePins ?? ['appPeer' => ['pid' => 111, 'uid' => 1001, 'gid' => 1001], 'appChannelRef' => 'channel_app_source_test',
            'gatewayPeer' => ['pid' => 222, 'uid' => 1002, 'gid' => 1002], 'gatewayChannelRef' => 'channel_gateway_source_test'];
    }

    private function appControlSource(): \Closure
    {
        return $this->withAssertions(function (string $command, array $payload, ?GatewayModelRequest $packet, int $expiresAt): array {
            $this->controlCalls[] = $command;
            $tuple = $payload['binding'] ?? null;
            if ($command === 'authorize_write') {
                $disk = json_decode(file_get_contents($this->directory . '/authority.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('consumed', $disk['state']['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['status']);
                $this->appHeld = true;
                $reply = ['schemaVersion' => 'public-core-app-upload-grant/1', 'binding' => $tuple, 'currentViewer' => $this->viewer,
                    'guardRef' => 'ref_simulated_upload_guard', 'coverageEvidenceRef' => 'ref_simulated_guard_coverage', 'uploadTimeoutMs' => $this->grantBudget];
                $replyCommand = 'write_authorized';
            } elseif ($command === 'upload_complete') {
                if ($this->nativePins === null) {
                    self::assertContains($this->nativeEvent, ['uploaded', 'stopped']);
                } else {
                    $event = $this->store->transaction(static fn (array &$state): array => $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['uploadEvent']);
                    self::assertContains($event['event'], ['uploaded', 'stopped']);
                    self::assertSame($event['completionRef'], $payload['completionRef']);
                    if ($this->expectUnsolicitedHandler) {
                        $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12), 'function');
                        self::assertContains('handleGatewayFrame', $functions);
                        self::assertNotContains('cancelUpload', $functions);
                        self::assertSame('stopped', $event['event']);
                        $received = $this->observedNativeProcessor->nativeGatewayTransfer($this->observedNativeAuthority, 'read', $packet);
                        self::assertSame('stopped', $received['event']);
                        self::assertNull($this->observedNativeProcessor->gatewayCompletionRef($this->observedNativeAuthority, $packet, $received));
                        self::assertNull($this->observedNativeProcessor->nativeGatewayTransfer($this->observedNativeAuthority, 'read', clone $packet));
                        $this->unsolicitedHandlerReleases++;
                    }
                }
                self::assertTrue(GatewayModelRequest::isReference($payload['completionRef']));
                self::assertNotNull($this->store->transaction(static fn (): array => ['ledgerReleased' => true]));
                $this->appHeld = false;
                $reply = ['schemaVersion' => 'public-core-app-upload-released/1', 'binding' => $tuple, 'guardRef' => $payload['guardRef']];
                $replyCommand = 'uploaded';
            } elseif ($payload['schemaVersion'] === 'public-core-app-viewer-ticket-check/1') {
                self::assertNull($packet);
                self::assertSame(['schemaVersion', 'viewerTicketRef'], array_keys($payload));
                $reply = ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1', 'viewerTicketRef' => $payload['viewerTicketRef'], 'currentViewer' => $this->viewer];
                $replyCommand = 'binding';
            } else {
                $reply = ['schemaVersion' => 'public-core-app-viewer-binding/1', 'binding' => $tuple, 'currentViewer' => $this->viewer];
                $replyCommand = 'binding';
            }
            $this->monoMs += $this->rpcDelayMs;
            if ($this->nativePins !== null && $this->rpcDelayMs > 0) {
                usleep($this->rpcDelayMs * 1000);
            }
            $result = ['frame' => ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $this->controlPins()['appChannelRef'],
                'sequence' => ++$this->appSequence, 'command' => $replyCommand, 'requestRef' => $packet?->requestRef,
                'attemptRef' => $packet?->attemptRef, 'expiresAt' => $expiresAt, 'payload' => $reply], 'peer' => $this->controlPins()['appPeer']];
            return $this->controlMutator === null ? $result : ($this->controlMutator)($command, $result);
        });
    }

    private function nativeSource(): \Closure
    {
        return $this->withAssertions(function (string $operation, GatewayModelRequest $packet, ?array $cancelRequest = null): ?array {
            if ($operation === 'cancel') {
                self::assertSame(['schemaVersion', 'binding', 'reasonCode'], array_keys($cancelRequest));
                self::assertSame('public-core-gateway-upload-cancel/1', $cancelRequest['schemaVersion']);
                self::assertSame($packet->binding(), $cancelRequest['binding']);
                if (!$this->cancelAllowed) {
                    return null;
                }
                $this->nativeEvent = 'stopped';
            }
            return array_replace(['qualification' => $this->nativeQualification, 'channelRef' => $this->controlPins()['gatewayChannelRef'],
                'transferRef' => 'ref_simulated_transfer_instance', 'requestRef' => $packet->requestRef, 'attemptRef' => $packet->attemptRef,
                'projectionDigest' => $packet->projectionDigest, 'event' => $this->nativeEvent], $this->nativeOverrides);
        });
    }

    private function localGateway(PublicCoreDispatchAuthority $dispatch, PublicCoreRuntimeReadiness $readiness, \Closure $sender): GatewayPublicCoreTransport
    {
        $profile = $readiness->qualifiedProfile();
        return new GatewayPublicCoreTransport($profile, fn (): string => $readiness->qualifiedProfile() === null ? 'runtime_not_activated' : 'none',
            static fn (): string => 'ref_simulated_processor_peer', 'ref_simulated_processor_peer', $dispatch->currentBinding(...),
            $dispatch->withGatewayFence(...), static fn (string $bytes): array => ['inputTokens' => strlen($bytes),
                'tokenizerId' => $profile->values()['tokenizerId'], 'tokenizerRevision' => $profile->values()['tokenizerRevision'],
                'mappingEvidenceRef' => $profile->values()['mappingEvidenceRef']], $this->withAssertions($sender), fn (): int => $this->now);
    }

    private function withAssertions(\Closure $operation): \Closure
    {
        return function (...$arguments) use ($operation): mixed {
            try {
                return $operation(...$arguments);
            } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                $this->callbackFailure ??= $failure;
                throw $failure;
            }
        };
    }

    private function runDispatch(PublicCoreDispatchAuthority $dispatch, GatewayModelRequest $packet, \Closure $operation): GatewayModelResponse
    {
        return $dispatch->withDispatchFence($packet, $this->withAssertions($operation));
    }

    public function testNativeCommittedProjectionUsesSeparateGatewayFingerprintAndSingleDurableAttempt(): void
    {
        [$dispatch, $packet, $readiness] = $this->dispatchPreparation();
        self::assertFalse($readiness->resolve()['model_enabled']);
        self::assertNull($readiness->resolve()['actual_model']);
        self::assertNotSame($packet->profileFingerprint, $packet->corePayloadDigest);
        $writes = 0;
        $gateway = $this->localGateway($dispatch, $readiness, function (GatewayModelProfile $profile, string $bytes) use ($dispatch, $packet, &$writes): array {
            $writes++;
            self::assertSame($packet->bodyBytes, $bytes);
            $disk = json_decode(file_get_contents($this->directory . '/authority.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('consumed', $disk['state']['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['status']);
            self::assertTrue($this->appHeld);
            self::assertNull($this->store->transaction(static fn (): array => ['held' => true]));
            $this->nativeEvent = 'uploaded';
            self::assertSame(['projectionDigest' => $packet->projectionDigest, 'bodyLength' => strlen($packet->bodyBytes)], $dispatch->uploadComplete($packet));
            self::assertFalse($this->appHeld);
            self::assertNotNull($this->store->transaction(static fn (): array => ['released' => true]));
            $this->monoMs += 3000;
            $this->now += 3;
            return ['actionBytes' => GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Find the public price using material.search.']), 'usage' => null];
        });
        self::assertSame('completed', $this->runDispatch($dispatch, $packet, $gateway->send(...))->status);
        self::assertSame('blocked', $this->runDispatch($dispatch, $packet, $gateway->send(...))->status);
        self::assertSame(1, $writes);
    }

    #[DataProvider('dispatchDenials')]
    public function testCurrentDispatchDenialsCauseZeroProviderWrites(string $change): void
    {
        [$dispatch, $packet, $readiness] = $this->dispatchPreparation();
        match ($change) {
            'authorization' => $this->viewer['authorized'] = false,
            'policy' => $this->viewer['policyRevision'] = 'public-policy/2',
            'source' => $this->runtimeSource['runtimeGenerationRef'] = 'ref_lost_runtime_generation',
            'manifest' => $this->runtimeSource['manifestGenerationRef'] = 'ref_other_manifest_generation',
            'profile' => $this->runtimeProof['profileFingerprint'] = str_repeat('0', 64),
            'expiry' => $this->now += 30,
        };
        $writes = 0;
        $gateway = $this->localGateway($dispatch, $readiness, static function () use (&$writes): array {
            $writes++;
            return [];
        });
        self::assertNotSame('completed', $this->runDispatch($dispatch, $packet, $gateway->send(...))->status);
        self::assertSame(0, $writes);
    }

    public static function dispatchDenials(): array
    {
        return [['authorization'], ['policy'], ['source'], ['manifest'], ['profile'], ['expiry']];
    }

    public function testUncertainSendConsumesAttemptAndBlocksNewProjection(): void
    {
        [$dispatch, $packet, $readiness, , $input, $binding] = $this->dispatchPreparation();
        $preparedBeforeSend = $dispatch->projectForDispatch($input, $binding, $readiness->qualifiedProfile());
        self::assertInstanceOf(GatewayModelRequest::class, $preparedBeforeSend);
        $writes = 0;
        $gateway = $this->localGateway($dispatch, $readiness, static function () use (&$writes): never {
            $writes++;
            throw new \RuntimeException('uncertain_send');
        });
        self::assertSame('gateway_unavailable', $this->runDispatch($dispatch, $packet, $gateway->send(...))->reasonCode);
        self::assertSame('blocked', $this->runDispatch($dispatch, $packet, $gateway->send(...))->status);
        self::assertSame('blocked', $this->runDispatch($dispatch, $preparedBeforeSend, $gateway->send(...))->status);
        self::assertIsArray($dispatch->projectForDispatch($input, $binding, $readiness->qualifiedProfile()));
        self::assertSame(1, $writes);
    }

    public function testTamperedNativePayloadAndInScopeProfileCannotMintAnotherProjection(): void
    {
        [$dispatch, , $readiness, , $input, $binding] = $this->dispatchPreparation();
        $input['context']['messages'][0]['content'] = 'arbitrary replacement';
        self::assertIsArray($dispatch->projectForDispatch($input, $binding, $readiness->qualifiedProfile()));
        self::assertSame('receipt_changed', $dispatch->projectForDispatch($input, $binding, $readiness->qualifiedProfile())['reasonCode']);
    }

    private function nativeFixtureProfile(): GatewayModelProfile
    {
        return GatewayModelProfile::fromArray(array_replace($this->qualifiedReadiness()->qualifiedProfile()->values(), [
            'qualification' => 'actual', 'apiMethod' => 'chat_completions', 'endpoint' => GatewayPublicCoreHttpSender::ENDPOINT,
            'modelId' => 'source-fixture-model', 'modelRevision' => 'source-fixture-v1', 'tokenizerId' => 'source-fixture-tokenizer',
        ]));
    }

    #[DataProvider('nativeProcessorCases')]
    public function testProcessorComposesActualReceivedLifecycleWithoutManualProof(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork') || !extension_loaded('curl')) {
            self::markTestSkipped('Native composition requires isolated Linux SCM credentials and cURL; Windows is not proof.');
        }
        mkdir($this->directory, 0700);
        $this->now = time();
        $profile = $this->nativeFixtureProfile();
        $configuration = $this->directory . '/openssl.cnf';
        file_put_contents($configuration, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:api.timeweb.ai\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'api.timeweb.ai'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['config' => $configuration, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privateKey);
        openssl_x509_export($certificate, $publicCertificate);
        $pem = $this->directory . '/server.pem';
        $ca = $this->directory . '/ca.pem';
        file_put_contents($pem, $privateKey . $publicCertificate);
        file_put_contents($ca, $publicCertificate);
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]));
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $tls = pcntl_fork();
        if ($tls === 0) {
            try {
                $connection = @stream_socket_accept($server, 4);
                if ($connection === false) {
                    file_put_contents($this->directory . '/received', '0');
                    exit(0);
                }
                stream_set_timeout($connection, 5);
                if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    exit(61);
                }
                $headers = '';
                while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 16384) {
                    $headers .= fread($connection, 1);
                }
                if (preg_match('/Content-Length: (\d+)/i', $headers, $match) !== 1) {
                    exit(62);
                }
                if ($mode === 'unsolicited') {
                    file_put_contents($this->directory . '/received', '0');
                    fclose($connection);
                    fclose($server);
                    exit(0);
                }
                $received = '';
                while (strlen($received) < (int) $match[1] && !feof($connection)) {
                    $part = fread($connection, min(4096, (int) $match[1] - strlen($received)));
                    if ($part === false || $part === '') {
                        break;
                    }
                    $received .= $part;
                }
                file_put_contents($this->directory . '/received', (string) strlen($received));
                if ($mode === 'full') {
                    file_put_contents($this->directory . '/body', $received);
                    usleep(2300000);
                    $payload = GatewayModelRequest::canonicalJson(['id' => 'source-fixture-completion', 'object' => 'chat.completion',
                        'created' => time(), 'model' => 'source-fixture-model', 'choices' => [['index' => 0,
                            'message' => ['role' => 'assistant', 'content' => '{"type":"plan","plan":"Read public facts."}'], 'finish_reason' => 'stop']]]);
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n" . $payload);
                }
                fclose($connection);
                fclose($server);
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($this->directory . '/tls-error', $failure->getMessage());
                exit(63);
            }
        }
        self::assertGreaterThan(0, $tls);
        fclose($server);
        $path = $this->directory . '/native.sock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $parent = getmypid();
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $gateway = pcntl_fork();
        if ($gateway === 0) {
            $retained = null;
            $weakHandle = null;
            try {
                $channel = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 30000);
                $sender = new GatewayPublicCoreHttpSender(static fn (): string => 'source-fixture-no-provider-credential', null,
                    function ($handle) use ($port, $ca, $mode, $channel, &$sender, &$retained, &$weakHandle): void {
                        $weakHandle = \WeakReference::create($handle);
                        curl_setopt($handle, CURLOPT_CONNECT_TO, ['api.timeweb.ai:443:127.0.0.1:' . $port]);
                        curl_setopt($handle, CURLOPT_CAINFO, $ca);
                        if ($mode !== 'full') {
                            curl_setopt($handle, CURLOPT_MAX_SEND_SPEED_LARGE, 512);
                        }
                        if ($mode === 'uncertain') {
                            $retained = $handle;
                        }
                        if ($mode === 'unsolicited') {
                            foreach ([$sender, new GatewayPublicCoreHttpSender()] as $unqualified) {
                                try {
                                    $channel->publishGatewayLifecycle($unqualified, 'stopped');
                                    throw new \RuntimeException('Pending/unbound sender published a native event');
                                } catch (\LogicException $failure) {
                                    self::assertSame('gateway_channel_unavailable', $failure->getMessage());
                                }
                            }
                            file_put_contents($this->directory . '/origin-denials', 'pending/unbound denied');
                        }
                    }, $mode === 'late' ? static function ($multi, $handle): int {
                        usleep(350000);
                        return curl_multi_remove_handle($multi, $handle);
                    } : null);
                $response = GatewayPublicCoreTransport::handleAuthenticatedChannel($channel, $profile, $sender,
                    static fn (): array => ['inputTokens' => 42, 'tokenizerId' => $profile->values()['tokenizerId'],
                        'tokenizerRevision' => $profile->values()['tokenizerRevision'], 'mappingEvidenceRef' => $profile->values()['mappingEvidenceRef']],
                    static fn (): string => 'none');
                file_put_contents($this->directory . '/gateway-status', $response->status);
                if ($mode === 'unsolicited') {
                    self::assertNull($weakHandle->get());
                    foreach (['stopped', 'uploaded'] as $duplicate) {
                        try {
                            $channel->publishGatewayLifecycle($sender, $duplicate);
                            throw new \RuntimeException('Terminal event published again');
                        } catch (\LogicException $failure) {
                            self::assertSame('gateway_channel_unavailable', $failure->getMessage());
                        }
                    }
                    file_put_contents($this->directory . '/one-shot-denials', 'duplicate/conflicting denied');
                }
                $retained = null;
                $channel->close();
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($this->directory . '/gateway-error', $failure->getMessage());
                exit(64);
            }
        }
        self::assertGreaterThan(0, $gateway);
        $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $gateway], 30000);
        $this->nativePins = ['appPeer' => ['pid' => 111, 'uid' => 1001, 'gid' => 1001], 'appChannelRef' => 'channel_app_source_test',
            'gatewayPeer' => $channel->peer(), 'gatewayChannelRef' => $channel->channelRef()];
        if ($mode !== 'full') {
            $this->grantBudget = 200;
        }
        if ($mode === 'unsolicited') {
            $this->grantBudget = 1500;
            $this->expectUnsolicitedHandler = true;
        }
        if ($mode === 'aged') {
            $this->rpcDelayMs = 120;
        }
        [$authority, $packet, $readiness] = $this->dispatchPreparation($profile);
        $processor = new PublicCoreProcessor($this->registry, $this->store, $this->sessions, $readiness);
        if ($mode === 'unsolicited') {
            $this->observedNativeProcessor = $processor;
            $this->observedNativeAuthority = $authority;
        }
        $started = hrtime(true);
        try {
            $response = $processor->dispatchGateway($authority, $channel, $packet);
        } finally {
            $channel->close();
            socket_close($listener);
            pcntl_waitpid($gateway, $gatewayStatus);
            pcntl_waitpid($tls, $tlsStatus);
        }
        self::assertTrue(pcntl_wifexited($gatewayStatus));
        self::assertSame(0, pcntl_wexitstatus($gatewayStatus), is_file($this->directory . '/gateway-error') ? file_get_contents($this->directory . '/gateway-error') : '');
        self::assertTrue(pcntl_wifexited($tlsStatus));
        self::assertSame(0, pcntl_wexitstatus($tlsStatus), is_file($this->directory . '/tls-error') ? file_get_contents($this->directory . '/tls-error') : '');
        $state = json_decode(file_get_contents($this->directory . '/authority.json'), true, flags: JSON_THROW_ON_ERROR)['state'];
        $attempt = $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef];
        self::assertSame('consumed', $attempt['status']);
        self::assertNull($processor->nativeGatewayTransfer($authority, 'read', $packet));
        self::assertNull($processor->gatewayCompletionRef($authority, $packet, ['event' => 'uploaded']));
        self::assertNotSame('completed', $processor->dispatchGateway($authority, $channel, $packet)->status);
        if ($mode === 'full') {
            self::assertSame('completed', $response->status);
            self::assertGreaterThan(2000000000, hrtime(true) - $started);
            self::assertSame($packet->bodyBytes, file_get_contents($this->directory . '/body'));
        } else {
            self::assertNotSame('completed', $response->status);
            self::assertLessThan(strlen($packet->bodyBytes), (int) file_get_contents($this->directory . '/received'));
        }
        if (in_array($mode, ['uncertain', 'late', 'aged'], true)) {
            self::assertArrayNotHasKey('uploadEvent', $attempt);
            self::assertTrue($this->appHeld);
            self::assertTrue($authority->uploadPending());
            self::assertNotContains('upload_complete', $this->controlCalls);
        } else {
            self::assertSame($mode === 'full' ? 'uploaded' : 'stopped', $attempt['uploadEvent']['event']);
            self::assertTrue(GatewayModelRequest::isReference($attempt['uploadEvent']['completionRef']));
            self::assertFalse($this->appHeld);
            self::assertFalse($authority->uploadPending());
            self::assertSame(1, count(array_filter($this->controlCalls, static fn (string $command): bool => $command === 'upload_complete')));
        }
        if ($mode === 'unsolicited') {
            self::assertSame(1, $this->unsolicitedHandlerReleases);
            self::assertSame('pending/unbound denied', file_get_contents($this->directory . '/origin-denials'));
            self::assertSame('duplicate/conflicting denied', file_get_contents($this->directory . '/one-shot-denials'));
        }
    }

    public static function nativeProcessorCases(): array
    {
        return [['full'], ['stop'], ['uncertain'], ['late'], ['aged'], 'genuine unsolicited stopped handler' => ['unsolicited']];
    }

    #[DataProvider('nativeProcessorDenials')]
    public function testProcessorRejectsInvalidSameTransferFramesAndEof(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Native peer/frame denial requires isolated Linux SCM credentials.');
        }
        mkdir($this->directory, 0700);
        $this->now = time();
        $path = $this->directory . '/denial.sock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $parent = getmypid();
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $child = pcntl_fork();
        if ($child === 0) {
            try {
                $stream = stream_socket_client('unix://' . $path, $error, $message, 3);
                stream_set_timeout($stream, 3);
                $hello = self::readNativeTestFrame($stream);
                fwrite($stream, self::nativeTestWire($hello));
                $dispatch = self::readNativeTestFrame($stream);
                $request = GatewayModelRequest::fromArray($dispatch['payload']);
                $frame = ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $hello['channelRef'], 'sequence' => 2,
                    'command' => 'authorize_write', 'requestRef' => $request->requestRef, 'attemptRef' => $request->attemptRef,
                    'expiresAt' => $request->expiresAt, 'payload' => ['projectionDigest' => $request->projectionDigest, 'profileFingerprint' => $request->profileFingerprint]];
                if (in_array($mode, ['eof', 'body', 'binding', 'replay'], true)) {
                    fwrite($stream, self::nativeTestWire($frame));
                    self::readNativeTestFrame($stream);
                    $frame['sequence'] = 3;
                    $frame['command'] = 'upload_complete';
                    $frame['payload'] = ['projectionDigest' => $request->projectionDigest, 'bodyLength' => strlen($request->bodyBytes)];
                }
                match ($mode) {
                    'channel' => $frame['channelRef'] = 'channel_other_transfer',
                    'attempt' => $frame['attemptRef'] = 'ref_other_attempt',
                    'sequence' => $frame['sequence'] = 99,
                    'expiry' => $frame['expiresAt'] = $request->expiresAt - 1,
                    'body' => $frame['payload']['bodyLength']++,
                    'binding' => $frame['payload']['projectionDigest'] = str_repeat('0', 64),
                    'replay' => $frame['sequence'] = 2,
                    'early' => [$frame['command'] = 'upload_complete', $frame['payload'] = ['projectionDigest' => $request->projectionDigest, 'bodyLength' => strlen($request->bodyBytes)]],
                    default => null,
                };
                if ($mode !== 'eof') {
                    fwrite($stream, self::nativeTestWire($frame));
                }
                fclose($stream);
                exit(0);
            } catch (\Throwable $failure) {
                if ($mode === 'role') {
                    exit(0);
                }
                file_put_contents($this->directory . '/denial-error', $failure->getMessage());
                exit(65);
            }
        }
        self::assertGreaterThan(0, $child);
        $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 30000);
        $this->nativePins = ['appPeer' => ['pid' => 111, 'uid' => 1001, 'gid' => 1001], 'appChannelRef' => 'channel_app_source_test',
            'gatewayPeer' => $channel->peer(), 'gatewayChannelRef' => $channel->channelRef()];
        if ($mode === 'role') {
            $this->nativePins['gatewayPeer']['pid']++;
        }
        [$authority, $packet, $readiness] = $this->dispatchPreparation();
        $processor = new PublicCoreProcessor($this->registry, $this->store, $this->sessions, $readiness);
        try {
            $response = $processor->dispatchGateway($authority, $channel, $packet);
        } finally {
            $channel->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status), is_file($this->directory . '/denial-error') ? file_get_contents($this->directory . '/denial-error') : '');
        self::assertNotSame('completed', $response->status);
        self::assertNotContains('upload_complete', $this->controlCalls);
        $disk = json_decode(file_get_contents($this->directory . '/authority.json'), true, flags: JSON_THROW_ON_ERROR)['state'];
        $attempt = $disk['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef];
        self::assertArrayNotHasKey('uploadEvent', $attempt);
        self::assertSame($mode === 'role' ? 'prepared' : 'consumed', $attempt['status']);
        self::assertSame(in_array($mode, ['eof', 'body', 'binding', 'replay'], true), $authority->uploadPending());
        self::assertNull($processor->nativeGatewayTransfer($authority, 'read', $packet));
    }

    public static function nativeProcessorDenials(): array
    {
        return array_map(static fn (string $mode): array => [$mode], ['channel', 'attempt', 'sequence', 'expiry', 'early', 'role', 'body', 'binding', 'replay', 'eof']);
    }

    private static function readNativeTestFrame($stream): array
    {
        $read = static function (int $length) use ($stream): string {
            $bytes = '';
            while (strlen($bytes) < $length) {
                $part = fread($stream, $length - strlen($bytes));
                if ($part === false || $part === '') {
                    throw new \LogicException('closed native fixture');
                }
                $bytes .= $part;
            }
            return $bytes;
        };
        $length = unpack('Nlength', $read(4))['length'];
        return json_decode($read($length), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function nativeTestWire(array $frame): string
    {
        $bytes = GatewayModelRequest::canonicalJson($frame);
        return pack('N', strlen($bytes)) . $bytes;
    }

    public function testActualProfileCannotUseCallerProvidedNativeReaderAsProof(): void
    {
        [$authority, $packet] = $this->dispatchPreparation($this->nativeFixtureProfile());
        $this->nativeQualification = 'actual-native';
        self::assertNotSame('completed', $this->runDispatch($authority, $packet, function () use ($authority, $packet): GatewayModelResponse {
            self::assertSame(['reasonCode' => 'gateway_channel_unavailable'], $authority->authorizeWrite($packet));
            self::assertSame([], $this->controlCalls);
            return $this->planResponse($packet);
        })->status);
        self::assertFalse($this->appHeld);
    }

    #[DataProvider('normalChannelCases')]
    public function testNormalChannelKeepsClosedCorrelationFreshBootstrapAndOriginalRequest(string $mode): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Normal source Channel requires isolated Linux SCM credentials.');
        }
        mkdir($this->directory, 0700);
        $this->now = time() - ($mode === 'original-cap' ? 110 : 0);
        $path = $this->directory . '/normal.sock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $parent = getmypid();
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $ticket = 'viewer_' . str_repeat('a', 48);
        $viewer = $this->viewer;
        $child = pcntl_fork();
        if ($child === 0) {
            try {
                $channel = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 30000);
                $expiry = min(time() + 20, $channel->deadlineExpiresAt());
                $checks = 0;
                $calls = 0;
                $policyChanged = false;
                $invoke = static function (string $command, array $input, ?string $operation = null) use ($channel, $expiry, $ticket, $viewer, $mode, &$checks, &$calls, &$policyChanged): array {
                    $operation ??= 'ref_' . str_pad((string) ++$calls, 32, '0', STR_PAD_LEFT);
                    $channel->send($command, in_array($command, ['readiness', 'open_or_resume'], true) ? $operation : $input['request_ref'],
                        $operation, ['schemaVersion' => 'public-core-processor-operation/1-proposal', 'operationRef' => $operation, 'input' => $input], $expiry);
                    while (true) {
                        $frame = $channel->receive();
                        self::assertSame($expiry, $frame['expiresAt']);
                        if ($frame['command'] === 'check_binding') {
                            $checks++;
                            self::assertNull($frame['requestRef']);
                            self::assertNull($frame['attemptRef']);
                            self::assertSame(['schemaVersion' => 'public-core-app-viewer-ticket-check/1', 'viewerTicketRef' => $ticket], $frame['payload']);
                            $current = $viewer;
                            if ($policyChanged) {
                                $current['policyRevision'] = 'public-policy/2';
                            }
                            if ($mode === 'revoked' && $checks >= 2) {
                                $channel->send('binding', null, null, ['schemaVersion' => 'public-core-app-viewer-ticket-denial/1',
                                    'viewerTicketRef' => $ticket, 'reasonCode' => 'authorization_changed'], $expiry);
                            } else {
                                $channel->send('binding', null, null, ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1',
                                    'viewerTicketRef' => $ticket, 'currentViewer' => $current], $expiry);
                            }
                            continue;
                        }
                        self::assertSame('result', $frame['command']);
                        self::assertSame($operation, $frame['attemptRef']);
                        self::assertSame($operation, $frame['payload']['operationRef']);
                        self::assertSame('public-core-processor-operation-result/1-proposal', $frame['payload']['schemaVersion']);
                        self::assertTrue(GatewayModelRequest::hasExactKeys($frame['payload'], ['schemaVersion', 'operationRef', 'output']));
                        return $frame['payload']['output'];
                    }
                };
                $ready = $invoke('readiness', []);
                self::assertSame('unavailable', $ready['status']);
                self::assertFalse($ready['model_enabled']);
                self::assertNull($ready['actual_model']);
                $input = ['viewer_ticket_ref' => $ticket, 'fixture_id' => 'material-search-v1', 'fixture_version' => 'public-material/1',
                    'input_id' => 'price-b25', 'request_id' => 'cc5b0d36-5c63-4a8c-bcfa-53c41e43ed0c', 'public_session_ref' => null];
                $opened = $invoke('open_or_resume', $input);
                if (in_array($mode, ['revoked', 'original-cap'], true)) {
                    self::assertSame('blocked', $opened['status']);
                    self::assertGreaterThanOrEqual(2, $checks);
                } else {
                    self::assertTrue(GatewayModelRequest::hasExactKeys($opened, ['status', 'reasonCode', 'request_ref', 'public_session_ref',
                        'transportAllowed', 'process_ref', 'original_expires_at']));
                    self::assertSame('accepted', $opened['status']);
                    self::assertGreaterThan($expiry, $opened['original_expires_at']);
                    if (in_array($mode, ['stale-process', 'cached-raw'], true)) {
                        file_put_contents($this->directory . '/normal-tamper', $mode);
                        $denied = $mode === 'stale-process' ? $invoke('open_or_resume', $input)
                            : $invoke('lookup_owned', ['viewer_ticket_ref' => $ticket, 'request_ref' => $opened['request_ref']]);
                        self::assertSame('blocked', $denied['status']);
                        self::assertArrayNotHasKey('reply', $denied);
                        self::assertArrayNotHasKey('trace', $denied);
                        self::assertArrayNotHasKey('publicationRef', $denied);
                        file_put_contents($this->directory . '/normal-checks', (string) $checks);
                        $channel->close();
                        exit(0);
                    }
                    if ($mode === 'policy-change') {
                        $policyChanged = true;
                        self::assertSame('blocked', $invoke('open_or_resume', $input)['status']);
                        file_put_contents($this->directory . '/normal-checks', (string) $checks);
                        $channel->close();
                        exit(0);
                    }
                    self::assertSame($opened, $invoke('open_or_resume', $input));
                    $owned = ['viewer_ticket_ref' => $ticket, 'request_ref' => $opened['request_ref']];
                    $pending = $invoke('lookup_owned', $owned);
                    self::assertSame(['reasonCode' => 'none', 'request_ref' => $opened['request_ref'], 'status' => 'accepted', 'transportAllowed' => false], $pending);
                    $blocked = $invoke('execute_owned', $owned);
                    self::assertSame('blocked', $blocked['status']);
                    self::assertSame('runtime_not_activated', $blocked['reasonCode']);
                    self::assertArrayNotHasKey('reply', $blocked);
                    self::assertArrayNotHasKey('publicationRef', $blocked);
                    self::assertGreaterThan(8, $checks);
                    if ($mode === 'duplicate') {
                        try {
                            $invoke('readiness', [], 'ref_' . str_pad('1', 32, '0', STR_PAD_LEFT));
                            throw new \RuntimeException('Duplicate operation accepted');
                        } catch (\LogicException) {
                        }
                    }
                }
                file_put_contents($this->directory . '/normal-checks', (string) $checks);
                $channel->close();
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($this->directory . '/normal-error', $failure->getMessage());
                exit(77);
            }
        }
        self::assertGreaterThan(0, $child);
        $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 30000);
        $processor = null;
        $reader = static function (array $binding) use (&$processor): ?array {
            return $processor?->currentNormalViewer($binding);
        };
        $sessions = new PublicCoreSessionAuthority($this->registry, $this->store, $reader, fn (): int => $this->now);
        $factoryCalls = 0;
        $readiness = $this->qualifiedReadiness();
        $peer = $channel->peer();
        $processor = new PublicCoreProcessor($this->registry, $this->store, $sessions, $readiness,
            function (array $kernel) use ($peer, $mode): ?array {
                if (is_file($this->directory . '/normal-tamper')) {
                    $this->store->transaction(static function (array &$state) use ($mode): array {
                        foreach ($state['requests'] as &$request) {
                            if ($mode === 'stale-process') {
                                $request['normalProcessRef'] = 'ref_' . str_repeat('f', 32);
                            } else {
                                $request['execution'] = ['status' => 'completed', 'result' => ['reply' => 'private cached bytes']];
                            }
                        }
                        return ['sourceFixtureTamper' => true];
                    });
                }
                return $kernel === $peer ? ['role' => 'app', 'identityRef' => 'ref_source_only_app_role', 'kernelPeer' => $kernel] : null;
            },
            static fn (string $ticket): array => ['viewerTicketRef' => $ticket],
            static function () use (&$factoryCalls): array { $factoryCalls++; return []; });
        try {
            $processor->serveAppChannel($channel);
        } finally {
            $channel->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status), is_file($this->directory . '/normal-error') ? file_get_contents($this->directory . '/normal-error') : '');
        self::assertSame(0, $factoryCalls);
        self::assertGreaterThanOrEqual(2, (int) file_get_contents($this->directory . '/normal-checks'));
        $snapshot = $this->store->transaction(static fn (array &$state): array => $state['requests']);
        self::assertIsArray($snapshot);
        if (in_array($mode, ['valid', 'duplicate'], true)) {
            self::assertCount(1, $snapshot);
            $stored = array_values($snapshot)[0];
            self::assertSame($this->now + 120, $stored['expiresAt']);
            self::assertMatchesRegularExpression('/^ref_[a-f0-9]{32}$/D', $stored['normalProcessRef']);
            self::assertArrayNotHasKey('execution', $stored);
        }
    }

    public static function normalChannelCases(): array
    {
        return [['valid'], ['duplicate'], ['revoked'], ['original-cap'], ['policy-change'], ['stale-process'], ['cached-raw']];
    }

    #[DataProvider('normalProtocolDenials')]
    public function testNormalProtocolDeniesMalformedRolePhaseAndReentry(string $change): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !function_exists('pcntl_fork')) {
            self::markTestSkipped('Normal protocol denial requires native isolated Linux.');
        }
        mkdir($this->directory, 0700);
        $this->now = time();
        $path = $this->directory . '/normal-deny.sock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $parent = getmypid();
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $child = pcntl_fork();
        if ($child === 0) {
            try {
                $channel = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parent], 30000);
                $op = 'ref_' . str_repeat('c', 32);
                $command = 'readiness';
                $request = $attempt = $op;
                $payload = ['schemaVersion' => 'public-core-processor-operation/1-proposal', 'operationRef' => $op, 'input' => []];
                switch ($change) {
                    case 'missing': unset($payload['operationRef']); break;
                    case 'extra': $payload['callerProof'] = true; break;
                    case 'schema': $payload['schemaVersion'] = 'public-core-processor-operation/2'; break;
                    case 'operation-type': $payload['operationRef'] = 5; break;
                    case 'operation-ref': $payload['operationRef'] = 'ref_source_foreign'; break;
                    case 'input-type': $payload['input'] = 'ready'; break;
                    case 'readiness-input': $payload['input'] = ['role' => 'app']; break;
                    case 'request-ref': $request = 'ref_' . str_repeat('d', 32); break;
                    case 'attempt-ref': $attempt = 'ref_' . str_repeat('d', 32); break;
                    case 'native-command': $command = 'authorize_write'; break;
                    case 'publication-output': $command = 'result'; break;
                    case 'ticket-type':
                        $command = 'lookup_owned';
                        $request = 'ref_' . str_repeat('d', 32);
                        $payload['input'] = ['viewer_ticket_ref' => 'ref_manual_ticket', 'request_ref' => $request];
                        break;
                    case 'bootstrap-expiry':
                    case 'bootstrap-phase':
                    case 'open-extra':
                        $command = 'open_or_resume';
                        $payload['input'] = ['viewer_ticket_ref' => 'viewer_' . str_repeat('a', 48), 'fixture_id' => 'material-search-v1',
                            'fixture_version' => 'public-material/1', 'input_id' => 'price-b25', 'request_id' => 'cc5b0d36-5c63-4a8c-bcfa-53c41e43ed0c',
                            'public_session_ref' => null];
                        if ($change === 'open-extra') {
                            $payload['input']['original_expires_at'] = time() + 120;
                        }
                        break;
                }
                $channel->send($command, $request, $attempt, $payload, min(time() + 20, $channel->deadlineExpiresAt()));
                if (in_array($change, ['bootstrap-expiry', 'bootstrap-phase'], true)) {
                    $check = $channel->receive();
                    self::assertSame('check_binding', $check['command']);
                    if ($change === 'bootstrap-expiry') {
                        $channel->send('binding', null, null, ['schemaVersion' => 'public-core-app-viewer-ticket-binding/1',
                            'viewerTicketRef' => $check['payload']['viewerTicketRef'], 'currentViewer' => [
                                'authorized' => true, 'viewerRef' => 'source-viewer', 'organizationRef' => 'source-org',
                                'authorizationRevision' => 'source-auth1', 'policyRevision' => 'source-policy1']], $check['expiresAt'] - 1);
                    } else {
                        $channel->send('readiness', $op, $op, $payload, $check['expiresAt']);
                    }
                }
                try {
                    $channel->receive();
                    throw new \RuntimeException('Invalid normal operation got a result');
                } catch (\LogicException) {
                }
                $channel->close();
                exit(0);
            } catch (\Throwable $failure) {
                file_put_contents($this->directory . '/normal-deny-error', $failure->getMessage());
                exit(78);
            }
        }
        self::assertGreaterThan(0, $child);
        $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 30000);
        $peer = $channel->peer();
        $processor = null;
        $identity = function (array $kernel) use (&$processor, $peer, $change): ?array {
            if ($change === 'reentry') {
                self::assertSame('blocked', $processor->handle('readiness', [], $kernel)['status']);
            }
            return ['role' => $change === 'role' ? 'gateway' : 'app', 'identityRef' => 'ref_source_normal_role',
                'kernelPeer' => $change === 'peer' ? ['pid' => $peer['pid'] + 1, 'uid' => $peer['uid'], 'gid' => $peer['gid']] : $kernel];
        };
        $processor = new PublicCoreProcessor($this->registry, $this->store, $this->sessions, $this->qualifiedReadiness(),
            $change === 'missing-factory' ? null : $identity);
        try {
            $processor->serveAppChannel($channel);
        } finally {
            $channel->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status), is_file($this->directory . '/normal-deny-error') ? file_get_contents($this->directory . '/normal-deny-error') : '');
        self::assertSame([], $this->store->transaction(static fn (array &$state): array => $state['requests']));
    }

    public static function normalProtocolDenials(): array
    {
        return array_map(static fn (string $mode): array => [$mode], ['missing', 'extra', 'schema', 'operation-type', 'operation-ref',
            'input-type', 'readiness-input', 'request-ref', 'attempt-ref', 'native-command', 'publication-output', 'ticket-type',
            'open-extra', 'role', 'peer', 'reentry', 'missing-factory', 'bootstrap-expiry', 'bootstrap-phase']);
    }

    private function gatewayFrame(GatewayModelRequest $packet, int $sequence, string $command, array $payload): array
    {
        return ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $this->controlPins()['gatewayChannelRef'],
            'sequence' => $sequence, 'command' => $command, 'requestRef' => $packet->requestRef,
            'attemptRef' => $packet->attemptRef, 'expiresAt' => $packet->expiresAt, 'payload' => $payload];
    }

    private function planResponse(GatewayModelRequest $packet): GatewayModelResponse
    {
        return GatewayModelResponse::completed($packet, GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Read public facts.']), null);
    }

    public function testGrantSubtractsFullRpcAndProcessingWithoutShorteningGenuineResponseExpiry(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->rpcDelayMs = 250;
        $this->grantBudget = 1000;
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            $grant = $dispatch->authorizeWrite($packet);
            self::assertSame(['schemaVersion', 'binding', 'uploadTimeoutMs'], array_keys($grant));
            self::assertSame('public-core-gateway-upload-grant/1', $grant['schemaVersion']);
            self::assertSame($packet->binding(), $grant['binding']);
            self::assertSame(750, $grant['uploadTimeoutMs']);
            $this->monoMs += 100;
            self::assertSame(650, $dispatch->remainingUploadMs());
            $this->nativeEvent = 'uploaded';
            self::assertArrayNotHasKey('reasonCode', $dispatch->uploadComplete($packet));
            $this->monoMs += 3000;
            $this->now += 3;
            self::assertSame(1700000030, $packet->expiresAt);
            self::assertFalse($this->appHeld);
            return $this->planResponse($packet);
        });
        self::assertSame('completed', $response->status);
    }

    #[DataProvider('invalidUploadBudgets')]
    public function testUnknownNonpositiveMalformedOrOverflowBudgetHasNoWriteAllowance(mixed $budget): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->grantBudget = $budget;
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            $grant = $dispatch->authorizeWrite($packet);
            self::assertSame(['reasonCode'], array_keys($grant));
            self::assertFalse($this->appHeld);
            return GatewayModelResponse::blocked($packet, $grant['reasonCode']);
        });
        self::assertNotSame('completed', $response->status);
        self::assertNotNull($this->store->transaction(static fn (): array => ['notHeld' => true]));
    }

    public static function invalidUploadBudgets(): array
    {
        return [[null], [0], [-1], ['2000'], [2000.0], [PHP_INT_MAX], [[]]];
    }

    public function testNearExpiryGrantCannotResetBudget(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->grantBudget = 100;
        $this->rpcDelayMs = 101;
        self::assertNotSame('completed', $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('expired', $dispatch->authorizeWrite($packet)['reasonCode']);
            return GatewayModelResponse::blocked($packet, 'expired');
        })->status);
        self::assertFalse($this->appHeld);
    }

    private function bootstrapAuthority(): PublicCoreDispatchAuthority
    {
        return new PublicCoreDispatchAuthority($this->store, $this->qualifiedReadiness(), appControl: $this->appControlSource(),
            clock: fn (): int => $this->now, controlPins: $this->controlPins(), bootstrapExpiry: static fn (): int => 1700000120);
    }

    public function testTicketBootstrapAuthenticatesBeforeOwnedTupleAndNeverGrantsUpload(): void
    {
        $authority = $this->bootstrapAuthority();
        self::assertSame($this->viewer, $authority->bootstrapViewer('ref_server_viewer_ticket'));
        self::assertSame(['check_binding'], $this->controlCalls);
        self::assertFalse($this->appHeld);
        $this->viewer['authorized'] = false;
        self::assertNull($authority->bootstrapViewer('ref_server_viewer_ticket'));
    }

    #[DataProvider('invalidBootstrapControls')]
    public function testBootstrapRolePhaseSchemaAndReplayConfusionIsDenied(string $change): void
    {
        $authority = $this->bootstrapAuthority();
        $this->controlMutator = function (string $command, array $result) use ($change): array {
            switch ($change) {
                case 'role': $result['peer'] = $this->controlPins()['gatewayPeer']; break;
                case 'channel': $result['frame']['channelRef'] = $this->controlPins()['gatewayChannelRef']; break;
                case 'sequence': $result['frame']['sequence'] = 1; break;
                case 'expiry': $result['frame']['expiresAt'] = $this->now; break;
                case 'owned_ref': $result['frame']['requestRef'] = 'ref_unowned_request'; break;
                case 'grant': $result['frame']['payload'] = ['schemaVersion' => 'public-core-app-upload-grant/1', 'binding' => [],
                    'currentViewer' => $this->viewer, 'guardRef' => 'ref_manual_guard', 'coverageEvidenceRef' => 'ref_manual_coverage', 'uploadTimeoutMs' => 2000]; break;
                case 'native12': $result['frame']['payload'] = array_fill_keys(['requestRef', 'attemptRef', 'publicAdmissionRef', 'contextReceiptRef',
                    'corePayloadDigest', 'coreReceiptDigest', 'projectionRef', 'projectionDigest', 'profileRef', 'profileFingerprint', 'purpose', 'expiresAt'], null); break;
            }
            return $result;
        };
        self::assertNull($authority->bootstrapViewer('ref_server_viewer_ticket'));
        self::assertNotContains('authorize_write', $this->controlCalls);
    }

    public static function invalidBootstrapControls(): array
    {
        return [['role'], ['channel'], ['sequence'], ['expiry'], ['owned_ref'], ['grant'], ['native12']];
    }

    public function testManualUploadAndStoppedAckCannotCreatePrivateCompletionReference(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $peer = $this->controlPins()['gatewayPeer'];
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet, $peer): GatewayModelResponse {
            $payload = ['projectionDigest' => $packet->projectionDigest, 'profileFingerprint' => $packet->profileFingerprint];
            self::assertSame($packet->binding(), $dispatch->handleGatewayFrame($this->gatewayFrame($packet, 2, 'check_binding', $payload), $peer, $packet));
            $grant = $dispatch->handleGatewayFrame($this->gatewayFrame($packet, 3, 'authorize_write', $payload), $peer, $packet);
            self::assertSame('public-core-gateway-upload-grant/1', $grant['schemaVersion']);
            self::assertSame('gateway_channel_unavailable', $dispatch->authorizeWrite($packet)['reasonCode']);
            $manual = $this->gatewayFrame($packet, 4, 'upload_complete', ['projectionDigest' => $packet->projectionDigest, 'bodyLength' => strlen($packet->bodyBytes)]);
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($manual, $peer, $packet));
            self::assertTrue($this->appHeld);
            self::assertNotContains('upload_complete', $this->controlCalls);
            $cancel = $dispatch->gatewayCancelRequest($packet, 'expired');
            $ack = ['schemaVersion' => 'public-core-gateway-upload-stopped/1', 'binding' => $cancel['binding'], 'reasonCode' => 'expired'];
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($this->gatewayFrame($packet, 5, 'abort', $ack), $peer, $packet));
            self::assertTrue($this->appHeld);
            $this->nativeEvent = 'stopped';
            self::assertArrayNotHasKey('reasonCode', $dispatch->handleGatewayFrame($this->gatewayFrame($packet, 6, 'abort', $ack), $peer, $packet));
            self::assertFalse($this->appHeld);
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($this->gatewayFrame($packet, 6, 'abort', $ack), $peer, $packet));
            return GatewayModelResponse::blocked($packet, 'expired');
        });
        self::assertNotSame('completed', $response->status);
    }

    public function testCanonicalStoppedBindingReleasesOnlyAfterMatchingPrivateEvent(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->nativeEvent = 'stopped';
            $frame = $this->gatewayFrame($packet, 2, 'abort', ['schemaVersion' => 'public-core-gateway-upload-stopped/1',
                'binding' => $packet->binding(), 'reasonCode' => 'expired']);
            $wire = json_decode(GatewayModelRequest::canonicalJson($frame), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame($packet->binding(), $wire['payload']['binding']);
            self::assertSame(GatewayModelRequest::canonicalJson($packet->binding()), GatewayModelRequest::canonicalJson($wire['payload']['binding']));
            self::assertSame(['projectionDigest' => $packet->projectionDigest, 'bodyLength' => strlen($packet->bodyBytes)],
                $dispatch->handleGatewayFrame($wire, $this->controlPins()['gatewayPeer'], $packet));
            self::assertFalse($this->appHeld);
            self::assertFalse($dispatch->uploadPending());
            self::assertNotNull($this->store->transaction(static fn (): array => ['released' => true]));
            self::assertSame(1, count(array_filter($this->controlCalls, static fn (string $command): bool => $command === 'upload_complete')));
            $wire['sequence']++;
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($wire, $this->controlPins()['gatewayPeer'], $packet));
            self::assertSame(1, count(array_filter($this->controlCalls, static fn (string $command): bool => $command === 'upload_complete')));
            return GatewayModelResponse::blocked($packet, 'expired');
        });
        self::assertNotSame('completed', $response->status);
    }

    #[DataProvider('canonicalStoppedDenials')]
    public function testCanonicalStoppedBindingCannotReleaseForInvalidTupleOrProof(string $change): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet, $change): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->nativeEvent = 'stopped';
            $frame = $this->gatewayFrame($packet, 2, 'abort', ['schemaVersion' => 'public-core-gateway-upload-stopped/1',
                'binding' => $packet->binding(), 'reasonCode' => 'expired']);
            $peer = $this->controlPins()['gatewayPeer'];
            if (str_starts_with($change, 'binding:')) {
                $key = substr($change, strlen('binding:'));
                $frame['payload']['binding'][$key] = $key === 'expiresAt' ? $packet->expiresAt - 1 : 'ref_foreign_binding_value';
            } elseif (str_starts_with($change, 'native:')) {
                $key = substr($change, strlen('native:'));
                $this->nativeOverrides[$key] = $key === 'qualification' ? 'actual-native' : 'ref_foreign_native_tuple';
            } else {
                switch ($change) {
                    case 'missing': unset($frame['payload']['binding']['profileRef']); break;
                    case 'extra': $frame['payload']['binding']['callerProof'] = true; break;
                    case 'integer-string': $frame['payload']['binding']['expiresAt'] = (string) $packet->expiresAt; break;
                    case 'string-integer': $frame['payload']['binding']['profileRef'] = 1; break;
                    case 'null': $frame['payload']['binding'] = null; break;
                    case 'denial': $frame['payload']['binding'] = ['reasonCode' => 'expired']; break;
                    case 'pending': $this->nativeEvent = 'pending'; break;
                    case 'uncertain': $this->nativeEvent = 'uncertain'; break;
                    case 'peer': $peer['pid']++; break;
                    case 'channel': $frame['channelRef'] = 'channel_foreign_transfer'; break;
                    case 'sequence': $frame['sequence'] = 1; break;
                    case 'outer-expiry': $frame['expiresAt']--; break;
                }
            }
            $wire = json_decode(GatewayModelRequest::canonicalJson($frame), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($wire, $peer, $packet));
            self::assertTrue($this->appHeld);
            self::assertTrue($dispatch->uploadPending());
            self::assertNotContains('upload_complete', $this->controlCalls);
            $this->nativeOverrides = [];
            $this->nativeEvent = 'pending';
            return GatewayModelResponse::blocked($packet, 'expired');
        });
        self::assertNotSame('completed', $response->status);
    }

    public static function canonicalStoppedDenials(): array
    {
        $cases = ['missing', 'extra', 'integer-string', 'string-integer', 'null', 'denial', 'pending', 'uncertain',
            'peer', 'channel', 'sequence', 'outer-expiry'];
        foreach (['requestRef', 'attemptRef', 'publicAdmissionRef', 'contextReceiptRef', 'corePayloadDigest', 'coreReceiptDigest',
            'projectionRef', 'projectionDigest', 'profileRef', 'profileFingerprint', 'purpose', 'expiresAt'] as $field) {
            $cases[] = 'binding:' . $field;
        }
        foreach (['qualification', 'channelRef', 'transferRef', 'requestRef', 'attemptRef', 'projectionDigest'] as $field) {
            $cases[] = 'native:' . $field;
        }
        return array_map(static fn (string $case): array => [$case], $cases);
    }

    public function testEofAndUnknownStopKeepScopeUntilVerifiedSameTransferStop(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->cancelAllowed = false;
        try {
            $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
                self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
                throw new \RuntimeException('EOF is not stopped');
            });
            self::assertNotSame('completed', $response->status);
            self::assertTrue($this->appHeld);
            self::assertNull($this->store->transaction(static fn (): array => ['held' => true]));
            self::assertNotContains('upload_complete', $this->controlCalls);
        } finally {
            $this->cancelAllowed = true;
            self::assertArrayNotHasKey('reasonCode', $dispatch->cancelUpload($packet));
        }
        self::assertFalse($this->appHeld);
        self::assertNotNull($this->store->transaction(static fn (): array => ['released' => true]));
    }

    public function testMissingReleaseAckDoesNotWaitForModelOrResendRelease(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->controlMutator = static function (string $command, array $result): array {
            if ($command === 'upload_complete') {
                $result['frame']['payload'] = [];
            }
            return $result;
        };
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->nativeEvent = 'uploaded';
            self::assertArrayHasKey('reasonCode', $dispatch->uploadComplete($packet));
            throw new \RuntimeException('release not acknowledged');
        });
        self::assertNotSame('completed', $response->status);
        self::assertSame(1, count(array_filter($this->controlCalls, static fn (string $command): bool => $command === 'upload_complete')));
        self::assertNotNull($this->store->transaction(static fn (): array => ['ledgerReleased' => true]));
    }

    public function testBackwardClockDuringGrantDeniesAndCancelsWithoutWriteAllowance(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->controlMutator = function (string $command, array $result): array {
            if ($command === 'authorize_write') {
                $this->monoMs--;
            }
            return $result;
        };
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            $grant = $dispatch->authorizeWrite($packet);
            self::assertSame(['reasonCode'], array_keys($grant));
            return GatewayModelResponse::blocked($packet, $grant['reasonCode']);
        });
        self::assertNotSame('completed', $response->status);
        self::assertFalse($this->appHeld);
    }

    #[DataProvider('invalidNativeEvents')]
    public function testUnknownNativeQualificationOrWrongTransferTupleGrantsNothing(string $field, mixed $value): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->nativeOverrides[$field] = $value;
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            $grant = $dispatch->authorizeWrite($packet);
            self::assertSame(['reasonCode'], array_keys($grant));
            return GatewayModelResponse::blocked($packet, $grant['reasonCode']);
        });
        self::assertNotSame('completed', $response->status);
        self::assertNotContains('authorize_write', $this->controlCalls);
    }

    public static function invalidNativeEvents(): array
    {
        return [['qualification', 'actual-native'], ['channelRef', 'channel_other_source'], ['requestRef', 'ref_other_request'],
            ['attemptRef', 'ref_other_attempt'], ['projectionDigest', str_repeat('0', 64)], ['event', 'manual_uploaded']];
    }

    public function testExpiredGrantAndSameAttemptRevocationDenyBeforeFollowingWrite(): void
    {
        [$dispatch, $packet, $readiness] = $this->dispatchPreparation();
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet, $readiness): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->monoMs += 2000;
            self::assertSame('expired', $dispatch->currentBinding($packet, $readiness->qualifiedProfile())['reasonCode']);
            self::assertArrayNotHasKey('reasonCode', $dispatch->cancelUpload($packet, 'expired'));
            return GatewayModelResponse::blocked($packet, 'expired');
        });
        self::assertNotSame('completed', $response->status);
        self::assertFalse($this->appHeld);
    }

    public function testFreshFinalAuthorizationSuppressesReplyAfterReleasedUpload(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->nativeEvent = 'uploaded';
            self::assertArrayNotHasKey('reasonCode', $dispatch->uploadComplete($packet));
            $this->viewer['authorized'] = false;
            return $this->planResponse($packet);
        });
        self::assertSame('authorization_changed', $response->reasonCode);
        self::assertNull($response->actionBytes);
    }

    public function testExpiredStoppedControlDoesNotEnterUndeclaredCleanupFallback(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $cancel = $dispatch->gatewayCancelRequest($packet, 'expired');
            $frame = $this->gatewayFrame($packet, 2, 'abort', ['schemaVersion' => 'public-core-gateway-upload-stopped/1',
                'binding' => $cancel['binding'], 'reasonCode' => 'expired']);
            $frame['expiresAt'] = $this->now;
            self::assertArrayHasKey('reasonCode', $dispatch->handleGatewayFrame($frame, $this->controlPins()['gatewayPeer'], $packet));
            self::assertTrue($this->appHeld);
            self::assertNotContains('upload_complete', $this->controlCalls);
            self::assertArrayNotHasKey('reasonCode', $dispatch->cancelUpload($packet));
            return GatewayModelResponse::blocked($packet, 'expired');
        });
        self::assertNotSame('completed', $response->status);
    }

    public function testOwnedDenialRemainsBoundedDenialAndNeverBecomesGrant(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->controlMutator = function (string $command, array $result): array {
            if ($command === 'authorize_write') {
                $this->appHeld = false;
                $result['frame']['payload'] = ['schemaVersion' => 'public-core-app-control-denial/1',
                    'binding' => $result['frame']['payload']['binding'], 'reasonCode' => 'source_changed'];
            }
            return $result;
        };
        $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame(['reasonCode' => 'source_changed'], $dispatch->authorizeWrite($packet));
            return GatewayModelResponse::blocked($packet, 'source_changed');
        });
        self::assertNotSame('completed', $response->status);
        self::assertNotContains('upload_complete', $this->controlCalls);
    }

    public function testAppAbortRequestsStopWithoutReleasingFromManualFlag(): void
    {
        [$dispatch, $packet] = $this->dispatchPreparation();
        $this->cancelAllowed = false;
        try {
            $response = $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
                self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
                $tuple = ['viewerTicketRef' => 'ref_server_viewer_ticket', 'requestRef' => $packet->requestRef, 'attemptRef' => $packet->attemptRef,
                    'projectionDigest' => $packet->projectionDigest, 'profileFingerprint' => $packet->profileFingerprint,
                    'registryDigest' => $this->runtimeSource['registryDigest'], 'manifestGenerationRef' => $this->runtimeSource['manifestGenerationRef']];
                $frame = ['schemaVersion' => 'public-core-channel/1', 'channelRef' => $this->controlPins()['appChannelRef'],
                    'sequence' => ++$this->appSequence, 'command' => 'abort', 'requestRef' => $packet->requestRef, 'attemptRef' => $packet->attemptRef,
                    'expiresAt' => $packet->expiresAt, 'payload' => ['schemaVersion' => 'public-core-app-upload-cancel-request/1', 'binding' => $tuple,
                        'guardRef' => 'ref_simulated_upload_guard', 'reasonCode' => 'authorization_changed']];
                self::assertArrayHasKey('reasonCode', $dispatch->handleAppCancelFrame($frame, $this->controlPins()['appPeer'], $packet));
                self::assertTrue($this->appHeld);
                self::assertNotContains('upload_complete', $this->controlCalls);
                return GatewayModelResponse::blocked($packet, 'authorization_changed');
            });
            self::assertNotSame('completed', $response->status);
            self::assertTrue($this->appHeld);
        } finally {
            $this->cancelAllowed = true;
            self::assertArrayNotHasKey('reasonCode', $dispatch->cancelUpload($packet));
        }
    }

    public function testCompletedTransferCannotBeReusedForAnotherAttempt(): void
    {
        [$dispatch, $packet, $readiness, , $input, $binding] = $this->dispatchPreparation();
        self::assertSame('completed', $this->runDispatch($dispatch, $packet, function () use ($dispatch, $packet): GatewayModelResponse {
            self::assertSame('public-core-gateway-upload-grant/1', $dispatch->authorizeWrite($packet)['schemaVersion']);
            $this->nativeEvent = 'uploaded';
            self::assertArrayNotHasKey('reasonCode', $dispatch->uploadComplete($packet));
            return $this->planResponse($packet);
        })->status);
        $next = $dispatch->projectForDispatch($input, $binding, $readiness->qualifiedProfile());
        self::assertInstanceOf(GatewayModelRequest::class, $next);
        $this->nativeEvent = 'pending';
        $this->controlCalls = [];
        $result = $this->runDispatch($dispatch, $next, function () use ($dispatch, $next): GatewayModelResponse {
            self::assertSame(['reasonCode' => 'receipt_changed'], $dispatch->authorizeWrite($next));
            return GatewayModelResponse::blocked($next, 'receipt_changed');
        });
        self::assertNotSame('completed', $result->status);
        self::assertNotContains('authorize_write', $this->controlCalls);
    }

    private function processor(?\Closure $composition = null, ?\Closure $currentRuntimeSource = null, bool $withRuntimeReader = true,
        bool $withPublisher = true): PublicCoreProcessor
    {
        $reader = $withRuntimeReader ? ($currentRuntimeSource ?? function (object $runtime, array $request): ?array {
            return $runtime instanceof SyntheticMaterialSearchCorpus && $runtime->guard($runtime->context()) === null
                ? ['registryDigest' => $this->registry->manifestDigest(), 'manifestGenerationRef' => $request['registered']['source_generation_ref'],
                    'runtimeGenerationRef' => $runtime->records()[0]->generationRef] : null;
        }) : null;
        $readiness = $this->qualifiedReadiness();
        $publisher = $this->controlledPublication();
        $bounds = function (array $request, \Closure $implementation) use ($publisher): ?array {
            return $this->publicationQualified && $implementation === $publisher
                ? ['qualification' => 'source-simulated-tcb', 'profileFingerprint' => $this->currentGatewayProfile?->fingerprint(),
                    'guardEvidenceRef' => 'ref_controlled_memory_fixture_only', 'maxDurationMs' => 250, 'genuineExpiresAt' => $request['expiresAt']]
                : null;
        };
        return new PublicCoreProcessor($this->registry, $this->store, $this->sessions, $readiness,
            static fn (array $peer): ?array => $peer === ['pid' => 111, 'uid' => 1001, 'gid' => 1001]
                ? ['role' => 'app', 'identityRef' => 'ref_simulated_app_identity', 'kernelPeer' => $peer] : null,
            static fn (string $ticket): ?array => $ticket === 'ref_server_viewer_ticket' ? ['viewerTicketRef' => $ticket] : null,
            $composition, $reader, $withPublisher ? $publisher : null, $withPublisher ? $bounds : null);
    }

    private function controlledPublication(): \Closure
    {
        return $this->withAssertions(function (array $input, object $runtime, \Closure $prepare): mixed {
            if ($this->publicationMutex || !$runtime instanceof SyntheticMaterialSearchCorpus) {
                return null;
            }
            $this->publicationMutex = true;
            $owner = $this->viewer;
            $start = intdiv(hrtime(true), 1000000);
            try {
                $candidate = $prepare();
                if ($this->publicationFault === 'double_prepare') {
                    self::assertNull($prepare());
                    return null;
                }
                if ($this->publicationFault === 'bool') {
                    return true;
                }
                if ($this->publicationFault === 'echo') {
                    return $candidate;
                }
                if ($this->publicationFault === 'bytes' && is_array($candidate)) {
                    $candidate['resultBytes'] = '{"reply":"altered"}';
                }
                if (!GatewayModelRequest::hasExactKeys($candidate, ['binding', 'resultBytes', 'resultDigest'])
                    || $candidate['binding'] !== $input['resultBinding'] || $candidate['resultDigest'] !== $input['resultBinding']['resultDigest']
                    || !is_string($candidate['resultBytes']) || hash('sha256', $candidate['resultBytes']) !== $candidate['resultDigest']) {
                    return null;
                }
                $data = json_decode($candidate['resultBytes'], true, flags: JSON_THROW_ON_ERROR);
                $bytes = RegisteredPublicFixtureRegistry::canonical(['success' => true, 'message' => null, 'data' => $data]);
                $ref = 'ref_' . bin2hex(random_bytes(16));
                $record = ['binding' => $input['resultBinding'], 'viewerTicketRef' => $input['viewerBinding']['viewerTicketRef'],
                    'requestRef' => $input['resultBinding']['requestRef'], 'sessionRef' => $input['resultBinding']['sessionRef'],
                    'operationRef' => $input['operationRef'], 'publicationRef' => $ref, 'resultDigest' => $candidate['resultDigest'],
                    'envelopeDigest' => hash('sha256', $bytes), 'bodyBytes' => $bytes, 'expiresAt' => $input['genuineExpiresAt']];
                $receipt = ['schemaVersion' => 'public-core-result-publication/1', 'binding' => $input['resultBinding'], 'publicationRef' => $ref];
                if ($this->publicationFault === 'receipt') {
                    $receipt['binding']['resultDigest'] = str_repeat('0', 64);
                    return $receipt;
                }
                if ($this->publicationFault === 'commit') {
                    throw new \RuntimeException('controlled sink failed before commit');
                }
                if (!$this->publicationQualified || !$this->runtimeReaderAvailable || $this->viewer !== $owner || $owner['authorized'] !== true
                    || $this->currentGatewayProfile === null || $this->currentGatewayProfile->fingerprint() !== $input['resultBinding']['profileFingerprint']
                    || ($this->runtimeProof['profileFingerprint'] ?? null) !== $input['resultBinding']['profileFingerprint']
                    || $this->now >= $input['genuineExpiresAt'] || intdiv(hrtime(true), 1000000) - $start >= $input['maxDurationMs']
                    || $runtime->guard($runtime->context()) !== null) {
                    return null;
                }
                $this->publications[$ref] = $record;
                return $receipt;
            } finally {
                $this->publicationMutex = false;
            }
        });
    }

    private function completedProcessor(bool $withReader = true, bool $withPublisher = true): array
    {
        $factory = function (): array {
            $this->cachedFactoryCalls++;
            return [];
        };
        $reader = function (object $runtime, array $request, GatewayModelProfile $profile): ?array {
            $this->currentRuntimeChecks++;
            if (!$this->runtimeReaderAvailable || !$runtime instanceof SyntheticMaterialSearchCorpus || $runtime->guard($runtime->context()) !== null) {
                return null;
            }
            $source = ['registryDigest' => $this->registry->manifestDigest(), 'manifestGenerationRef' => $request['registered']['source_generation_ref'],
                'runtimeGenerationRef' => $runtime->records()[0]->generationRef];
            if ($this->onRuntimeRead !== null) {
                ($this->onRuntimeRead)($runtime);
            }
            return $source;
        };
        $processor = $this->processor($factory, $reader, $withReader, $withPublisher);
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $opened = $processor->handle('open_or_resume', $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'], $peer);
        self::assertSame('accepted', $opened['status']);
        $request = $this->sessions->lookup(['viewerTicketRef' => 'ref_server_viewer_ticket'], $opened['request_ref']);
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $reflection = new \ReflectionClass($processor);
        $processRef = $reflection->getProperty('processRef')->getValue($processor);
        $instanceRef = 'ref_seeded_retained_source_instance';
        $reflection->getProperty('runtimes')->setValue($processor, [$request['sessionRef'] => $corpus]);
        $reflection->getProperty('runtimeInstances')->setValue($processor, [$request['sessionRef'] => ['runtime' => $corpus, 'instanceRef' => $instanceRef]]);
        $result = ['status' => 'completed', 'reasonCode' => 'none', 'request_ref' => $request['requestRef'],
            'reply' => 'PUBLIC PRIOR COMPLETED RESULT', 'trace' => [], 'transportAllowed' => false,
            'actual_model' => null, 'tools' => [], 'sources' => []];
        $generation = $corpus->records()[0]->generationRef;
        $fingerprint = $this->currentGatewayProfile->fingerprint();
        $this->store->transaction(function (array &$state) use ($request, $result, $generation, $fingerprint, $processRef, $instanceRef): array {
            $sessionRef = $request['sessionRef'];
            $state['sessions'][$sessionRef]['runtimeGenerationRef'] = $generation;
            $state['requests'][$request['requestRef']]['runtimeGenerationRef'] = $generation;
            $binding = ['schemaVersion' => 'public-core-result-binding/1', 'requestRef' => $request['requestRef'], 'sessionRef' => $sessionRef,
                'processRef' => $processRef, 'ownerDigest' => $state['sessions'][$sessionRef]['ownerDigest'], 'profileFingerprint' => $fingerprint,
                'registryDigest' => $this->registry->manifestDigest(), 'manifestGenerationRef' => $request['registered']['source_generation_ref'],
                'runtimeGenerationRef' => $generation, 'runtimeInstanceRef' => $instanceRef,
                'resultDigest' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($result))];
            $state['requests'][$request['requestRef']]['execution'] = ['status' => 'completed', 'processRef' => $processRef,
                'result' => $result, 'resultBinding' => $binding];
            return ['seeded' => true];
        });
        return [$processor, ['viewer_ticket_ref' => 'ref_server_viewer_ticket', 'request_ref' => $request['requestRef']], $peer, $corpus, $result, $request];
    }

    #[DataProvider('cachedReadPaths')]
    public function testCompletedReadRequiresExactRetainedCurrentBindingWithoutFactoryOrWrites(string $command): void
    {
        [$processor, $payload, $peer, , $expected] = $this->completedProcessor();
        $before = hash_file('sha256', $this->directory . '/authority.json');
        $first = $processor->handle($command, $payload, $peer);
        $second = $processor->handle($command, $payload, $peer);
        foreach ([$first, $second] as $published) {
            self::assertSame(['schemaVersion', 'binding', 'publicationRef'], array_keys($published));
            self::assertSame('public-core-result-publication/1', $published['schemaVersion']);
            self::assertArrayNotHasKey('reply', $published);
            self::assertArrayNotHasKey('trace', $published);
            $record = $this->publications[$published['publicationRef']];
            self::assertSame($expected, json_decode($record['bodyBytes'], true, flags: JSON_THROW_ON_ERROR)['data']);
            self::assertSame(hash('sha256', $record['bodyBytes']), $record['envelopeDigest']);
            self::assertNotSame($record['resultDigest'], $record['envelopeDigest']);
        }
        self::assertNotSame($first['publicationRef'], $second['publicationRef']);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertGreaterThanOrEqual(4, $this->currentRuntimeChecks);
        self::assertSame($before, hash_file('sha256', $this->directory . '/authority.json'));
    }

    public static function cachedReadPaths(): array
    {
        return [['lookup_owned'], ['execute_owned']];
    }

    #[DataProvider('cachedReadFailures')]
    public function testCachedReadAndExistingExecuteRejectLostChangedOrUnprovedCurrentProof(string $command, string $change): void
    {
        [$processor, $payload, $peer, $corpus, , $request] = $this->completedProcessor($change !== 'missing_reader');
        $reflection = new \ReflectionClass($processor);
        switch ($change) {
            case 'profile_revoked': $this->runtimeProof['profileFingerprint'] = str_repeat('0', 64); break;
            case 'profile_missing': $this->currentGatewayProfile = null; break;
            case 'profile_changed':
                $values = $this->currentGatewayProfile->values();
                $values['modelRevision'] = 'source/2';
                $this->currentGatewayProfile = GatewayModelProfile::fromArray($values);
                $this->runtimeProof['profileFingerprint'] = $this->currentGatewayProfile->fingerprint();
                break;
            case 'lost_runtime': $reflection->getProperty('runtimes')->setValue($processor, []); break;
            case 'lost_instance': $reflection->getProperty('runtimeInstances')->setValue($processor, []); break;
            case 'changed_object': $reflection->getProperty('runtimes')->setValue($processor, [$request['sessionRef'] => SyntheticMaterialSearchCorpus::named('material-search-v1')]); break;
            case 'source_revoked': $corpus->invalidateSource(); break;
            case 'source_scope_revoked': $corpus->revokeScope(); break;
            case 'unproved_source': $this->runtimeReaderAvailable = false; break;
            case 'missing_reader': break;
            case 'lost_binding': $this->store->transaction(static function (array &$state) use ($request): array {
                unset($state['requests'][$request['requestRef']]['execution']['resultBinding']);
                return ['changed' => true];
            }); break;
            case 'changed_generation': $this->store->transaction(static function (array &$state) use ($request): array {
                $state['requests'][$request['requestRef']]['runtimeGenerationRef'] = 'ref_changed_runtime_generation';
                return ['changed' => true];
            }); break;
            case 'changed_result': $this->store->transaction(static function (array &$state) use ($request): array {
                $state['requests'][$request['requestRef']]['execution']['result']['reply'] = 'REPLACED REPLY';
                return ['changed' => true];
            }); break;
        }
        $before = hash_file('sha256', $this->directory . '/authority.json');
        $result = $processor->handle($command, $payload, $peer);
        self::assertSame('blocked', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertArrayNotHasKey('trace', $result);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertSame($before, hash_file('sha256', $this->directory . '/authority.json'));
    }

    public static function cachedReadFailures(): array
    {
        $cases = [];
        foreach (['lookup_owned', 'execute_owned'] as $command) {
            foreach (['profile_revoked', 'profile_missing', 'profile_changed', 'lost_runtime', 'lost_instance', 'changed_object',
                'source_revoked', 'source_scope_revoked', 'unproved_source', 'missing_reader', 'lost_binding', 'changed_generation', 'changed_result'] as $change) {
                $cases[] = [$command, $change];
            }
        }
        return $cases;
    }

    #[DataProvider('cachedReadPaths')]
    public function testRestartCannotRebuildCachedResultProofWithFactory(string $command): void
    {
        [, $payload, $peer] = $this->completedProcessor();
        $restarted = $this->processor(function (): array {
            $this->cachedFactoryCalls++;
            return [];
        });
        $result = $restarted->handle($command, $payload, $peer);
        self::assertSame('blocked', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertSame([], $this->publications);
    }

    #[DataProvider('cachedReadDuringProofFailures')]
    public function testChangesDuringCachedProofCheckSuppressBytes(string $command, string $change, int $check): void
    {
        [$processor, $payload, $peer] = $this->completedProcessor();
        $this->onRuntimeRead = function (SyntheticMaterialSearchCorpus $runtime) use ($change, $check): void {
            if ($this->currentRuntimeChecks === $check) {
                if ($change === 'owner') {
                    $this->viewer['authorized'] = false;
                } else {
                    $runtime->invalidateSource();
                }
            }
        };
        $result = $processor->handle($command, $payload, $peer);
        self::assertSame('blocked', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertSame(0, $this->cachedFactoryCalls);
    }

    public static function cachedReadDuringProofFailures(): array
    {
        return [['lookup_owned', 'owner', 1], ['execute_owned', 'owner', 1], ['lookup_owned', 'source', 1], ['execute_owned', 'source', 1],
            ['lookup_owned', 'owner', 2], ['execute_owned', 'owner', 2], ['lookup_owned', 'source', 2], ['execute_owned', 'source', 2]];
    }

    #[DataProvider('publicationFailures')]
    public function testUnknownInvalidOrFailedPublicationNeverExposesRawCandidate(string $command, string $failure): void
    {
        [$processor, $payload, $peer] = $this->completedProcessor(withPublisher: $failure !== 'missing');
        if ($failure === 'unqualified') {
            $this->publicationQualified = false;
        } elseif ($failure === 'expiry') {
            $this->onRuntimeRead = function (): void {
                if ($this->currentRuntimeChecks === 2) {
                    $this->now += 120;
                }
            };
        } elseif ($failure === 'reentry') {
            $this->onRuntimeRead = function () use ($processor, $payload, $peer): void {
                if ($this->currentRuntimeChecks === 2) {
                    $nested = $processor->handle('lookup_owned', $payload, $peer);
                    self::assertSame('blocked', $nested['status']);
                }
            };
        } else {
            $this->publicationFault = $failure;
        }
        $before = hash_file('sha256', $this->directory . '/authority.json');
        $result = $processor->handle($command, $payload, $peer);
        self::assertSame('blocked', $result['status']);
        self::assertArrayNotHasKey('reply', $result);
        self::assertArrayNotHasKey('trace', $result);
        self::assertSame([], $this->publications);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertSame($before, hash_file('sha256', $this->directory . '/authority.json'));
    }

    public static function publicationFailures(): array
    {
        $cases = [];
        foreach (['lookup_owned', 'execute_owned'] as $command) {
            foreach (['missing', 'unqualified', 'double_prepare', 'bool', 'echo', 'bytes', 'receipt', 'commit', 'expiry', 'reentry'] as $failure) {
                $cases[] = [$command, $failure];
            }
        }
        return $cases;
    }

    public function testUnqualifiedPublicationCannotStartFactoryOrModel(): void
    {
        $processor = $this->processor(function (): array {
            $this->cachedFactoryCalls++;
            return [];
        });
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $opened = $processor->handle('open_or_resume', $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'], $peer);
        $this->publicationQualified = false;
        $before = hash_file('sha256', $this->directory . '/authority.json');
        $result = $processor->handle('execute_owned', ['viewer_ticket_ref' => 'ref_server_viewer_ticket', 'request_ref' => $opened['request_ref']], $peer);
        self::assertSame('blocked', $result['status']);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertSame([], $this->publications);
        self::assertSame($before, hash_file('sha256', $this->directory . '/authority.json'));
    }

    #[DataProvider('invalidCompletedTraces')]
    public function testMalformedCompletedTraceCannotReachStagingOrSink(mixed $trace): void
    {
        [$processor, $payload, $peer] = $this->completedProcessor();
        $this->store->transaction(static function (array &$state) use ($payload, $trace): array {
            $execution =& $state['requests'][$payload['request_ref']]['execution'];
            $execution['result']['trace'] = $trace;
            $execution['resultBinding']['resultDigest'] = hash('sha256', RegisteredPublicFixtureRegistry::canonical($execution['result']));
            return ['seededMalformedPriorState' => true];
        });
        foreach (['lookup_owned', 'execute_owned'] as $command) {
            $result = $processor->handle($command, $payload, $peer);
            self::assertSame('blocked', $result['status']);
            self::assertArrayNotHasKey('reply', $result);
            self::assertSame([], $this->publications);
        }
        self::assertSame(0, $this->cachedFactoryCalls);
    }

    public static function invalidCompletedTraces(): array
    {
        $event = ['action' => 'ready', 'step' => 1, 'tokens' => 32, 'callRef' => null];
        return [[null], ['PRIVATE raw trace'], [['private' => $event]], [[['action' => 'ready']]],
            [[array_replace($event, ['action' => 'private_lookup'])]], [[array_replace($event, ['step' => -1])]],
            [[array_replace($event, ['tokens' => 1.5])]], [[array_replace($event, ['callRef' => 'PRIVATE_SOURCE_REF'])]],
            [[array_replace($event, ['callRef' => 'ref_' . str_repeat('g', 32)])]], [[$event + ['sourceRef' => 'PRIVATE_SOURCE']]]];
    }

    public function testClosedNativeTraceKeepsExactCoreDigestAndIndependentEnvelopeDigest(): void
    {
        [$processor, $payload, $peer] = $this->completedProcessor();
        $trace = [['action' => 'tool', 'step' => 1, 'tokens' => 32, 'callRef' => 'ref_' . str_repeat('a', 32)],
            ['action' => 'final', 'step' => 2, 'tokens' => 64, 'callRef' => null],
            ['action' => 'ready', 'step' => 2, 'tokens' => 64, 'callRef' => null]];
        $this->store->transaction(static function (array &$state) use ($payload, $trace): array {
            $execution =& $state['requests'][$payload['request_ref']]['execution'];
            $execution['result']['trace'] = $trace;
            $execution['resultBinding']['resultDigest'] = hash('sha256', RegisteredPublicFixtureRegistry::canonical($execution['result']));
            return ['seededValidPriorState' => true];
        });
        $receipt = $processor->handle('lookup_owned', $payload, $peer);
        self::assertSame('public-core-result-publication/1', $receipt['schemaVersion']);
        $record = $this->publications[$receipt['publicationRef']];
        $data = json_decode($record['bodyBytes'], true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed', 'actual_model', 'tools', 'sources'], array_keys($data));
        self::assertSame($trace, $data['trace']);
        self::assertSame(hash('sha256', RegisteredPublicFixtureRegistry::canonical($data)), $record['resultDigest']);
        self::assertNotSame($record['resultDigest'], $record['envelopeDigest']);
    }

    public function testMissingRuntimeReaderCannotCreateFreshExecutionOrFactoryWrites(): void
    {
        $processor = $this->processor(function (): array {
            $this->cachedFactoryCalls++;
            return [];
        }, withRuntimeReader: false);
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $opened = $processor->handle('open_or_resume', $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'], $peer);
        $result = $processor->handle('execute_owned', ['viewer_ticket_ref' => 'ref_server_viewer_ticket', 'request_ref' => $opened['request_ref']], $peer);
        self::assertSame('runtime_not_activated', $result['reasonCode']);
        self::assertSame(0, $this->cachedFactoryCalls);
        self::assertArrayNotHasKey('reply', $result);
    }

    public function testProcessorCommandsRequireProtectedPeerAndServerOwnedViewerTicket(): void
    {
        $processor = $this->processor();
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $payload = $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'];
        self::assertSame('blocked', $processor->handle('open_or_resume', $payload, ['role' => 'processor', 'authorized' => true])['status']);
        self::assertSame('blocked', $processor->handle('open_or_resume', $payload + ['message' => 'PRIVATE arbitrary input'], $peer)['status']);
        $opened = $processor->handle('open_or_resume', $payload, $peer);
        self::assertSame('accepted', $opened['status']);
        self::assertSame($opened, $processor->handle('open_or_resume', $payload, $peer));
        $lookup = ['viewer_ticket_ref' => 'ref_server_viewer_ticket', 'request_ref' => $opened['request_ref']];
        self::assertSame('accepted', $processor->handle('lookup_owned', $lookup, $peer)['status']);
        self::assertSame('runtime_not_activated', $processor->handle('execute_owned', $lookup, $peer)['reasonCode']);
        self::assertFalse($processor->handle('readiness', [], $peer)['model_enabled']);
        $this->viewer['authorizationRevision'] = 'authorization/2';
        self::assertSame('authorization_changed', $processor->handle('lookup_owned', $lookup, $peer)['reasonCode']);
    }

    public function testFailedCompositionIsTerminalAndCannotBeRetriedByCommandReplay(): void
    {
        $compositions = 0;
        $processor = $this->processor(static function () use (&$compositions): array {
            $compositions++;
            return ['loop' => (object) ['status' => 'READY']];
        });
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $opened = $processor->handle('open_or_resume', $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'], $peer);
        $payload = ['viewer_ticket_ref' => 'ref_server_viewer_ticket', 'request_ref' => $opened['request_ref']];
        $first = $processor->handle('execute_owned', $payload, $peer);
        self::assertSame('blocked', $first['status']);
        self::assertSame($first, $processor->handle('execute_owned', $payload, $peer));
        self::assertSame($first, $processor->handle('lookup_owned', $payload, $peer));
        self::assertSame(1, $compositions);
    }

    public function testLostRuntimeGenerationOrRunningClaimDoesNotResumeAfterRestart(): void
    {
        $compositions = 0;
        $factory = static function () use (&$compositions): array {
            $compositions++;
            return [];
        };
        $processor = $this->processor($factory);
        $peer = ['pid' => 111, 'uid' => 1001, 'gid' => 1001];
        $opened = $processor->handle('open_or_resume', $this->selection() + ['viewer_ticket_ref' => 'ref_server_viewer_ticket'], $peer);
        $this->store->transaction(static function (array &$state) use ($opened): array {
            $sessionRef = $state['requests'][$opened['request_ref']]['sessionRef'];
            $state['sessions'][$sessionRef]['runtimeGenerationRef'] = 'ref_previous_process_generation';
            return ['lost' => true];
        });
        $restarted = $this->processor($factory);
        self::assertSame('source_changed', $restarted->executeOwned($opened['request_ref'])['reasonCode']);
        $this->store->transaction(static function (array &$state) use ($opened): array {
            $state['requests'][$opened['request_ref']]['execution'] = ['status' => 'running', 'processRef' => 'ref_previous_process'];
            return ['claimed' => true];
        });
        self::assertSame('receipt_changed', $restarted->executeOwned($opened['request_ref'])['reasonCode']);
        self::assertSame(0, $compositions);
    }
}
