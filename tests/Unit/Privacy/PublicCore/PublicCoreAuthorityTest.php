<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextPreparationService;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantSafeContextSegment;
use App\Services\Privacy\PublicCore\PublicCoreReceiptStore;
use App\Services\Privacy\PublicCore\PublicCoreSessionAuthority;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
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

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/public-core-authority-test-' . bin2hex(random_bytes(16));
        $this->registry = RegisteredPublicFixtureRegistry::compiled();
        $this->store = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        $this->now = 1700000000;
        $this->viewer = ['authorized' => true, 'viewerRef' => 'real-backend-viewer-A', 'organizationRef' => 'real-backend-organization-A',
            'authorizationRevision' => 'authorization/1', 'policyRevision' => 'public-policy/1'];
        $this->sessions = new PublicCoreSessionAuthority($this->registry, $this->store,
            fn (array $binding): ?array => $binding === ['credential' => 'server-ticket'] ? $this->viewer : null,
            fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
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
}
