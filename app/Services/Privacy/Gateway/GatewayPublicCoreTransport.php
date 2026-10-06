<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\Contracts\GatewayModelTransport;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Closure;
use LogicException;
use Throwable;
use Yethee\Tiktoken\Encoder\NativeEncoder;
use Yethee\Tiktoken\Vocab\Vocab;

final class GatewayPublicCoreTransport implements GatewayModelTransport
{
    private readonly GatewayModelProfile $profile;

    private readonly GatewayPublicCoreRequestValidator $validator;

    private array $attempts = [];

    public static function serveProtected(string $configurationFile): void
    {
        if (! AuthenticatedPublicCoreChannel::isNativeAvailable()) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $configuration = self::protectedConfiguration($configurationFile);
        $profile = GatewayModelProfile::fromArray($configuration['profile']);
        $pattern = self::protectedBytes($configuration['tokenizerPattern'], 32768);
        $vocabulary = self::protectedBytes($configuration['tokenizerFile'], 16777216);
        if (! hash_equals($configuration['tokenizerPatternSha256'], hash('sha256', $pattern))
            || ! hash_equals($configuration['tokenizerSha256'], hash('sha256', $vocabulary))) {
            throw new LogicException('tokenizer_unqualified');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new LogicException('tokenizer_unqualified');
        }
        try {
            fwrite($stream, $vocabulary);
            rewind($stream);
            $encoder = new NativeEncoder($configuration['tokenizerVocabulary'], Vocab::fromStream($stream), $pattern);
        } finally {
            fclose($stream);
        }
        $counter = static function (string $bytes, GatewayModelProfile $selected) use ($encoder): array {
            $settings = $selected->values();

            return [
                'inputTokens' => count($encoder->encode($bytes)),
                'tokenizerId' => $settings['tokenizerId'],
                'tokenizerRevision' => $settings['tokenizerRevision'],
                'mappingEvidenceRef' => $settings['mappingEvidenceRef'],
            ];
        };
        $readiness = static function (GatewayModelProfile $selected, array $peer) use ($configurationFile): string {
            try {
                $current = self::protectedConfiguration($configurationFile);
                $currentProfile = GatewayModelProfile::fromArray($current['profile']);
                if (! hash_equals($selected->fingerprint(), $currentProfile->fingerprint())) {
                    return 'profile_changed';
                }
                if ($peer['uid'] !== $current['processorPeer']['uid'] || $peer['gid'] !== $current['processorPeer']['gid']
                    || ($current['processorPeer']['pid'] !== null && $peer['pid'] !== $current['processorPeer']['pid'])) {
                    return 'gateway_identity_unavailable';
                }

                return 'none';
            } catch (Throwable) {
                return 'runtime_not_activated';
            }
        };
        $sender = new GatewayPublicCoreHttpSender(static function () use ($configurationFile): string {
            $current = self::protectedConfiguration($configurationFile);

            return trim(self::protectedBytes($current['credentialFile'], 4096));
        });
        $listener = AuthenticatedPublicCoreChannel::listen($configuration['socketPath']);
        try {
            for ($handled = 0; $handled < $configuration['maxRequests']; $handled++) {
                $channel = null;
                try {
                    $current = self::protectedConfiguration($configurationFile);
                    $channel = AuthenticatedPublicCoreChannel::accept($listener, $current['processorPeer'], $current['deadlineMs']);
                    self::handleAuthenticatedChannel($channel, $profile, $sender, $counter, $readiness);
                } catch (Throwable) {
                } finally {
                    $channel?->close();
                }
            }
        } finally {
            socket_close($listener);
        }
    }

    private static function protectedConfiguration(string $path): array
    {
        $configuration = json_decode(self::protectedBytes($path, 65536), true, 64, JSON_THROW_ON_ERROR);
        if (! GatewayModelRequest::hasExactKeys($configuration, [
            'schemaVersion', 'activation', 'gatewayUid', 'gatewayGid', 'processorPeer', 'socketPath',
            'profile', 'evidenceDirectory', 'evidence', 'credentialFile', 'tokenizerFile',
            'tokenizerSha256', 'tokenizerPattern', 'tokenizerPatternSha256', 'tokenizerVocabulary',
            'deadlineMs', 'maxRequests',
        ]) || $configuration['schemaVersion'] !== 'public-core-gateway-runtime/1'
            || $configuration['activation'] !== 'approved'
            || $configuration['gatewayUid'] !== posix_geteuid() || $configuration['gatewayUid'] === 0
            || $configuration['gatewayGid'] !== posix_getegid()
            || ! GatewayModelRequest::hasExactKeys($configuration['processorPeer'], ['uid', 'gid', 'pid'])
            || ! is_int($configuration['processorPeer']['uid']) || $configuration['processorPeer']['uid'] < 1
            || $configuration['processorPeer']['uid'] === $configuration['gatewayUid']
            || ! is_int($configuration['processorPeer']['gid']) || $configuration['processorPeer']['gid'] < 1
            || ($configuration['processorPeer']['pid'] !== null && (! is_int($configuration['processorPeer']['pid']) || $configuration['processorPeer']['pid'] < 1))
            || ! is_int($configuration['deadlineMs']) || $configuration['deadlineMs'] < 12000 || $configuration['deadlineMs'] > 30000
            || ! is_int($configuration['maxRequests']) || $configuration['maxRequests'] < 1 || $configuration['maxRequests'] > 128) {
            throw new LogicException('runtime_not_activated');
        }
        $profile = GatewayModelProfile::fromArray($configuration['profile']);
        if (! $profile->isActualProfile() || ! GatewayModelRequest::isDigest($configuration['tokenizerSha256'])
            || ! GatewayModelRequest::isDigest($configuration['tokenizerPatternSha256'])
            || ! is_string($configuration['tokenizerVocabulary']) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $configuration['tokenizerVocabulary']) !== 1) {
            throw new LogicException('model_profile_unqualified');
        }
        foreach (['socketPath', 'evidenceDirectory', 'credentialFile', 'tokenizerFile', 'tokenizerPattern'] as $key) {
            if (! is_string($configuration[$key]) || ! str_starts_with($configuration[$key], '/') || str_contains($configuration[$key], "\0")) {
                throw new LogicException('runtime_not_activated');
            }
        }
        $kinds = ['catalog', 'method', 'capacity', 'tokenizer', 'key', 'identity', 'channel', 'egress', 'backendAuthority'];
        if (! GatewayModelRequest::hasExactKeys($configuration['evidence'], $kinds)) {
            throw new LogicException('runtime_not_activated');
        }
        $proofs = [];
        foreach ($kinds as $kind) {
            $reference = $configuration['evidence'][$kind];
            if (! GatewayModelRequest::hasExactKeys($reference, ['ref', 'file', 'sha256'])
                || ! GatewayModelRequest::isReference($reference['ref']) || ! GatewayModelRequest::isDigest($reference['sha256'])
                || ! is_string($reference['file']) || preg_match('/\A[A-Za-z0-9_-]{1,128}\.json\z/D', $reference['file']) !== 1) {
                throw new LogicException('runtime_not_activated');
            }
            $bytes = self::protectedBytes($configuration['evidenceDirectory'].'/'.$reference['file'], 65536);
            if (! hash_equals($reference['sha256'], hash('sha256', $bytes))) {
                throw new LogicException('runtime_not_activated');
            }
            $proof = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! GatewayModelRequest::hasExactKeys($proof, [
                'schemaVersion', 'kind', 'ref', 'status', 'profileFingerprint', 'modelId', 'apiMethod', 'issuedAt', 'expiresAt', 'details',
            ]) || $proof['schemaVersion'] !== 'public-core-runtime-evidence/1'
                || $proof['kind'] !== $kind || $proof['ref'] !== $reference['ref'] || $proof['status'] !== 'verified'
                || $proof['profileFingerprint'] !== $profile->fingerprint() || $proof['modelId'] !== $profile->values()['modelId']
                || $proof['apiMethod'] !== $profile->values()['apiMethod'] || ! is_int($proof['issuedAt']) || $proof['issuedAt'] < 1
                || ! is_int($proof['expiresAt']) || $proof['expiresAt'] <= $proof['issuedAt']
                || $proof['issuedAt'] > time() || $proof['expiresAt'] <= time()
                || ! is_array($proof['details'])) {
                throw new LogicException('runtime_not_activated');
            }
            $proofs[$kind] = $proof['details'];
        }
        $settings = $profile->values();
        if ($configuration['evidence']['method']['ref'] !== $settings['capabilityEvidenceRef']
            || $configuration['evidence']['capacity']['ref'] !== $settings['capacityEvidenceRef']
            || $configuration['evidence']['tokenizer']['ref'] !== $settings['mappingEvidenceRef']
            || ($proofs['method']['endpoint'] ?? null) !== GatewayPublicCoreHttpSender::ENDPOINT
            || ($proofs['method']['templateVersion'] ?? null) !== 'chat-completions-action/1'
            || ($proofs['catalog']['modelRevision'] ?? null) !== $settings['modelRevision']
            || ! GatewayModelRequest::isDigest($proofs['catalog']['catalogDigest'] ?? null)
            || ! is_int($proofs['capacity']['contextWindow'] ?? null) || $settings['contextWindow'] > $proofs['capacity']['contextWindow']
            || ! is_int($proofs['capacity']['maxOutputTokens'] ?? null) || $settings['maxOutputTokens'] > $proofs['capacity']['maxOutputTokens']
            || ($proofs['tokenizer']['tokenizerId'] ?? null) !== $settings['tokenizerId']
            || ($proofs['tokenizer']['tokenizerRevision'] ?? null) !== $settings['tokenizerRevision']
            || ($proofs['tokenizer']['modelRevision'] ?? null) !== $settings['modelRevision']
            || ($proofs['tokenizer']['countMethod'] ?? null) !== 'full_wire_json_bpe_upper_bound'
            || ($proofs['tokenizer']['vocabularySha256'] ?? null) !== $configuration['tokenizerSha256']
            || ($proofs['tokenizer']['patternSha256'] ?? null) !== $configuration['tokenizerPatternSha256']
            || ($proofs['key']['credentialFile'] ?? null) !== $configuration['credentialFile']
            || ($proofs['identity']['gatewayUid'] ?? null) !== $configuration['gatewayUid']
            || ($proofs['identity']['gatewayGid'] ?? null) !== $configuration['gatewayGid']
            || ($proofs['identity']['processorUid'] ?? null) !== $configuration['processorPeer']['uid']
            || ($proofs['identity']['processorGid'] ?? null) !== $configuration['processorPeer']['gid']
            || ! is_int($proofs['identity']['appUid'] ?? null) || $proofs['identity']['appUid'] < 1
            || in_array($proofs['identity']['appUid'], [$configuration['gatewayUid'], $configuration['processorPeer']['uid']], true)
            || ($proofs['channel']['protocol'] ?? null) !== AuthenticatedPublicCoreChannel::SCHEMA_VERSION
            || ($proofs['channel']['socketPath'] ?? null) !== $configuration['socketPath']
            || ($proofs['egress']['allowedEndpoint'] ?? null) !== GatewayPublicCoreHttpSender::ENDPOINT
            || ! GatewayModelRequest::isDigest($proofs['egress']['policyDigest'] ?? null)
            || ! GatewayModelRequest::isDigest($proofs['backendAuthority']['coverageDigest'] ?? null)
            || ! GatewayModelRequest::isReference($proofs['backendAuthority']['strategyVersion'] ?? null)
            || ($proofs['backendAuthority']['releasePhase'] ?? null) !== 'guarded_upload') {
            throw new LogicException('runtime_not_activated');
        }

        return $configuration;
    }

    private static function protectedBytes(string $path, int $maxBytes): string
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_getegid') || ! str_starts_with($path, '/')
            || realpath($path) !== $path) {
            throw new LogicException('runtime_not_activated');
        }
        $directory = @lstat(dirname($path));
        $stat = @lstat($path);
        if (! is_array($directory) || $directory['uid'] !== 0 || $directory['gid'] !== posix_getegid()
            || ($directory['mode'] & 0027) !== 0 || ! is_array($stat) || $stat['uid'] !== 0
            || $stat['gid'] !== posix_getegid() || ($stat['mode'] & 0037) !== 0
            || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > $maxBytes) {
            throw new LogicException('runtime_not_activated');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new LogicException('runtime_not_activated');
        }
        try {
            $opened = fstat($stream);
            if (! is_array($opened) || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) {
                throw new LogicException('runtime_not_activated');
            }
            $bytes = stream_get_contents($stream, $maxBytes + 1);
            if (! is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new LogicException('runtime_not_activated');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    public static function handleAuthenticatedChannel(
        AuthenticatedPublicCoreChannel $channel,
        GatewayModelProfile $profile,
        GatewayPublicCoreHttpSender $httpSender,
        Closure $tokenCounter,
        Closure $runtimeReadiness,
    ): GatewayModelResponse {
        $frame = $channel->receive();
        if ($frame['command'] !== 'dispatch' || ! $profile->isActualProfile()) {
            throw new \LogicException('gateway_channel_unavailable');
        }
        $request = GatewayModelRequest::fromArray($frame['payload']);
        if ($frame['requestRef'] !== $request->requestRef || $frame['attemptRef'] !== $request->attemptRef
            || $frame['expiresAt'] > $request->expiresAt) {
            throw new \LogicException('receipt_changed');
        }
        $exchange = static function (string $command, string $expectedCommand, array $payload) use ($channel, $request, $frame): array {
            $channel->assertAlive();
            $channel->send($command, $request->requestRef, $request->attemptRef, $payload, $frame['expiresAt']);
            $reply = $channel->receive();
            if ($reply['command'] !== $expectedCommand || $reply['requestRef'] !== $request->requestRef
                || $reply['attemptRef'] !== $request->attemptRef || $reply['expiresAt'] !== $frame['expiresAt']) {
                throw new \LogicException('gateway_channel_unavailable');
            }

            return $reply['payload'];
        };
        $probe = ['projectionDigest' => $request->projectionDigest, 'profileFingerprint' => $request->profileFingerprint];
        $identity = static fn (): string => 'processor:'.hash('sha256', GatewayModelRequest::canonicalJson($channel->peer()));
        $validator = new GatewayPublicCoreRequestValidator;
        $transport = new self(
            profile: $profile,
            runtimeReadiness: static function () use ($channel, $runtimeReadiness, $profile): string {
                $channel->assertAlive();

                return $runtimeReadiness($profile, $channel->peer());
            },
            peerIdentity: $identity,
            expectedProcessorIdentity: $identity(),
            currentBinding: static fn (): array => $exchange('check_binding', 'binding', $probe),
            serializedFence: static function (GatewayModelRequest $packet, Closure $write) use ($exchange, $probe, $validator): GatewayModelResponse {
                $binding = $exchange('check_binding', 'binding', $probe);
                $reason = $validator->validateBinding($packet, $binding);

                return $reason === null ? $write() : GatewayModelResponse::blocked($packet, $reason);
            },
            tokenCounter: $tokenCounter,
            sender: static function (GatewayModelProfile $selected, string $bytes) use ($httpSender, $exchange, $probe, $channel, $request, $validator, $runtimeReadiness): array {
                return $httpSender->send($selected, $bytes,
                    static function () use ($exchange, $probe, $channel, $request, $selected, $validator, $runtimeReadiness): void {
                        $binding = $exchange('authorize_write', 'write_authorized', $probe);
                        $reason = $validator->validateBinding($request, $binding)
                            ?? $validator->validate($request, $selected, time());
                        $ready = $runtimeReadiness($selected, $channel->peer());
                        if ($reason !== null || $ready !== 'none') {
                            throw new \LogicException($reason ?? (in_array($ready, GatewayModelResponse::REASON_CODES, true) ? $ready : 'runtime_not_activated'));
                        }
                        $channel->assertAlive();
                    },
                    static function (int $bodyLength) use ($exchange, $request): void {
                        $uploaded = ['projectionDigest' => $request->projectionDigest, 'bodyLength' => $bodyLength];
                        $reply = $exchange('upload_complete', 'uploaded', $uploaded);
                        if (! GatewayModelRequest::hasExactKeys($reply, array_keys($uploaded))
                            || $reply['projectionDigest'] !== $uploaded['projectionDigest']
                            || $reply['bodyLength'] !== $uploaded['bodyLength']) {
                            throw new \LogicException('receipt_changed');
                        }
                    },
                );
            },
        );
        $response = $transport->send($request);
        $channel->send('result', $request->requestRef, $request->attemptRef, $response->values(), $frame['expiresAt']);

        return $response;
    }

    public function __construct(
        ?GatewayModelProfile $profile = null,
        private readonly ?Closure $runtimeReadiness = null,
        private readonly ?Closure $peerIdentity = null,
        private readonly ?string $expectedProcessorIdentity = null,
        private readonly ?Closure $currentBinding = null,
        private readonly ?Closure $serializedFence = null,
        private readonly ?Closure $tokenCounter = null,
        private readonly ?Closure $sender = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->profile = $profile ?? GatewayModelProfile::unqualified();
        $this->validator = new GatewayPublicCoreRequestValidator;
    }

    public function send(GatewayModelRequest $request): GatewayModelResponse
    {
        try {
            $readiness = $this->runtimeReadiness === null ? 'runtime_not_activated' : ($this->runtimeReadiness)($this->profile);
            if ($readiness !== 'none') {
                return GatewayModelResponse::unavailable($request,
                    in_array($readiness, GatewayModelResponse::REASON_CODES, true) ? $readiness : 'runtime_not_activated');
            }
            if (! $this->profile->isQualified()) {
                return GatewayModelResponse::unavailable($request, 'model_profile_unqualified');
            }
            if ($this->peerIdentity === null || ! GatewayModelRequest::isReference($this->expectedProcessorIdentity)) {
                return GatewayModelResponse::unavailable($request, 'gateway_identity_unavailable');
            }
            if ($this->currentBinding === null || $this->serializedFence === null) {
                return GatewayModelResponse::unavailable($request, 'receipt_unavailable');
            }
            if ($this->tokenCounter === null) {
                return GatewayModelResponse::unavailable($request, 'tokenizer_unqualified');
            }
            if ($this->sender === null) {
                return GatewayModelResponse::unavailable($request, 'gateway_not_configured');
            }
            if (isset($this->attempts[$request->attemptRef])) {
                return GatewayModelResponse::blocked($request, 'receipt_changed');
            }
            if (count($this->attempts) >= 1024) {
                return GatewayModelResponse::blocked($request, 'budget_exceeded');
            }
            $this->attempts[$request->attemptRef] = true;
            $called = false;
            $response = null;
            $fenceOpen = true;
            $isFenceOpen = static function () use (&$fenceOpen): bool {
                return $fenceOpen;
            };
            try {
                $fenced = ($this->serializedFence)($request, function () use ($request, &$called, &$response, $isFenceOpen): GatewayModelResponse {
                    if (! $isFenceOpen() || $called) {
                        return GatewayModelResponse::blocked($request, 'receipt_changed');
                    }
                    $called = true;
                    $reason = $this->currentReason($request);
                    if ($reason !== null) {
                        return $response = GatewayModelResponse::blocked($request, $reason);
                    }
                    $count = ($this->tokenCounter)($request->bodyBytes, $this->profile);
                    $reason = $this->validator->validateTokenCount($this->profile, $count);
                    if ($reason !== null) {
                        return $response = GatewayModelResponse::blocked($request, $reason);
                    }
                    $reason = $this->currentReason($request);
                    if ($reason !== null) {
                        return $response = GatewayModelResponse::blocked($request, $reason);
                    }
                    $provider = ($this->sender)($this->profile, $request->bodyBytes);
                    try {
                        if (! GatewayModelRequest::hasExactKeys($provider, ['actionBytes', 'usage'])
                            || ! is_string($provider['actionBytes'])
                            || ($provider['usage'] !== null && ! is_array($provider['usage']))) {
                            return $response = GatewayModelResponse::blocked($request, 'invalid_model_output');
                        }

                        $completed = GatewayModelResponse::completed($request, $provider['actionBytes'], $provider['usage']);
                        $reason = $this->validator->validateUsage($this->profile, $completed->usage);

                        return $response = $reason === null
                            ? $completed : GatewayModelResponse::blocked($request, $reason);
                    } catch (Throwable) {
                        return $response = GatewayModelResponse::blocked($request, 'invalid_model_output');
                    }
                });
            } finally {
                $fenceOpen = false;
            }

            return $called && $response instanceof GatewayModelResponse && $fenced === $response
                ? $response : GatewayModelResponse::unavailable($request, 'receipt_unavailable');
        } catch (Throwable $error) {
            if ($error instanceof \LogicException && in_array($error->getMessage(), GatewayModelResponse::REASON_CODES, true) && $error->getMessage() !== 'none') {
                return in_array($error->getMessage(), ['authorization_changed', 'source_changed', 'profile_changed', 'receipt_changed', 'expired', 'budget_exceeded', 'invalid_model_output'], true)
                    ? GatewayModelResponse::blocked($request, $error->getMessage())
                    : GatewayModelResponse::unavailable($request, $error->getMessage());
            }

            return GatewayModelResponse::unavailable($request, 'gateway_unavailable');
        }
    }

    private function currentReason(GatewayModelRequest $request): ?string
    {
        $readiness = ($this->runtimeReadiness)($this->profile);
        if ($readiness !== 'none') {
            return in_array($readiness, GatewayModelResponse::REASON_CODES, true) ? $readiness : 'runtime_not_activated';
        }
        $peer = ($this->peerIdentity)();
        if (! is_string($peer) || ! hash_equals($this->expectedProcessorIdentity, $peer)) {
            return 'gateway_identity_unavailable';
        }
        $now = $this->clock === null ? time() : ($this->clock)();
        if (! is_int($now)) {
            return 'expired';
        }
        $reason = $this->validator->validate($request, $this->profile, $now);
        if ($reason !== null) {
            return $reason;
        }

        return $this->validator->validateBinding($request, ($this->currentBinding)($request, $this->profile));
    }
}
