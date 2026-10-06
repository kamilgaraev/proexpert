<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\Gateway;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender;
use App\Services\Privacy\Gateway\GatewayPublicCoreTransport;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
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
            'answerReserve' => 768,
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
        self::assertFalse(GatewayModelProfile::unqualified()->isActualProfile());
        self::assertNull(GatewayModelProfile::unqualified()->inputBudget());
    }

    public function test_gateway_profile_budgets_match_the_frozen_core_and_count_the_full_body(): void
    {
        $gateway = $this->profile();
        $settings = $gateway->values();
        $coreValues = array_intersect_key($settings, array_flip([
            'profileRef', 'qualification', 'adapterRevision', 'modelId', 'modelRevision',
            'tokenizerId', 'tokenizerRevision', 'contextWindow', 'maxOutputTokens',
            'answerReserve', 'toolReserve',
        ]));
        $coreValues['qualification'] = 'offline-synthetic';
        $core = AssistantModelContextProfile::resolve($settings['profileRef'], static function (string $ref) use ($coreValues): array {
            self::assertSame($coreValues['profileRef'], $ref);

            return $coreValues;
        });
        self::assertCount(16, $settings);
        self::assertCount(11, $coreValues);
        self::assertSame('offline-synthetic', $coreValues['qualification']);
        self::assertSame($core->modelPayload(), array_intersect_key($settings, $core->modelPayload()));
        self::assertNotSame($core->fingerprint(), $gateway->fingerprint());
        self::assertSame($core->inputBudget(), $gateway->inputBudget());
        self::assertTrue($gateway->isQualified());
        self::assertFalse($gateway->isActualProfile());
        $request = $this->request($gateway);
        $body = json_decode($request->bodyBytes, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($core->modelPayload()['maxOutputTokens'], $body['max_completion_tokens']);
        $countedBodies = [];
        $atBoundary = $this->transport(['tokenCounter' => function (string $bytes, GatewayModelProfile $profile) use ($core, &$countedBodies): array {
            $countedBodies[] = $bytes;

            return $this->tokenCount($profile, $core->inputBudget());
        }])->send($request);
        self::assertSame('completed', $atBoundary->status);
        self::assertSame([$request->bodyBytes], $countedBodies);
        self::assertSame(1, $this->writes);
        $overflow = $this->transport(['tokenCounter' => fn (string $bytes, GatewayModelProfile $profile): array => $this->tokenCount($profile, $core->inputBudget() + 1)])->send($request);
        self::assertSame('budget_exceeded', $overflow->reasonCode);
        self::assertSame(1, $this->writes);
        $body['max_completion_tokens']++;
        $bytes = GatewayModelRequest::canonicalJson($body);
        $outputOverflow = $this->request($gateway, ['bodyBytes' => $bytes, 'projectionDigest' => hash('sha256', $bytes)]);
        self::assertSame('source_changed', $this->transport()->send($outputOverflow)->reasonCode);
        self::assertSame(1, $this->writes);
    }

    #[DataProvider('profileBudgetBoundaries')]
    public function test_profile_capacity_boundaries_use_the_frozen_core(array $changes, ?int $expectedBudget): void
    {
        $settings = array_replace($this->profile()->values(), $changes);
        $coreValues = array_intersect_key($settings, array_flip([
            'profileRef', 'qualification', 'adapterRevision', 'modelId', 'modelRevision',
            'tokenizerId', 'tokenizerRevision', 'contextWindow', 'maxOutputTokens',
            'answerReserve', 'toolReserve',
        ]));
        $coreValues['qualification'] = 'offline-synthetic';
        if ($expectedBudget !== null) {
            $gateway = GatewayModelProfile::fromArray($settings);
            $core = AssistantModelContextProfile::resolve($settings['profileRef'], static fn (): array => $coreValues);
            self::assertSame($expectedBudget, $core->inputBudget());
            self::assertSame($core->inputBudget(), $gateway->inputBudget());
            self::assertSame($settings['maxOutputTokens'], $core->modelPayload()['maxOutputTokens']);
        } else {
            try {
                AssistantModelContextProfile::resolve($settings['profileRef'], static fn (): array => $coreValues);
                self::fail('Frozen core accepted invalid capacity');
            } catch (LogicException $exception) {
                self::assertSame('model_profile_invalid_capacity', $exception->getMessage());
            }
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('model_profile_unqualified');
            GatewayModelProfile::fromArray($settings);
        }
    }

    public static function profileBudgetBoundaries(): array
    {
        return [
            'output exactly fits answer reserve' => [['answerReserve' => 512], 3456],
            'conservative answer reserve' => [[], 3200],
            'one token remains for full input' => [['answerReserve' => 512, 'contextWindow' => 641], 1],
            'output exceeds answer reserve' => [['maxOutputTokens' => 769], null],
            'answer exhausts context' => [['answerReserve' => 4096], null],
            'tool reserve exhausts remaining context' => [['toolReserve' => 3328], null],
        ];
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
            [$this->tokenCount($this->profile(), 3201), 'budget_exceeded'],
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

    public function test_provider_usage_cannot_exceed_input_or_output_budget(): void
    {
        $profile = $this->profile();
        $boundary = ['inputTokens' => $profile->inputBudget(), 'outputTokens' => $profile->values()['maxOutputTokens']];
        $boundary['totalTokens'] = $boundary['inputTokens'] + $boundary['outputTokens'];
        $completed = $this->transport(['sender' => fn (): array => ['actionBytes' => $this->actionBytes(), 'usage' => $boundary]])->send($this->request());
        self::assertSame('completed', $completed->status);
        self::assertSame($boundary, $completed->usage);
        $overflows = [
            ['inputTokens' => $profile->inputBudget() + 1, 'outputTokens' => 0],
            ['inputTokens' => 0, 'outputTokens' => $profile->values()['maxOutputTokens'] + 1],
            ['inputTokens' => PHP_INT_MAX, 'outputTokens' => 0],
        ];
        foreach ($overflows as $usage) {
            $usage['totalTokens'] = $usage['inputTokens'] + $usage['outputTokens'];
            $writes = 0;
            $response = $this->transport(['sender' => function () use ($usage, &$writes): array {
                $writes++;

                return ['actionBytes' => $this->actionBytes(), 'usage' => $usage];
            }])->send($this->request());
            self::assertSame('budget_exceeded', $response->reasonCode);
            self::assertSame(1, $writes);
            self::assertNull($response->actionBytes);
            self::assertNull($response->usage);
        }
    }

    public function test_actual_profile_requires_independent_evidence_and_fixed_destination(): void
    {
        $base = $this->profile()->values();
        foreach ([['qualification' => 'actual'], ['endpoint' => 'https://example.com'], ['tokenizerRevision' => ''], ['capacityEvidenceRef' => null], ['contextWindow' => null], ['maxOutputTokens' => 4096], ['contextWindow' => PHP_INT_MAX], ['maxOutputTokens' => PHP_INT_MAX], ['answerReserve' => PHP_INT_MAX], ['toolReserve' => PHP_INT_MAX]] as $change) {
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

    private function httpSourceFixtureProfile(): GatewayModelProfile
    {
        return GatewayModelProfile::fromArray(array_replace($this->profile()->values(), [
            'qualification' => 'actual',
            'apiMethod' => 'chat_completions',
            'endpoint' => GatewayPublicCoreHttpSender::ENDPOINT,
            'modelId' => 'source-fixture-model',
            'modelRevision' => 'source-fixture-v1',
            'tokenizerId' => 'source-fixture-tokenizer',
        ]));
    }

    private function providerEnvelope(?string $content = null): array
    {
        return [
            'id' => 'source-fixture-completion-01',
            'object' => 'chat.completion',
            'created' => self::NOW,
            'model' => 'source-fixture-model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content ?? ' { "type": "plan", "plan": "Проверить публичные источники" } '], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 8, 'total_tokens' => 50],
        ];
    }

    private function sourceHttpExecutor(string $response, int $status = 200, int $error = 0): Closure
    {
        return static function ($handle, array $options) use ($response, $status, $error): array {
            $length = strlen($options[CURLOPT_POSTFIELDS]);
            $options[CURLOPT_XFERINFOFUNCTION]($handle, 0.0, 0.0, (float) $length, (float) $length);
            $options[CURLOPT_WRITEFUNCTION]($handle, $response);

            return ['status' => $status, 'errorCode' => $error, 'uploadedBytes' => $length];
        };
    }

    public function test_concrete_http_sender_uses_pinned_options_exact_bytes_and_private_decoder(): void
    {
        $profile = $this->httpSourceFixtureProfile();
        $request = $this->request($profile);
        $phase = 'guard_pending';
        $exchanges = 0;
        $executor = $this->sourceHttpExecutor(json_encode($this->providerEnvelope(), JSON_THROW_ON_ERROR));
        $sender = new GatewayPublicCoreHttpSender(
            static fn (): string => 'source-fixture-credential-not-a-provider-key',
            function ($handle, array $options) use ($executor, $request, &$phase, &$exchanges): array {
                self::assertSame('write_authorized', $phase);
                self::assertSame(GatewayPublicCoreHttpSender::ENDPOINT, $options[CURLOPT_URL]);
                self::assertSame($request->bodyBytes, $options[CURLOPT_POSTFIELDS]);
                self::assertTrue($options[CURLOPT_POST]);
                self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
                self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
                self::assertSame('', $options[CURLOPT_PROXY]);
                self::assertSame('*', $options[CURLOPT_NOPROXY]);
                self::assertSame(CURL_NETRC_IGNORED, $options[CURLOPT_NETRC]);
                self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
                self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
                self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
                self::assertSame(2000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
                self::assertSame(10000, $options[CURLOPT_TIMEOUT_MS]);
                self::assertCount(4, $options[CURLOPT_HTTPHEADER]);
                $exchanges++;

                return $executor($handle, $options);
            },
        );
        $result = $sender->send($profile, $request->bodyBytes,
            function () use (&$phase): void {
                $phase = 'write_authorized';
            },
            function (int $length) use ($request, &$phase): void {
                self::assertSame(strlen($request->bodyBytes), $length);
                self::assertSame('write_authorized', $phase);
                $phase = 'uploaded';
            },
        );
        self::assertSame(1, $exchanges);
        self::assertSame('uploaded', $phase);
        self::assertSame(GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Проверить публичные источники']), $result['actionBytes']);
        self::assertSame(['inputTokens' => 42, 'outputTokens' => 8, 'totalTokens' => 50], $result['usage']);
        self::assertTrue($profile->isActualProfile());
        self::assertSame('runtime_not_activated', (new GatewayPublicCoreTransport)->send($request)->reasonCode);
    }

    public function test_concrete_http_sender_rejects_unknown_guard_or_profile_before_exchange(): void
    {
        $calls = 0;
        $keys = 0;
        $sender = new GatewayPublicCoreHttpSender(
            static function () use (&$keys): string {
                $keys++;

                return 'source-fixture-credential-not-a-provider-key';
            },
            static function () use (&$calls): array {
                $calls++;

                return [];
            },
        );
        foreach ([[$this->profile(), 'model_profile_unqualified'], [$this->httpSourceFixtureProfile(), 'gateway_channel_unavailable']] as [$profile, $reason]) {
            try {
                $sender->send($profile, $this->request($profile)->bodyBytes);
                self::fail('Unknown source gate accepted');
            } catch (LogicException $error) {
                self::assertSame($reason, $error->getMessage());
            }
        }
        self::assertSame(0, $calls);
        self::assertSame(0, $keys);
        try {
            $profile = $this->httpSourceFixtureProfile();
            $sender->send($profile, $this->request($profile)->bodyBytes,
                static function (): void {
                    throw new LogicException('authorization_changed');
                },
                static function (): void {},
            );
            self::fail('Revoked write executed');
        } catch (LogicException $error) {
            self::assertSame('authorization_changed', $error->getMessage());
        }
        self::assertSame(0, $calls);
    }

    #[DataProvider('invalidProviderContents')]
    public function test_concrete_private_decoder_rejects_malformed_duplicate_or_native_tool_actions(string $content): void
    {
        $profile = $this->httpSourceFixtureProfile();
        $sender = new GatewayPublicCoreHttpSender(
            static fn (): string => 'source-fixture-credential-not-a-provider-key',
            $this->sourceHttpExecutor(json_encode($this->providerEnvelope($content), JSON_THROW_ON_ERROR)),
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('invalid_model_output');
        $sender->send($profile, $this->request($profile)->bodyBytes, static function (): void {}, static function (): void {});
    }

    public static function invalidProviderContents(): array
    {
        return [
            ['{broken'],
            ['{"type":"plan","plan":"one","plan":"two"}'],
            ['{"type":"plan","plan":"one","pl\\u0061n":"two"}'],
            ['{"type":"tool","tool":"project.delete","arguments":{}}'],
            ['{"type":"tool","tool":"material.search","arguments":{"query":"бетон","limit":99}}'],
            ['```json\n{"type":"plan","plan":"one"}\n```'],
            ['{"type":"plan","plan":"one","rawPrivateData":"secret"}'],
        ];
    }

    public function test_concrete_sender_envelope_usage_and_transport_failures_are_allowlisted(): void
    {
        $profile = $this->httpSourceFixtureProfile();
        $base = $this->providerEnvelope();
        $cases = [
            [array_replace($base, ['model' => 'other-model']), 'invalid_model_output', 200, 0],
            [array_replace($base, ['choices' => []]), 'invalid_model_output', 200, 0],
            [array_replace_recursive($base, ['choices' => [['finish_reason' => 'length']]]), 'invalid_model_output', 200, 0],
            [array_replace_recursive($base, ['choices' => [['message' => ['refusal' => 'no']]]]), 'invalid_model_output', 200, 0],
            [array_replace_recursive($base, ['choices' => [['message' => ['tool_calls' => []]]]]), 'invalid_model_output', 200, 0],
            [array_replace($base, ['usage' => ['prompt_tokens' => 42, 'completion_tokens' => 513, 'total_tokens' => 555]]), 'budget_exceeded', 200, 0],
            [array_replace($base, ['usage' => ['prompt_tokens' => 3201, 'completion_tokens' => 0, 'total_tokens' => 3201]]), 'budget_exceeded', 200, 0],
            [array_replace($base, ['usage' => ['prompt_tokens' => 42, 'completion_tokens' => 8, 'total_tokens' => 99]]), 'invalid_model_output', 200, 0],
            [['error' => 'raw-private-provider-error'], 'gateway_unavailable', 401, 0],
            [$base, 'gateway_unavailable', 302, 0],
            [$base, 'gateway_unavailable', 200, 28],
        ];
        foreach ($cases as [$body, $reason, $status, $errorCode]) {
            $calls = 0;
            $executor = $this->sourceHttpExecutor(json_encode($body, JSON_THROW_ON_ERROR), $status, $errorCode);
            $sender = new GatewayPublicCoreHttpSender(
                static fn (): string => 'source-fixture-credential-not-a-provider-key',
                static function ($handle, array $options) use ($executor, &$calls): array {
                    $calls++;

                    return $executor($handle, $options);
                },
            );
            try {
                $sender->send($profile, $this->request($profile)->bodyBytes, static function (): void {}, static function (): void {});
                self::fail('Invalid provider result accepted');
            } catch (LogicException $error) {
                self::assertSame($reason, $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
            self::assertSame(1, $calls);
        }
    }

    #[DataProvider('nativeControlCases')]
    public function test_native_channel_composes_real_sender_with_current_guard_and_upload_release(bool $denyWrite): void
    {
        if (! AuthenticatedPublicCoreChannel::isNativeAvailable() || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('Native SCM_CREDENTIALS branch requires isolated Linux sockets/pcntl; Windows skip is not Linux PASS');
        }
        $directory = sys_get_temp_dir().'/gate27-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $path = $directory.'/channel.sock';
        $lockPath = $directory.'/authority.lock';
        $listener = AuthenticatedPublicCoreChannel::listen($path);
        $profile = $this->httpSourceFixtureProfile();
        $request = $this->request($profile, ['expiresAt' => time() + 10]);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $parentPid = getmypid();
        $lock = fopen($lockPath, 'c');
        fclose($lock);
        $child = pcntl_fork();
        self::assertGreaterThan(0, $child === 0 ? 1 : $child);
        if ($child === 0) {
            socket_close($listener);
            $held = fopen($lockPath, 'c');
            flock($held, LOCK_EX);
            try {
                $channel = AuthenticatedPublicCoreChannel::connect($path, ['uid' => $uid, 'gid' => $gid, 'pid' => $parentPid], 5000);
                $expiresAt = min($request->expiresAt, $channel->deadlineExpiresAt());
                $channel->send('dispatch', $request->requestRef, $request->attemptRef, $request->values(), $expiresAt);
                $authorized = false;
                $uploaded = false;
                $uploadObserved = static function () use (&$uploaded): bool {
                    return $uploaded;
                };
                $checks = 0;
                while (true) {
                    $frame = $channel->receive();
                    if ($frame['requestRef'] !== $request->requestRef || $frame['attemptRef'] !== $request->attemptRef) {
                        exit(41);
                    }
                    if ($frame['command'] === 'check_binding') {
                        $checks++;
                        $channel->send('binding', $request->requestRef, $request->attemptRef, $request->binding(), $expiresAt);
                    } elseif ($frame['command'] === 'authorize_write') {
                        if ($checks !== 3 || $authorized) {
                            exit(42);
                        }
                        $authorized = true;
                        $grant = $denyWrite ? ['reasonCode' => 'authorization_changed'] : $request->binding();
                        $channel->send('write_authorized', $request->requestRef, $request->attemptRef, $grant, $expiresAt);
                    } elseif ($frame['command'] === 'upload_complete') {
                        if (! $authorized || $denyWrite || $frame['payload']['projectionDigest'] !== $request->projectionDigest
                            || $frame['payload']['bodyLength'] !== strlen($request->bodyBytes)) {
                            exit(43);
                        }
                        $uploaded = true;
                        flock($held, LOCK_UN);
                        $channel->send('uploaded', $request->requestRef, $request->attemptRef, $frame['payload'], $expiresAt);
                    } elseif ($frame['command'] === 'result') {
                        $response = GatewayModelResponse::fromArray($frame['payload']);
                        if (($denyWrite && ($response->reasonCode !== 'authorization_changed' || $uploadObserved()))
                            || (! $denyWrite && ($response->status !== 'completed' || ! $uploadObserved()))) {
                            exit(44);
                        }
                        break;
                    } else {
                        exit(45);
                    }
                }
                $channel->close();
                fclose($held);
                exit(0);
            } catch (\Throwable) {
                exit(46);
            }
        }
        $channel = null;
        $providerCalls = 0;
        try {
            $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => $uid, 'gid' => $gid, 'pid' => $child], 5000);
            $executor = $this->sourceHttpExecutor(json_encode($this->providerEnvelope(), JSON_THROW_ON_ERROR));
            $sender = new GatewayPublicCoreHttpSender(
                static fn (): string => 'source-fixture-credential-not-a-provider-key',
                static function ($handle, array $options) use ($executor, $lockPath, &$providerCalls): array {
                    $providerCalls++;
                    $check = fopen($lockPath, 'c');
                    if (flock($check, LOCK_EX | LOCK_NB)) {
                        throw new LogicException('authorization_changed');
                    }
                    $result = $executor($handle, $options);
                    if (! flock($check, LOCK_EX | LOCK_NB)) {
                        throw new LogicException('gateway_channel_unavailable');
                    }
                    flock($check, LOCK_UN);
                    fclose($check);

                    return $result;
                },
            );
            $response = GatewayPublicCoreTransport::handleAuthenticatedChannel($channel, $profile, $sender,
                fn (string $bytes, GatewayModelProfile $selected): array => $this->tokenCount($selected),
                static function (GatewayModelProfile $selected, array $peer) use ($profile, $child, $uid, $gid): string {
                    return $selected->fingerprint() === $profile->fingerprint() && $peer === ['pid' => $child, 'uid' => $uid, 'gid' => $gid]
                        ? 'none' : 'gateway_identity_unavailable';
                },
            );
            self::assertSame($denyWrite ? 'authorization_changed' : 'none', $response->reasonCode);
            self::assertSame($denyWrite ? 0 : 1, $providerCalls);
        } finally {
            $channel?->close();
            socket_close($listener);
            pcntl_waitpid($child, $status);
            unlink($path);
            unlink($lockPath);
            rmdir($directory);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public static function nativeControlCases(): array
    {
        return ['qualified source upload' => [false], 'revoke before final write' => [true]];
    }

    public function test_bootstrap_rejects_caller_role_flags_before_key_or_socket_access(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gate27-config-');
        file_put_contents($file, json_encode([
            'schemaVersion' => 'public-core-gateway-runtime/1',
            'activation' => 'approved',
            'role' => 'gateway',
            'authorized' => true,
            'gatewayUid' => function_exists('posix_geteuid') ? posix_geteuid() : 1,
            'gatewayGid' => function_exists('posix_getegid') ? posix_getegid() : 1,
            'credentialFile' => '/not-a-source-fixture-provider-key',
            'socketPath' => '/not-a-source-fixture-socket',
            'profile' => $this->httpSourceFixtureProfile()->values(),
        ], JSON_THROW_ON_ERROR));
        try {
            GatewayPublicCoreTransport::serveProtected($file);
            self::fail('Caller flags started protected runtime');
        } catch (LogicException $error) {
            self::assertContains($error->getMessage(), ['runtime_not_activated', 'gateway_identity_unavailable']);
        } finally {
            unlink($file);
        }
    }

    public function test_http_sender_bounds_response_and_preserves_unavailable_usage(): void
    {
        $profile = $this->httpSourceFixtureProfile();
        $body = $this->providerEnvelope();
        unset($body['usage']);
        $sender = new GatewayPublicCoreHttpSender(
            static fn (): string => 'source-fixture-credential-not-a-provider-key',
            $this->sourceHttpExecutor(json_encode($body, JSON_THROW_ON_ERROR)),
        );
        $result = $sender->send($profile, $this->request($profile)->bodyBytes, static function (): void {}, static function (): void {});
        self::assertNull($result['usage']);
        $sender = new GatewayPublicCoreHttpSender(
            static fn (): string => 'source-fixture-credential-not-a-provider-key',
            $this->sourceHttpExecutor(str_repeat('a', GatewayPublicCoreHttpSender::MAX_RESPONSE_BYTES + 1)),
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('invalid_model_output');
        $sender->send($profile, $this->request($profile)->bodyBytes, static function (): void {}, static function (): void {});
    }
}
