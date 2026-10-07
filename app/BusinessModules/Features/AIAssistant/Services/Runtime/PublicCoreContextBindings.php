<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextPreparationService;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResult;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopLimits;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopResponseValidator;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResultAdapter;
use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Closure;
use LogicException;

final readonly class PublicCoreContextBindings
{
    private function __construct(private string $sourceChannelRef, private string $sourcePeerRole,
        private Closure $sourceCompletionReader, private object $sourceSequence, private ?object $sourcePublicationState = null,
        private ?AuthenticatedPublicCoreChannel $nativeChannel = null, private ?string $nativeIdentityFile = null,
        private ?string $nativeIdentityDigest = null, private ?object $normalState = null)
    {
    }

    public static function sourceNormalAppPort(AuthenticatedPublicCoreChannel $channel, string $identityFile): self
    {
        static $channels;
        $channels ??= new \WeakMap();
        if (isset($channels[$channel])) { throw new LogicException('receipt_changed'); }
        $identity = self::appProcessorIdentity($identityFile, $channel);
        $normal = (object) ['active' => false, 'phase' => 'IDLE', 'violated' => false, 'lastSequence' => null, 'used' => []];
        $channels[$channel] = $normal;
        return new self($channel->channelRef(), 'Processor', static fn (): null => null,
            (object) ['next' => null, 'bootstrap' => null, 'replied' => true], null, $channel, $identityFile, $identity['digest'],
            $normal);
    }

    public function callNormalSource(string $command, array $input, int $rpcExpiresAt,
        PublicCoreBackendAuthorityFence $viewerSource, ?int $requestOriginalExpiresAt = null): array
    {
        if ($this->normalState === null || $this->nativeChannel === null) { throw new LogicException('receipt_unavailable'); }
        if ($this->normalState->active) {
            $this->normalState->violated = true;
            throw new LogicException('receipt_changed');
        }
        $this->assertNativeIdentity();
        self::normalInput($command, $input);
        if ($rpcExpiresAt <= time() || $rpcExpiresAt > $this->nativeChannel->deadlineExpiresAt()
            || ($requestOriginalExpiresAt !== null && $rpcExpiresAt > $requestOriginalExpiresAt)
            || (in_array($command, ['execute_owned', 'lookup_owned'], true) && $requestOriginalExpiresAt === null)
            || count($this->normalState->used) >= 1024) { throw new LogicException('expired'); }
        $operation = 'ref_'.bin2hex(random_bytes(16));
        $requestRef = in_array($command, ['readiness', 'open_or_resume'], true) ? $operation : $input['request_ref'];
        $peer = $this->nativeChannel->peer();
        $this->normalState->used[$operation] = true;
        $this->normalState->active = true;
        $this->normalState->phase = 'NORMAL_RUNNING';
        $this->normalState->violated = false;
        try {
            $this->nativeChannel->send($command, $requestRef, $operation,
                ['schemaVersion' => 'public-core-processor-operation/1-proposal', 'operationRef' => $operation, 'input' => $input], $rpcExpiresAt);
            while (true) {
                $frame = $this->nativeChannel->receive();
                if ($this->normalState->phase !== 'NORMAL_RUNNING' || $this->normalState->violated || $this->nativeChannel->peer() !== $peer || $rpcExpiresAt <= time()
                    || $frame['channelRef'] !== $this->sourceChannelRef || $frame['expiresAt'] !== $rpcExpiresAt
                    || ($this->normalState->lastSequence !== null && $frame['sequence'] !== $this->normalState->lastSequence + 1)) {
                    throw new LogicException('receipt_changed');
                }
                $this->normalState->lastSequence = $frame['sequence'];
                $this->assertNativeIdentity();
                if ($frame['command'] === 'check_binding') {
                    $check = $frame['payload'];
                    if ($command === 'readiness' || $frame['requestRef'] !== null || $frame['attemptRef'] !== null
                        || !GatewayModelRequest::hasExactKeys($check, ['schemaVersion', 'viewerTicketRef'])
                        || $check['schemaVersion'] !== 'public-core-app-viewer-ticket-check/1'
                        || $check['viewerTicketRef'] !== $input['viewer_ticket_ref']) { throw new LogicException('authorization_changed'); }
                    $this->normalState->phase = 'VIEWER_CHECK';
                    try { $binding = $viewerSource->viewerTicketBinding($check, $rpcExpiresAt); }
                    catch (\Throwable $failure) {
                        $binding = ['schemaVersion' => 'public-core-app-viewer-ticket-denial/1',
                            'viewerTicketRef' => $check['viewerTicketRef'],
                            'reasonCode' => $failure->getMessage() === 'expired' ? 'expired' : 'authorization_changed'];
                    }
                    if ($this->normalState->violated || $rpcExpiresAt <= time()) { throw new LogicException('receipt_changed'); }
                    $this->assertNativeIdentity();
                    $this->nativeChannel->send('binding', null, null, $binding, $rpcExpiresAt);
                    $this->normalState->phase = 'NORMAL_RUNNING';
                    continue;
                }
                if ($frame['command'] !== 'result' || $frame['requestRef'] !== $requestRef || $frame['attemptRef'] !== $operation
                    || !GatewayModelRequest::hasExactKeys($frame['payload'], ['schemaVersion', 'operationRef', 'output'])
                    || $frame['payload']['schemaVersion'] !== 'public-core-processor-operation-result/1-proposal'
                    || $frame['payload']['operationRef'] !== $operation || !is_array($frame['payload']['output'])) {
                    throw new LogicException('receipt_changed');
                }
                return self::normalOutput($command, $input, $frame['payload']['output'], $rpcExpiresAt, $requestOriginalExpiresAt);
            }
        } catch (\Throwable $failure) {
            $this->nativeChannel->close();
            throw $failure;
        } finally {
            $this->normalState->active = false;
            $this->normalState->phase = 'IDLE';
        }
    }

    private static function normalOwnedRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^ref_[a-f0-9]{32}$/D', $value) === 1;
    }

    private static function normalInput(string $command, array $input): void
    {
        if ($command === 'readiness' && $input === []) { return; }
        if (!in_array($command, ['open_or_resume', 'execute_owned', 'lookup_owned'], true)
            || !is_string($input['viewer_ticket_ref'] ?? null)
            || preg_match('/^viewer_[a-f0-9]{48}$/D', $input['viewer_ticket_ref']) !== 1) {
            throw new LogicException('authorization_changed');
        }
        if ($command !== 'open_or_resume') {
            if (!GatewayModelRequest::hasExactKeys($input, ['viewer_ticket_ref', 'request_ref'])
                || !self::normalOwnedRef($input['request_ref'])) { throw new LogicException('receipt_changed'); }
            return;
        }
        if (!GatewayModelRequest::hasExactKeys($input, ['viewer_ticket_ref', 'fixture_id', 'fixture_version', 'input_id', 'request_id', 'public_session_ref'])
            || !is_string($input['request_id']) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $input['request_id']) !== 1
            || ($input['public_session_ref'] !== null && !self::normalOwnedRef($input['public_session_ref']))) { throw new LogicException('receipt_changed'); }
        foreach (['fixture_id', 'fixture_version', 'input_id'] as $key) {
            if (!is_string($input[$key]) || strlen($input[$key]) > 128 || preg_match('~^[A-Za-z0-9._/-]+$~D', $input[$key]) !== 1) {
                throw new LogicException('source_unavailable');
            }
        }
        if (RegisteredPublicFixtureRegistry::compiled()->resolve($input['fixture_id'], $input['fixture_version'], $input['input_id']) === null) {
            throw new LogicException('source_unavailable');
        }
    }

    private static function normalOutput(string $command, array $input, array $output, int $rpcExpiresAt, ?int $originalExpiresAt): array
    {
        if (($output['status'] ?? null) === 'blocked') {
            $keys = array_key_exists('request_ref', $output) ? ['status', 'reasonCode', 'transportAllowed', 'request_ref'] : ['status', 'reasonCode', 'transportAllowed'];
            if (!GatewayModelRequest::hasExactKeys($output, $keys) || $output['transportAllowed'] !== false
                || !in_array($output['reasonCode'], GatewayModelResponse::REASON_CODES, true) || $output['reasonCode'] === 'none'
                || (isset($output['request_ref']) && (!in_array($command, ['execute_owned', 'lookup_owned'], true)
                    || $output['request_ref'] !== $input['request_ref']))) { throw new LogicException('receipt_changed'); }
            return $output;
        }
        if ($command === 'readiness') {
            $keys = ['schema_version', 'mode', 'data_scope', 'status', 'reason_code', 'source_contract_version', 'actual_model', 'model_enabled',
                'capabilities', 'free_input_enabled', 'uploads_enabled', 'actions_enabled', 'private_ready', 'fixtures'];
            if (!GatewayModelRequest::hasExactKeys($output, $keys) || $output['schema_version'] !== 'public-core-runtime-api/1'
                || $output['mode'] !== 'public_core_test' || $output['data_scope'] !== 'registered_public_fixture'
                || $output['status'] !== 'unavailable' || $output['reason_code'] !== 'runtime_not_activated'
                || $output['source_contract_version'] !== 'public-core-authority/0.7-candidate'
                || $output['actual_model'] !== null || $output['model_enabled'] !== false
                || !GatewayModelRequest::hasExactKeys($output['capabilities'], ['text', 'tools', 'vision'])
                || $output['capabilities']['text'] !== false || $output['capabilities']['tools'] !== false || $output['capabilities']['vision'] !== false
                || $output['free_input_enabled'] !== false || $output['uploads_enabled'] !== false || $output['actions_enabled'] !== false
                || $output['private_ready'] !== false || !is_array($output['fixtures'])
                || GatewayModelRequest::canonicalJson($output['fixtures']) !== GatewayModelRequest::canonicalJson(RegisteredPublicFixtureRegistry::compiled()->catalog())) {
                throw new LogicException('source_unavailable');
            }
            return $output;
        }
        if ($command === 'open_or_resume') {
            if (!GatewayModelRequest::hasExactKeys($output, ['status', 'reasonCode', 'request_ref', 'public_session_ref', 'transportAllowed', 'process_ref', 'original_expires_at'])
                || $output['status'] !== 'accepted' || $output['reasonCode'] !== 'none' || $output['transportAllowed'] !== false
                || !self::normalOwnedRef($output['request_ref']) || !self::normalOwnedRef($output['public_session_ref']) || !self::normalOwnedRef($output['process_ref'])
                || !is_int($output['original_expires_at']) || $output['original_expires_at'] < $rpcExpiresAt
                || ($originalExpiresAt !== null && $output['original_expires_at'] !== $originalExpiresAt)) { throw new LogicException('receipt_changed'); }
            return $output;
        }
        if ($command !== 'lookup_owned' || !GatewayModelRequest::hasExactKeys($output, ['status', 'reasonCode', 'request_ref', 'transportAllowed'])
            || $output['status'] !== 'accepted' || $output['reasonCode'] !== 'none' || $output['transportAllowed'] !== false
            || $output['request_ref'] !== $input['request_ref']) { throw new LogicException('receipt_changed'); }
        return $output;
    }

    public static function authenticatedAppControlPort(AuthenticatedPublicCoreChannel $channel, string $identityFile): self
    {
        $identity = self::appProcessorIdentity($identityFile, $channel);

        return new self($channel->channelRef(), 'Processor', static fn (): null => null,
            (object) ['next' => 2, 'bootstrap' => null, 'replied' => false], null, $channel, $identityFile, $identity['digest']);
    }

    public function receiveAppBootstrap(): array
    {
        $this->assertNativeIdentity();
        if ($this->normalState !== null || $this->nativeChannel === null || $this->sourceSequence->bootstrap !== null || $this->sourceSequence->replied) {
            throw new LogicException('authorization_changed');
        }
        $frame = $this->nativeChannel->receive();
        $expiresAt = $frame['expiresAt'];
        if ($expiresAt > $this->nativeChannel->deadlineExpiresAt()) { throw new LogicException('expired'); }
        $payload = self::appViewerBootstrapPayload($frame, $this->sourceChannelRef,
            $this->sourceSequence->next, $expiresAt, $this->sourcePeerRole);
        $this->assertNativeIdentity();
        $this->sourceSequence->bootstrap = $frame;
        $this->sourceSequence->next++;

        return ['payload' => $payload, 'expiresAt' => $expiresAt];
    }

    public function replyAppBootstrap(array $binding): void
    {
        $this->assertNativeIdentity();
        $frame = $this->sourceSequence->bootstrap;
        if ($this->nativeChannel === null || !is_array($frame) || $this->sourceSequence->replied
            || ($binding['viewerTicketRef'] ?? null) !== $frame['payload']['viewerTicketRef']) {
            throw new LogicException('authorization_changed');
        }
        $this->sourceSequence->replied = true;
        $this->nativeChannel->send('binding', null, null, $binding, $frame['expiresAt']);
    }

    private function assertNativeIdentity(): void
    {
        if ($this->nativeChannel === null || $this->nativeIdentityFile === null || $this->nativeIdentityDigest === null
            || self::appProcessorIdentity($this->nativeIdentityFile, $this->nativeChannel)['digest'] !== $this->nativeIdentityDigest) {
            throw new LogicException('authorization_changed');
        }
    }

    private static function appProcessorIdentity(string $path, AuthenticatedPublicCoreChannel $channel): array
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || !str_starts_with($path, '/')
            || str_contains($path, "\0") || realpath($path) !== $path) {
            throw new LogicException('gateway_identity_unavailable');
        }
        clearstatcache(true, $path);
        $stat = lstat($path);
        $parent = lstat(dirname($path));
        if (!is_array($parent) || realpath(dirname($path)) !== dirname($path) || ($parent['mode'] & 0170000) !== 0040000
            || ($parent['mode'] & 0077) !== 0 || $parent['uid'] !== posix_geteuid() || $parent['gid'] !== posix_getegid()
            || !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || $stat['uid'] !== posix_geteuid() || $stat['gid'] !== posix_getegid()
            || $stat['size'] < 2 || $stat['size'] > 4096) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new LogicException('gateway_identity_unavailable'); }
        try {
            $opened = fstat($stream);
            $bytes = stream_get_contents($stream, 4097);
            clearstatcache(true, $path);
            $current = lstat($path);
            if (!is_array($opened) || !is_array($current) || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
                || $current['dev'] !== $opened['dev'] || $current['ino'] !== $opened['ino']
                || $current['mode'] !== $stat['mode'] || $current['uid'] !== $stat['uid'] || $current['gid'] !== $stat['gid']
                || !is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new LogicException('gateway_identity_unavailable');
            }
        } finally {
            fclose($stream);
        }
        $identity = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!GatewayModelRequest::hasExactKeys($identity, ['schemaVersion', 'localRole', 'peerRole', 'localIdentityRef',
            'peerIdentityRef', 'localKernel', 'peerKernel']) || $identity['schemaVersion'] !== 'public-core-app-processor-identity/1'
            || $identity['localRole'] !== 'App' || $identity['peerRole'] !== 'Processor'
            || !GatewayModelRequest::isReference($identity['localIdentityRef']) || !GatewayModelRequest::isReference($identity['peerIdentityRef'])
            || $identity['localIdentityRef'] === $identity['peerIdentityRef']
            || !GatewayModelRequest::hasExactKeys($identity['localKernel'], ['pid', 'uid', 'gid'])
            || !GatewayModelRequest::hasExactKeys($identity['peerKernel'], ['pid', 'uid', 'gid'])
            || GatewayModelRequest::canonicalJson($identity['localKernel']) !== GatewayModelRequest::canonicalJson(
                ['pid' => getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid()])
            || GatewayModelRequest::canonicalJson($identity['peerKernel']) !== GatewayModelRequest::canonicalJson($channel->peer())
            || $channel->isCleanupOnly()) {
            throw new LogicException('gateway_identity_unavailable');
        }

        return ['digest' => hash('sha256', $bytes)];
    }

    public static function sourceAppControlPort(string $channelRef, string $peerRole, Closure $privateCompletionReader,
        int $nextSequence = 2): self
    {
        if (!PublicCoreRuntimeResource::opaqueRef($channelRef) || $nextSequence < 2) {
            throw new LogicException('authorization_changed');
        }

        return new self($channelRef, $peerRole, $privateCompletionReader, (object) ['next' => $nextSequence]);
    }

    public function sourceOnly(): bool
    {
        return $this->nativeChannel === null;
    }

    public function sourceChannel(): string
    {
        return $this->sourceChannelRef;
    }

    public function consumeSourceFrame(array $frame, string $command, array $binding, int $expiresAt): array
    {
        if ($expiresAt <= time()) { throw new LogicException('authorization_changed'); }

        return $this->consumeSourceBoundFrame($frame, $command, $binding, $expiresAt);
    }

    public function consumeSourceReleaseFrame(array $frame, array $binding, int $originalExpiresAt): array
    {
        return $this->consumeSourceBoundFrame($frame, 'upload_complete', $binding, $originalExpiresAt);
    }

    private function consumeSourceBoundFrame(array $frame, string $command, array $binding, int $expiresAt): array
    {
        $keys = ['schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload'];
        if ($this->nativeChannel !== null || $this->sourcePeerRole !== 'Processor' || count($frame) !== 8 || array_diff(array_keys($frame), $keys) !== []
            || ($frame['schemaVersion'] ?? null) !== 'public-core-channel/1'
            || ($frame['channelRef'] ?? null) !== $this->sourceChannelRef
            || ($frame['sequence'] ?? null) !== $this->sourceSequence->next || ($frame['command'] ?? null) !== $command
            || ($frame['requestRef'] ?? null) !== ($binding['requestRef'] ?? null)
            || ($frame['attemptRef'] ?? null) !== ($binding['attemptRef'] ?? null)
            || ($frame['expiresAt'] ?? null) !== $expiresAt
            || !is_array($frame['payload'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $this->sourceSequence->next++;

        return $frame['payload'];
    }

    public function sourceCompletion(string $reference): ?array
    {
        $proof = ($this->sourceCompletionReader)($reference);

        return is_array($proof) ? $proof : null;
    }

    public static function sourcePublicationPort(string $channelRef, object $privateLiveState): self
    {
        if (!PublicCoreRuntimeResource::opaqueRef($channelRef)) { throw new LogicException('receipt_unavailable'); }

        return new self($channelRef, 'Processor', static fn (): null => null, (object) ['next' => 2], $privateLiveState);
    }

    public function sourcePublicationMatches(array $guardInput, object $retainedRuntime, int $phase): bool
    {
        return in_array($phase, [1, 2], true) && $this->sourcePublicationState !== null
            && ($this->sourcePublicationState->guardInput ?? null) === $guardInput
            && ($this->sourcePublicationState->retainedRuntime ?? null) === $retainedRuntime;
    }

    public static function processorContext(GatewayModelProfile $gateway, Closure $trustedSnapshot,
        Closure $artifactProjector, Closure $tokenizer, Closure $receiptPublisher): AssistantContextPreparationService
    {
        $profile = self::coreProfile($gateway);

        return new AssistantContextPreparationService($trustedSnapshot, $artifactProjector,
            static fn (string $ref): ?array => $ref === $profile['profileRef'] ? $profile : null,
            $tokenizer, $receiptPublisher);
    }

    public static function processorTools(Closure $execute, Closure $project, Closure $privateGate): AssistantToolResultAdapter
    {
        return new AssistantToolResultAdapter($execute, $project, $privateGate);
    }

    public static function processorLoop(AssistantContextPreparationService $context, Closure $authority, Closure $tokenizer,
        Closure $modelDriver, AssistantToolResultAdapter $tools, AssistantLoopResponseValidator $validator,
        Closure $clock, Closure $finalGuard, ?AssistantLoopLimits $limits = null): AssistantLocalLoop
    {
        return new AssistantLocalLoop($context, $authority, $tokenizer, $modelDriver, $tools, $validator,
            $limits ?? new AssistantLoopLimits(), $clock, $finalGuard);
    }

    public static function processorResponseValidator(Closure $semanticValidator): AssistantLoopResponseValidator
    {
        return new AssistantLoopResponseValidator($semanticValidator, Closure::fromCallable([self::class, 'verifyDerivedCurrency']));
    }

    public static function verifyDerivedCurrency(array $claim, AssistantModelAction $action, AssistantContextReceipt $receipt,
        AssistantToolResult $latest): bool
    {
        if (PHP_INT_SIZE !== 8 || ($claim['currency'] ?? null) !== 'RUB' || !array_key_exists('unit', $claim) || $claim['unit'] !== null) {
            return false;
        }
        $value = $action->values();
        $payload = $receipt->payload();
        $private = $receipt->privateBinding();
        $current = $payload['currentRef'];
        $alias = $private['receipt']['aliases'][$current] ?? null;
        if (!is_array($alias) || ($alias['kind'] ?? null) !== 'user'
            || ($alias['artifactRef'] ?? null) !== ($private['snapshot']['conversation']['currentRef'] ?? null)) {
            return false;
        }
        $messages = array_values(array_filter($payload['messages'], static fn (array $message): bool =>
            $message['ref'] === $current && $message['role'] === 'user'));
        if (count($messages) !== 1 || !is_string($messages[0]['content'])) { return false; }
        $matched = preg_match_all('/(?<![\p{L}\p{N}.,+\-−\/])([+\-−]?[0-9][0-9.,eE+\-−\/]*)\s*(?:m3|m³|м3|м³)(?![\p{L}\p{N}])/iu',
            $messages[0]['content'], $quantities, PREG_OFFSET_CAPTURE);
        if ($matched !== 1 || preg_match('/\A[1-9][0-9]{0,6}\z/D', $quantities[1][0][0]) !== 1) { return false; }
        $before = substr($messages[0]['content'], 0, $quantities[1][0][1]);
        $after = substr($messages[0]['content'], $quantities[0][0][1] + strlen($quantities[0][0][0]));
        $number = '(?<![\p{L}\p{N}])[0-9]+(?:[.,][0-9]+)?';
        $connectors = '(?<![\p{L}\p{N}])(?:от|до|или|либо|и|более|менее|больше|меньше|свыше|минимум|максимум|or|to|from|between|least|most)(?![\p{L}\p{N}])';
        $approximation = '(?<![\p{L}\p{N}])(?:приблизительн\p{L}*|ориентировочн\p{L}*|примерн\p{L}*|прибл|около|порядка|почти|approx(?:imate(?:ly)?)?|roughly|around|about|circa|nearly|almost)(?![\p{L}\p{N}])';
        if (preg_match('/'.$approximation.'/iu', $messages[0]['content']) !== 0
            || preg_match('/(?:[\p{Sm}\p{Pd}~～⁓*\/]|'.$number.'\s*[:=^]|'.$number.'\s+|'.$connectors.'|(?<![\p{L}\p{N}])[xх](?![\p{L}\p{N}]))[\s(\[]*\z/iu', $before) !== 0
            || preg_match('/\A[\s)\]]*(?:[\p{Sm}~～⁓*\/]|[xх](?![\p{L}\p{N}])|(?:[\p{Pd}:]|(?:или|либо|и|до|or|to)(?![\p{L}\p{N}]))\s*[+\-−]?\s*[0-9])/iu', $after) !== 0) {
            return false;
        }
        $quantity = (int) $quantities[1][0][0];
        if ($quantity < 1 || $quantity > 1000000) { return false; }
        $evidence = $latest->evidence();
        $envelope = $evidence['envelope'];
        $scope = $envelope['coverage']['claimScope'] ?? null;
        if (!is_array($scope) || !in_array($scope['kind'] ?? null, ['selected_entity', 'search_subset'], true)
            || ($scope['sourceGenerationRef'] ?? null) !== $envelope['resultGenerationRef']
            || $value['claimScope'] !== $latest->modelMetadata()['claimScope'] || !is_array($scope['unitRefs'] ?? null)) {
            return false;
        }
        $sourceRefs = [];
        foreach ($private['receipt']['aliases'] as $toolAlias) {
            if ($toolAlias['artifactRef'] === $latest->artifactRef() && $toolAlias['kind'] === 'tool') {
                $sourceRefs = $toolAlias['sourceRefs'];
            }
        }
        if (!is_array($claim['sourceRefs'] ?? null) || $claim['sourceRefs'] === [] || $sourceRefs === []
            || array_diff($claim['sourceRefs'], $sourceRefs) !== [] || array_diff($value['sourceRefs'], $sourceRefs) !== []) {
            return false;
        }
        $prices = [];
        foreach ($envelope['facts'] as $fact) {
            if (($fact['kind'] ?? null) === 'price' && ($fact['currency'] ?? null) === 'RUB' && ($fact['perUnit'] ?? null) === 'm3'
                && ($fact['provenance']['sourceGenerationRef'] ?? null) === $envelope['resultGenerationRef']
                && in_array($fact['provenance']['unitRef'] ?? null, $scope['unitRefs'], true)) {
                $prices[] = $fact;
            }
        }
        if (count($prices) !== 1) { return false; }
        $unitMinor = self::integerMinorUnits($prices[0]['decimal'] ?? null);
        $totalMinor = self::integerMinorUnits($claim['value'] ?? null);
        if ($unitMinor === null || $totalMinor === null || $unitMinor > intdiv(PHP_INT_MAX, $quantity)) { return false; }

        return $unitMinor * $quantity === $totalMinor;
    }

    private static function integerMinorUnits(mixed $amount): ?int
    {
        if (!is_string($amount) || preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D', $amount) !== 1) { return null; }
        $digits = ltrim(str_replace('.', '', $amount), '0');
        if ($digits === '') { return 0; }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        return (int) $digits;
    }

    public static function appViewerBootstrapPayload(array $frame, string $channelRef, int $sequence,
        int $expiresAt, string $peerRole): array
    {
        $keys = ['schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload'];
        if ($peerRole !== 'Processor' || count($frame) !== count($keys) || array_diff(array_keys($frame), $keys) !== []
            || ($frame['schemaVersion'] ?? null) !== 'public-core-channel/1'
            || !GatewayModelRequest::isReference($channelRef) || ($frame['channelRef'] ?? null) !== $channelRef
            || $sequence < 2 || ($frame['sequence'] ?? null) !== $sequence || ($frame['command'] ?? null) !== 'check_binding'
            || !array_key_exists('requestRef', $frame) || $frame['requestRef'] !== null
            || !array_key_exists('attemptRef', $frame) || $frame['attemptRef'] !== null
            || $expiresAt <= time() || ($frame['expiresAt'] ?? null) !== $expiresAt
            || !is_array($frame['payload'] ?? null)) {
            throw new LogicException('authorization_changed');
        }
        $payload = $frame['payload'];
        if (count($payload) !== 2 || array_diff(array_keys($payload), ['schemaVersion', 'viewerTicketRef']) !== []
            || ($payload['schemaVersion'] ?? null) !== 'public-core-app-viewer-ticket-check/1'
            || !PublicCoreRuntimeResource::opaqueRef($payload['viewerTicketRef'] ?? null)) {
            throw new LogicException('authorization_changed');
        }

        return $payload;
    }

    public static function coreProfile(GatewayModelProfile $gateway): array
    {
        if (!$gateway->isQualified()) {
            throw new LogicException('model_profile_unqualified');
        }
        $values = $gateway->values();
        $profile = [
            'profileRef' => $values['profileRef'], 'qualification' => 'offline-synthetic',
            'adapterRevision' => $values['adapterRevision'], 'modelId' => $values['modelId'],
            'modelRevision' => $values['modelRevision'], 'tokenizerId' => $values['tokenizerId'],
            'tokenizerRevision' => $values['tokenizerRevision'], 'contextWindow' => $values['contextWindow'],
            'maxOutputTokens' => $values['maxOutputTokens'], 'answerReserve' => $values['answerReserve'],
            'toolReserve' => $values['toolReserve'],
        ];
        AssistantModelContextProfile::resolve($profile['profileRef'], static fn (string $ref): array => $profile);

        return $profile;
    }
}
