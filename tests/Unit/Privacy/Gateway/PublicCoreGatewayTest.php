<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\Gateway;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreTransport;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PublicCoreGatewayTest extends TestCase
{
    private const NOW = 1801821600;

    private const PEER = 'processor:local-test-identity-01';

    private int $writes = 0;

    private bool $fenced = false;

    private bool $revoked = false;

    private array $written = [];

    private function profile(): GatewayModelProfile
    {
        return GatewayModelProfile::fromArray([
            'profileRef' => 'profile:local-stub-profile-01',
            'qualification' => 'local-stub',
            'adapterRevision' => 'local-test-v1',
            'apiMethod' => 'local_action',
            'endpoint' => 'local://public-core-stub',
            'modelId' => 'local-action-stub',
            'modelRevision' => 'local-v1',
            'tokenizerId' => 'local-count-stub',
            'tokenizerRevision' => 'local-v1',
            'mappingEvidenceRef' => 'evidence:local-count-stub-01',
            'capabilityEvidenceRef' => 'evidence:local-action-stub-01',
            'capacityEvidenceRef' => 'evidence:local-budget-stub-01',
            'contextWindow' => 4096,
            'maxOutputTokens' => 512,
            'answerReserve' => 384,
            'toolReserve' => 128,
        ]);
    }

    private function request(?GatewayModelProfile $profile = null, array $changes = []): GatewayModelRequest
    {
        $profile ??= $this->profile();
        $body = GatewayModelRequest::canonicalJson([
            'model' => $profile->values()['modelId'],
            'messages' => [['role' => 'user', 'content' => 'Публичный локальный сценарий.']],
            'stream' => false,
            'store' => false,
            'max_completion_tokens' => $profile->values()['maxOutputTokens'],
            'response_format' => ['type' => 'json_object'],
        ]);

        return GatewayModelRequest::fromArray(array_replace([
            'schemaVersion' => GatewayModelRequest::SCHEMA_VERSION,
            'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'requestRef' => 'request:local-test-request-01',
            'attemptRef' => 'attempt:local-test-attempt-01',
            'publicAdmissionRef' => 'admission:local-test-admission-01',
            'contextReceiptRef' => 'receipt:local-test-receipt-01',
            'corePayloadDigest' => str_repeat('a', 64),
            'coreReceiptDigest' => str_repeat('b', 64),
            'projectionRef' => 'projection:local-test-projection-01',
            'projectionDigest' => hash('sha256', $body),
            'profileRef' => $profile->values()['profileRef'],
            'profileFingerprint' => $profile->fingerprint(),
            'purpose' => GatewayModelRequest::PURPOSE,
            'expiresAt' => self::NOW + 60,
            'bodyBytes' => $body,
        ], $changes));
    }

    private function actionBytes(): string
    {
        return GatewayModelRequest::canonicalJson([
            'type' => 'tool', 'tool' => 'material.search',
            'arguments' => ['query' => 'бетон', 'limit' => 3],
        ]);
    }

    private function tokenCount(GatewayModelProfile $profile, int $tokens = 50): array
    {
        $value = $profile->values();

        return [
            'inputTokens' => $tokens,
            'tokenizerId' => $value['tokenizerId'],
            'tokenizerRevision' => $value['tokenizerRevision'],
            'mappingEvidenceRef' => $value['mappingEvidenceRef'],
        ];
    }

    private function transport(array $overrides = []): GatewayPublicCoreTransport
    {
        $args = array_replace([
            'profile' => $this->profile(),
            'runtimeReadiness' => static fn (): string => 'none',
            'peerIdentity' => static fn (): string => self::PEER,
            'expectedProcessorIdentity' => self::PEER,
            'currentBinding' => function (GatewayModelRequest $request): array {
                self::assertTrue($this->fenced);

                return $this->revoked ? ['reasonCode' => 'authorization_changed'] : $request->binding();
            },
            'serializedFence' => function (GatewayModelRequest $request, Closure $write): GatewayModelResponse {
                $this->fenced = true;
                try {
                    return $write();
                } finally {
                    $this->fenced = false;
                }
            },
            'tokenCounter' => fn (string $body, GatewayModelProfile $profile): array => $this->tokenCount($profile),
            'sender' => function (GatewayModelProfile $profile, string $body): array {
                self::assertTrue($this->fenced);
                $this->writes++;
                $this->written[] = $body;

                return ['actionBytes' => $this->actionBytes(), 'usage' => null];
            },
            'clock' => static fn (): int => self::NOW,
        ], $overrides);

        return new GatewayPublicCoreTransport(...$args);
    }

    public function test_immutable_packet_and_profile_are_detached(): void
    {
        $request = $this->request();
        $value = $request->values();
        $value['bodyBytes'] = 'changed';
        self::assertNotSame($value['bodyBytes'], $request->bodyBytes);
        self::assertSame(hash('sha256', $request->bodyBytes), $request->projectionDigest);
        $profile = $this->profile();
        $settings = $profile->values();
        $settings['modelId'] = 'changed';
        self::assertSame('local-action-stub', $profile->values()['modelId']);
        self::assertFalse(GatewayModelProfile::unqualified()->isQualified());
    }

    #[DataProvider('invalidPackets')]
    public function test_packet_rejects_forged_authority_and_malformed_bytes(string $key, mixed $value): void
    {
        $this->expectException(LogicException::class);
        $this->request(changes: [$key => $value]);
    }

    public static function invalidPackets(): array
    {
        return [
            'caller role' => ['role', 'gateway'],
            'caller trust' => ['trusted', true],
            'caller permission' => ['transportAllowed', true],
            'raw media' => ['media', 'data:image/png;base64,AAAA'],
            'destination' => ['endpoint', 'https://example.com'],
            'schema' => ['schemaVersion', 'public-core-model-request/2'],
            'version' => ['contractVersion', 'other/1'],
            'purpose' => ['purpose', 'assistant_chat'],
            'attempt type' => ['attemptRef', 1],
            'attempt header' => ['attemptRef', "attempt:valid-reference\r\nAuthorization: secret"],
            'digest mismatch' => ['projectionDigest', str_repeat('f', 64)],
            'digest type' => ['corePayloadDigest', []],
            'expiry type' => ['expiresAt', '1801821660'],
            'invalid UTF8' => ['bodyBytes', "\xff"],
            'NUL' => ['bodyBytes', "{}\0"],
            'oversized body' => ['bodyBytes', str_repeat('a', 262145)],
        ];
    }

    public function test_default_transport_never_calls_injected_sender(): void
    {
        $response = $this->transport(['runtimeReadiness' => null])->send($this->request());
        self::assertSame('unavailable', $response->status);
        self::assertSame('runtime_not_activated', $response->reasonCode);
        self::assertSame(0, $this->writes);
        self::assertSame('runtime_not_activated', (new GatewayPublicCoreTransport)->send($this->request())->reasonCode);
    }

    #[DataProvider('missingDependencies')]
    public function test_missing_trusted_dependencies_prevent_write(string $dependency, string $reason): void
    {
        $response = $this->transport([$dependency => null])->send($this->request());
        self::assertSame($reason, $response->reasonCode);
        self::assertSame(0, $this->writes);
    }

    public static function missingDependencies(): array
    {
        return [
            ['profile', 'model_profile_unqualified'],
            ['peerIdentity', 'gateway_identity_unavailable'],
            ['expectedProcessorIdentity', 'gateway_identity_unavailable'],
            ['currentBinding', 'receipt_unavailable'],
            ['serializedFence', 'receipt_unavailable'],
            ['tokenCounter', 'tokenizer_unqualified'],
            ['sender', 'gateway_not_configured'],
        ];
    }

    public function test_stub_writes_only_exact_bytes_while_fence_is_held(): void
    {
        $request = $this->request();
        $response = $this->transport()->send($request);
        self::assertSame('completed', $response->status);
        self::assertSame('none', $response->reasonCode);
        self::assertSame($request->bodyBytes, $this->written[0]);
        self::assertSame(1, $this->writes);
        self::assertSame($this->actionBytes(), $response->actionBytes);
        self::assertNull($response->usage);
        self::assertFalse($this->fenced);
        self::assertSame($response->values(), GatewayModelResponse::fromArray($response->values())->values());
    }

    public function test_profile_change_expiry_and_wrong_peer_produce_zero_writes(): void
    {
        $cases = [
            [$this->request(changes: ['profileFingerprint' => str_repeat('f', 64)]), [], 'profile_changed'],
            [$this->request(changes: ['expiresAt' => self::NOW]), [], 'expired'],
            [$this->request(), ['peerIdentity' => static fn (): string => 'app:local-test-identity-01'], 'gateway_identity_unavailable'],
        ];
        foreach ($cases as [$request, $overrides, $reason]) {
            self::assertSame($reason, $this->transport($overrides)->send($request)->reasonCode);
            self::assertSame(0, $this->writes);
        }
    }

    #[DataProvider('bindingChanges')]
    public function test_current_store_binding_is_rechecked(string $key, mixed $value, string $reason): void
    {
        $binding = array_replace($this->request()->binding(), [$key => $value]);
        $response = $this->transport(['currentBinding' => static fn (): array => $binding])->send($this->request());
        self::assertSame($reason, $response->reasonCode);
        self::assertSame(0, $this->writes);
    }

    public static function bindingChanges(): array
    {
        return [
            ['requestRef', 'request:another-public-request', 'authorization_changed'],
            ['attemptRef', 'attempt:another-public-attempt', 'authorization_changed'],
            ['publicAdmissionRef', 'admission:another-public-admission', 'authorization_changed'],
            ['contextReceiptRef', 'receipt:another-public-receipt', 'receipt_changed'],
            ['corePayloadDigest', str_repeat('c', 64), 'source_changed'],
            ['coreReceiptDigest', str_repeat('c', 64), 'receipt_changed'],
            ['projectionRef', 'projection:another-public-projection', 'source_changed'],
            ['projectionDigest', str_repeat('c', 64), 'source_changed'],
            ['profileRef', 'profile:another-public-profile', 'profile_changed'],
            ['profileFingerprint', str_repeat('c', 64), 'profile_changed'],
            ['purpose', 'assistant_chat', 'authorization_changed'],
            ['expiresAt', self::NOW - 1, 'expired'],
            ['authorized', true, 'receipt_unavailable'],
        ];
    }

    public function test_unavailable_store_cannot_be_replaced_by_success_boolean(): void
    {
        foreach ([null, true, ['status' => 'committed'], ['reasonCode' => 'source_unavailable']] as $binding) {
            $response = $this->transport(['currentBinding' => static fn () => $binding])->send($this->request());
            self::assertSame($binding === ['reasonCode' => 'source_unavailable'] ? 'source_unavailable' : 'receipt_unavailable', $response->reasonCode);
        }
        self::assertSame(0, $this->writes);
    }

    public function test_revocation_during_counting_is_observed_before_write(): void
    {
        $response = $this->transport(['tokenCounter' => function (string $body, GatewayModelProfile $profile): array {
            $this->revoked = true;

            return $this->tokenCount($profile);
        }])->send($this->request());
        self::assertSame('authorization_changed', $response->reasonCode);
        self::assertSame(0, $this->writes);
    }

    public function test_runtime_and_expiry_are_rechecked_after_counting(): void
    {
        $ready = true;
        $now = self::NOW;
        $counter = function (string $body, GatewayModelProfile $profile) use (&$ready, &$now): array {
            $ready = false;
            $now += 60;

            return $this->tokenCount($profile);
        };
        $readiness = static function () use (&$ready): string {
            return $ready ? 'none' : 'runtime_not_activated';
        };
        $clock = static function () use (&$now): int {
            return $now;
        };
        $response = $this->transport(['runtimeReadiness' => $readiness, 'tokenCounter' => $counter])->send($this->request());
        self::assertSame('runtime_not_activated', $response->reasonCode);
        $ready = true;
        $now = self::NOW;
        $response = $this->transport(['clock' => $clock, 'tokenCounter' => $counter])->send($this->request());
        self::assertSame('expired', $response->reasonCode);
        self::assertSame(0, $this->writes);
    }

    public function test_unqualified_tokenizer_and_budget_do_not_become_byte_estimates(): void
    {
        $cases = [
            [50, 'tokenizer_unqualified'],
            [['inputTokens' => 50], 'tokenizer_unqualified'],
            [$this->tokenCount($this->profile(), 3585), 'budget_exceeded'],
            [array_replace($this->tokenCount($this->profile()), ['tokenizerId' => 'unproven-o200k_base']), 'tokenizer_unqualified'],
        ];
        foreach ($cases as [$count, $reason]) {
            self::assertSame($reason, $this->transport(['tokenCounter' => static fn () => $count])->send($this->request())->reasonCode);
        }
        self::assertSame(0, $this->writes);
    }

    public function test_body_cannot_add_destination_tools_images_or_streaming(): void
    {
        $request = $this->request();
        $body = json_decode($request->bodyBytes, true, 64, JSON_THROW_ON_ERROR);
        foreach ([['stream' => true], ['store' => true], ['model' => 'other-model'], ['tools' => []], ['endpoint' => 'https://example.com'], ['messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => 'private']]]]]] as $change) {
            $bytes = GatewayModelRequest::canonicalJson(array_replace($body, $change));
            $changed = $this->request(changes: ['bodyBytes' => $bytes, 'projectionDigest' => hash('sha256', $bytes)]);
            self::assertSame('source_changed', $this->transport()->send($changed)->reasonCode);
        }
        self::assertSame(0, $this->writes);
    }

    public function test_duplicate_or_deferred_fence_callback_cannot_replay_payload(): void
    {
        $transport = $this->transport(['serializedFence' => function (GatewayModelRequest $request, Closure $write): GatewayModelResponse {
            $this->fenced = true;
            try {
                $first = $write();
                self::assertSame('receipt_changed', $write()->reasonCode);

                return $first;
            } finally {
                $this->fenced = false;
            }
        }]);
        self::assertSame('completed', $transport->send($this->request())->status);
        self::assertSame('receipt_changed', $transport->send($this->request())->reasonCode);
        self::assertSame(1, $this->writes);
        $deferred = null;
        $response = $this->transport(['serializedFence' => static function (GatewayModelRequest $request, Closure $write) use (&$deferred): null {
            $deferred = $write;

            return null;
        }])->send($this->request());
        self::assertSame('receipt_unavailable', $response->reasonCode);
        self::assertSame('receipt_changed', $deferred()->reasonCode);
        self::assertSame(1, $this->writes);
    }

    public function test_unknown_provider_outcome_cannot_retry_or_expose_raw_error(): void
    {
        $transport = $this->transport(['sender' => function (): never {
            $this->writes++;
            throw new RuntimeException('secret-provider-body-private-content');
        }]);
        $response = $transport->send($this->request());
        self::assertSame('gateway_unavailable', $response->reasonCode);
        self::assertStringNotContainsString('secret-provider', GatewayModelRequest::canonicalJson($response->values()));
        self::assertSame('receipt_changed', $transport->send($this->request())->reasonCode);
        self::assertSame(1, $this->writes);
    }

    public function test_strict_action_and_usage_reject_raw_or_malformed_provider_output(): void
    {
        $outputs = [
            ['raw' => 'provider body'],
            ['actionBytes' => '{}', 'usage' => null],
            ['actionBytes' => GatewayModelRequest::canonicalJson(['type' => 'tool', 'tool' => 'project.delete', 'arguments' => []]), 'usage' => null],
            ['actionBytes' => '{"type":"plan","plan":"one","plan":"two"}', 'usage' => null],
            ['actionBytes' => $this->actionBytes(), 'usage' => ['bytes' => 50]],
            ['actionBytes' => $this->actionBytes(), 'usage' => ['inputTokens' => 10, 'outputTokens' => -1, 'totalTokens' => 9]],
            ['actionBytes' => $this->actionBytes(), 'usage' => ['inputTokens' => 10, 'outputTokens' => 2, 'totalTokens' => 50]],
        ];
        foreach ($outputs as $output) {
            self::assertSame('invalid_model_output', $this->transport(['sender' => static fn (): array => $output])->send($this->request())->reasonCode);
        }
    }

    public function test_supplied_token_usage_is_preserved(): void
    {
        $usage = ['inputTokens' => 42, 'outputTokens' => 8, 'totalTokens' => 50];
        $response = $this->transport(['sender' => fn (): array => ['actionBytes' => $this->actionBytes(), 'usage' => $usage]])->send($this->request());
        self::assertSame('completed', $response->status);
        self::assertSame($usage, $response->usage);
    }

    public function test_actual_profile_requires_independent_evidence_and_fixed_destination(): void
    {
        $base = $this->profile()->values();
        foreach ([['qualification' => 'actual'], ['endpoint' => 'https://example.com'], ['tokenizerRevision' => ''], ['capacityEvidenceRef' => null], ['contextWindow' => null], ['maxOutputTokens' => 4096]] as $change) {
            try {
                GatewayModelProfile::fromArray(array_replace($base, $change));
                self::fail('Unqualified profile accepted');
            } catch (LogicException $exception) {
                self::assertSame('model_profile_unqualified', $exception->getMessage());
            }
        }
        self::assertSame(0, $this->writes);
    }

    public function test_response_cannot_carry_action_on_blocked_or_foreign_fields(): void
    {
        $base = GatewayModelResponse::blocked($this->request(), 'expired')->values();
        foreach ([['actionBytes' => $this->actionBytes()], ['reasonCode' => 'secret'], ['usage' => []], ['rawProviderBody' => 'private']] as $change) {
            try {
                GatewayModelResponse::fromArray(array_replace($base, $change));
                self::fail('Invalid response accepted');
            } catch (LogicException $exception) {
                self::assertSame('invalid_model_output', $exception->getMessage());
            }
        }
    }

    public function test_unactivated_entrypoint_reads_no_request_and_returns_unavailable(): void
    {
        $entry = dirname(__DIR__, 4).'/gateway/public-core/entry.php';
        $process = new Process([PHP_BINARY, $entry]);
        $process->run();
        self::assertSame(0, $process->getExitCode());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame([
            'schemaVersion' => 'public-core-gateway-entry/1',
            'status' => 'unavailable',
            'reasonCode' => 'runtime_not_activated',
        ], json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR));
    }
}
