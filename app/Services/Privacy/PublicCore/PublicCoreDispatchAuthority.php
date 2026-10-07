<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Closure;
use Throwable;

final class PublicCoreDispatchAuthority
{
    private ?array $heldState = null;
    private ?GatewayModelRequest $heldPacket = null;
    private bool $gatewayEntered = false;
    private string $phase = 'IDLE';
    private ?array $appGuard = null;
    private ?string $transferRef = null;
    private ?int $uploadDeadline = null;
    private bool $ledgerHeld = false;
    private bool $uploaded = false;
    private bool $releaseSent = false;
    private array $sequences = [];
    private ?int $lastMono = null;
    private ?int $lastWall = null;
    private ?array $cancelRequest = null;
    private array $seenTransfers = [];
    private ?PublicCoreProcessor $nativeProcessor = null;

    public function __construct(
        private readonly PublicCoreReceiptStore $receipts,
        private readonly PublicCoreRuntimeReadiness $readiness,
        private readonly ?PublicCoreSessionAuthority $sessions = null,
        private readonly array $viewerBinding = [],
        private readonly ?string $requestRef = null,
        private readonly ?Closure $sourceState = null,
        private readonly ?Closure $appControl = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $monotonic = null,
        private readonly ?Closure $nativeTransfer = null,
        private readonly ?Closure $uploadBounds = null,
        private readonly ?array $controlPins = null,
        private readonly ?Closure $bootstrapExpiry = null,
    ) {
    }

    public function authority(?string $contextRef): ?array
    {
        return $this->receipts->authority($contextRef);
    }

    public function projectForDispatch(array $coreInput, array $committedPrivateBinding, ?object $qualifiedGatewayProfile = null): GatewayModelRequest|array
    {
        $profile = $this->readiness->qualifiedProfile();
        if (!$qualifiedGatewayProfile instanceof GatewayModelProfile || $profile === null
            || $profile->fingerprint() !== $qualifiedGatewayProfile->fingerprint() || $this->sessions === null
            || $this->requestRef === null || $this->sourceState === null || $this->appControl === null) {
            return $this->unavailable();
        }
        try {
            if (!GatewayModelRequest::hasExactKeys($coreInput, ['schemaVersion', 'context', 'contextScope', 'tools', 'toolReferences', 'repair'])
                || $coreInput['schemaVersion'] !== 'assistant-loop-input/1' || !is_array($coreInput['context'])
                || !GatewayModelRequest::hasExactKeys($committedPrivateBinding, ['snapshot', 'profile', 'lineage', 'stored', 'artifacts', 'receipt'])) {
                return $this->unavailable('receipt_changed');
            }
            $contextRef = $coreInput['context']['contextRef'] ?? null;
            if (!is_string($contextRef)) {
                return $this->unavailable('receipt_changed');
            }
            $coreAuthority = $committedPrivateBinding;
            unset($coreAuthority['receipt']);
            $native = AssistantContextReceipt::consume(['status' => 'READY', 'mode' => 'offline-synthetic',
                'transportAllowed' => false, 'payload' => $coreInput['context']], $coreAuthority, $profile->values()['profileRef']);
            if ($native->privateBinding() !== $committedPrivateBinding || $native->contextScope() !== $coreInput['contextScope']
                || $coreAuthority['profile'] !== $this->readiness->coreProfile()) {
                return $this->unavailable('receipt_changed');
            }
            $packet = null;
            $result = $this->receipts->transaction(function (array &$state) use ($coreInput, $committedPrivateBinding, $profile, $contextRef, &$packet): array {
                $request = $this->sessions->currentRequest($state, $this->viewerBinding, $this->requestRef);
                $authority = $this->receipts->authorityFromLockedState($state, $contextRef);
                $now = $this->now();
                if ($request === null || $authority === null || $now === null || !$this->sourceCurrent($request)
                    || $authority['receipt'] !== $committedPrivateBinding['receipt']
                    || $authority['binding']['snapshotHash'] !== AssistantContextSourceBinding::snapshotHash($committedPrivateBinding['snapshot'])
                    || $authority['binding']['trustedModelProfile'] !== $this->readiness->coreProfile()
                    || count($request['dispatchAttempts'] ?? []) >= 12) {
                    return $this->unavailable('source_changed');
                }
                foreach ($authority['receipt']['sources'] as $source) {
                    if (!in_array($source['source']['class'] ?? null, ['public', 'synthetic'], true)) {
                        return $this->unavailable('source_unavailable');
                    }
                }
                foreach ($request['dispatchAttempts'] ?? [] as $previous) {
                    if ($previous['status'] === 'consumed' && ($previous['resultStatus'] ?? null) !== 'completed') {
                        return $this->unavailable('receipt_changed');
                    }
                }
                $v = $profile->values();
                $body = GatewayModelRequest::canonicalJson(['model' => $v['modelId'], 'messages' => [
                    ['role' => 'system', 'content' => 'Return one JSON action for assistant-loop-input/1. Use only supplied public context and tools. '
                        . 'Actions: plan(type,plan); tool(type,tool,arguments); refine or summary(type,ref); '
                        . 'final(type,text,claims,sourceRefs,claimScope). Use current opaque references. '
                        . 'Each claim contains value,unit,currency,sourceRefs. Copy supplied claimScope exactly.'],
                    ['role' => 'user', 'content' => AssistantContextSourceBinding::canonical($coreInput)],
                ], 'stream' => false, 'store' => false, 'max_completion_tokens' => $v['maxOutputTokens'],
                    'response_format' => ['type' => 'json_object']]);
                $packet = GatewayModelRequest::fromArray([
                    'schemaVersion' => GatewayModelRequest::SCHEMA_VERSION, 'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
                    'requestRef' => $request['requestRef'], 'attemptRef' => self::reference(), 'publicAdmissionRef' => self::reference(),
                    'contextReceiptRef' => $contextRef, 'corePayloadDigest' => $authority['receipt']['payloadDigest'],
                    'coreReceiptDigest' => $authority['expected']['receiptDigest'], 'projectionRef' => self::reference(),
                    'projectionDigest' => hash('sha256', $body), 'profileRef' => $v['profileRef'], 'profileFingerprint' => $profile->fingerprint(),
                    'purpose' => GatewayModelRequest::PURPOSE, 'expiresAt' => min($request['expiresAt'], $now + 30), 'bodyBytes' => $body,
                ]);
                $reason = (new GatewayPublicCoreRequestValidator())->validate($packet, $profile, $now);
                if ($reason !== null) {
                    $packet = null;
                    return $this->unavailable($reason);
                }
                $state['requests'][$this->requestRef]['dispatchAttempts'][$packet->attemptRef] = [
                    'status' => 'prepared', 'binding' => $packet->binding(), 'source' => ($this->sourceState)(),
                ];
                return ['status' => 'prepared'];
            });
            return ($result['status'] ?? null) === 'prepared' && $packet instanceof GatewayModelRequest
                ? $packet : $this->unavailable('receipt_unavailable');
        } catch (Throwable) {
            return $this->unavailable('receipt_changed');
        }
    }

    public function withDispatchFence(GatewayModelRequest $packet, Closure $operation): GatewayModelResponse
    {
        if ($this->phase !== 'IDLE' || $this->sessions === null || $this->requestRef !== $packet->requestRef) {
            return GatewayModelResponse::unavailable($packet, 'receipt_unavailable');
        }
        $this->heldPacket = $packet;
        $this->phase = 'PREPARED';
        $this->gatewayEntered = false;
        $this->uploaded = false;
        $this->releaseSent = false;
        $this->cancelRequest = null;
        $response = GatewayModelResponse::unavailable($packet, 'gateway_unavailable');
        try {
            $reserved = $this->receipts->transaction(function (array &$state) use ($packet): array {
                $reason = $this->reasonInState($state, $packet, 'prepared');
                if ($reason !== null) {
                    return ['reasonCode' => $reason];
                }
                $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['status'] = 'consumed';
                return ['status' => 'consumed'];
            });
            if ($reserved !== ['status' => 'consumed']) {
                return GatewayModelResponse::blocked($packet, $reserved['reasonCode'] ?? 'receipt_unavailable');
            }
            try {
                $value = $operation($packet);
                $response = $value instanceof GatewayModelResponse && $value->requestRef === $packet->requestRef
                    && $value->attemptRef === $packet->attemptRef && $value->profileFingerprint === $packet->profileFingerprint
                    ? $value : GatewayModelResponse::blocked($packet, 'invalid_model_output');
            } catch (Throwable) {
                $response = GatewayModelResponse::unavailable($packet, 'gateway_unavailable');
            }
            if ($this->appGuard !== null || $this->ledgerHeld) {
                $this->cancelUpload($packet);
            }
            if (!$this->isWaitingResponse()) {
                $response = GatewayModelResponse::blocked($packet, 'gateway_unavailable');
            }
            if ($this->ledgerHeld || $this->appGuard !== null) {
                return GatewayModelResponse::unavailable($packet, 'gateway_unavailable');
            }
            $saved = $this->receipts->transaction(function (array &$state) use ($packet, &$response): array {
                $reason = $this->reasonInState($state, $packet, 'consumed');
                if ($response->status === 'completed' && $reason !== null) {
                    $response = GatewayModelResponse::blocked($packet, $reason);
                }
                $attempt = $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef] ?? null;
                if (!is_array($attempt) || $attempt['binding'] !== $packet->binding()) {
                    return [];
                }
                $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['resultStatus'] = $response->status;
                return $response->values();
            });
            return $saved === $response->values() ? $response : GatewayModelResponse::unavailable($packet, 'receipt_unavailable');
        } finally {
            if (!$this->ledgerHeld && $this->appGuard === null) {
                $this->heldPacket = null;
                $this->phase = 'IDLE';
                $this->transferRef = null;
                $this->uploadDeadline = null;
            }
        }
    }

    public function currentBinding(GatewayModelRequest $packet, GatewayModelProfile $profile): array
    {
        if ($this->heldPacket !== $packet || !in_array($this->phase, ['PREPARED', 'GUARD_HELD', 'UPLOAD_IN_PROGRESS'], true)) {
            return ['reasonCode' => 'receipt_changed'];
        }
        $current = $this->readiness->qualifiedProfile();
        if ($current === null || $current->fingerprint() !== $profile->fingerprint()) {
            return ['reasonCode' => 'profile_changed'];
        }
        if ($this->ledgerHeld && $this->remainingUploadMs() < 1) {
            return ['reasonCode' => 'expired'];
        }
        if (!$this->ownedViewer($packet)) {
            return ['reasonCode' => 'authorization_changed'];
        }
        if ($this->ledgerHeld && $this->remainingUploadMs() < 1) {
            return ['reasonCode' => 'expired'];
        }
        $result = $this->readState($packet);
        return ($result['reasonCode'] ?? 'receipt_unavailable') === 'none' ? $packet->binding() : $result;
    }

    public function withGatewayFence(GatewayModelRequest $packet, Closure $operation): GatewayModelResponse
    {
        if ($this->gatewayEntered) {
            return GatewayModelResponse::blocked($packet, 'receipt_changed');
        }
        $this->gatewayEntered = true;
        $grant = $this->authorizeWrite($packet);
        if (isset($grant['reasonCode'])) {
            return GatewayModelResponse::blocked($packet, $grant['reasonCode']);
        }
        $this->phase = 'UPLOAD_IN_PROGRESS';
        return $operation();
    }

    public function matchesGatewayChannel(AuthenticatedPublicCoreChannel $channel): bool
    {
        try {
            return $this->validPins() && $channel->peer() === $this->controlPins['gatewayPeer']
                && $channel->channelRef() === $this->controlPins['gatewayChannelRef'];
        } catch (Throwable) {
            return false;
        }
    }

    public function uploadPending(): bool
    {
        return $this->ledgerHeld || $this->appGuard !== null;
    }

    public function withProcessorGateway(PublicCoreProcessor $processor, AuthenticatedPublicCoreChannel $channel,
        GatewayModelRequest $packet, Closure $operation): GatewayModelResponse
    {
        if ($this->heldPacket !== $packet || $this->phase !== 'PREPARED' || $this->nativeProcessor !== null
            || !$this->matchesGatewayChannel($channel)) {
            return GatewayModelResponse::unavailable($packet, 'gateway_identity_unavailable');
        }
        $this->nativeProcessor = $processor;
        try {
            return $operation();
        } finally {
            if (!$this->uploadPending()) {
                $this->nativeProcessor = null;
            }
        }
    }

    public function authorizeWrite(GatewayModelRequest $packet): array
    {
        if ($this->heldPacket !== $packet || $this->phase !== 'PREPARED' || ($this->nativeTransfer === null && $this->nativeProcessor === null)
            || $this->appControl === null || $this->uploadBounds === null || !$this->validPins()) {
            return ['reasonCode' => 'gateway_channel_unavailable'];
        }
        try {
            $native = $this->nativeState('read', $packet);
            if ($native === null || $native['event'] !== 'pending') {
                return ['reasonCode' => 'gateway_channel_unavailable'];
            }
            if (isset($this->seenTransfers[$native['transferRef']])) {
                return ['reasonCode' => 'receipt_changed'];
            }
            $this->transferRef = $native['transferRef'];
            $this->seenTransfers[$this->transferRef] = $packet->attemptRef;
            $viewer = $this->sessions?->currentViewer($this->viewerBinding);
            if ($viewer === null) {
                return ['reasonCode' => 'authorization_changed'];
            }
            $tuple = $this->appTuple($packet);
            $started = $this->mono();
            $reply = $this->appRpc('authorize_write', ['schemaVersion' => 'public-core-app-upload-acquire/1', 'binding' => $tuple],
                'write_authorized', $packet, $packet->expiresAt);
            if (($reply['schemaVersion'] ?? null) === 'public-core-app-control-denial/1') {
                return ['reasonCode' => $reply['reasonCode']];
            }
            if (!GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'binding', 'currentViewer', 'guardRef', 'coverageEvidenceRef', 'uploadTimeoutMs'])
                || $reply['schemaVersion'] !== 'public-core-app-upload-grant/1' || $reply['binding'] !== $tuple
                || !$this->validViewer($reply['currentViewer']) || $reply['currentViewer'] !== $viewer
                || !GatewayModelRequest::isReference($reply['guardRef']) || !GatewayModelRequest::isReference($reply['coverageEvidenceRef'])) {
                return ['reasonCode' => 'authorization_changed'];
            }
            $this->appGuard = $reply;
            $budget = $this->budget($packet, $reply['uploadTimeoutMs']);
            $now = $this->mono();
            if ($budget === null || $now < $started || $now - $started >= $budget || $started > PHP_INT_MAX - $budget) {
                $this->cancelUpload($packet);
                return ['reasonCode' => 'expired'];
            }
            $this->uploadDeadline = $started + $budget;
            $remaining = $this->remainingUploadMs();
            $held = $this->receipts->beginUploadScope($remaining, function (array &$state) use ($packet): array {
                $reason = $this->reasonInState($state, $packet, 'consumed');
                return $reason === null ? ['status' => 'held'] : ['reasonCode' => $reason];
            });
            if ($held !== ['status' => 'held']) {
                $this->cancelUpload($packet);
                return ['reasonCode' => 'receipt_changed'];
            }
            $this->ledgerHeld = true;
            $this->phase = 'GUARD_HELD';
            $remaining = $this->remainingUploadMs();
            if ($remaining < 1) {
                $this->cancelUpload($packet);
                return ['reasonCode' => 'expired'];
            }
            return ['schemaVersion' => 'public-core-gateway-upload-grant/1', 'binding' => $packet->binding(), 'uploadTimeoutMs' => $remaining];
        } catch (Throwable) {
            if ($this->appGuard !== null || $this->ledgerHeld) {
                $this->cancelUpload($packet);
            }
            return ['reasonCode' => 'gateway_channel_unavailable'];
        }
    }

    public function uploadComplete(GatewayModelRequest $packet): array
    {
        if ($this->heldPacket !== $packet || !in_array($this->phase, ['GUARD_HELD', 'UPLOAD_IN_PROGRESS'], true)) {
            return ['reasonCode' => 'receipt_changed'];
        }
        $event = $this->nativeState('read', $packet);
        if ($event === null || $event['event'] !== 'uploaded' || $this->remainingUploadMs() < 1) {
            return ['reasonCode' => 'gateway_channel_unavailable'];
        }
        return $this->releaseFromNativeEvent($packet, $event);
    }

    public function cancelUpload(GatewayModelRequest $packet, string $reasonCode = 'gateway_unavailable'): array
    {
        if ($this->heldPacket !== $packet || $this->appGuard === null) {
            return ['reasonCode' => 'receipt_changed'];
        }
        $request = $this->gatewayCancelRequest($packet, $reasonCode);
        if (isset($request['reasonCode']) && !isset($request['schemaVersion'])) {
            return $request;
        }
        $event = $this->nativeState('cancel', $packet);
        if ($event === null || !in_array($event['event'], ['stopped', 'uploaded'], true)) {
            $this->phase = 'STOP_UNCONFIRMED';
            return ['reasonCode' => 'gateway_unavailable'];
        }
        return $this->releaseFromNativeEvent($packet, $event);
    }

    public function gatewayCancelRequest(GatewayModelRequest $packet, string $reasonCode): array
    {
        if ($this->heldPacket !== $packet || $this->appGuard === null || $this->releaseSent
            || !in_array($reasonCode, GatewayModelResponse::REASON_CODES, true) || $reasonCode === 'none') {
            return ['reasonCode' => 'receipt_changed'];
        }
        if ($this->cancelRequest === null) {
            $this->cancelRequest = ['schemaVersion' => 'public-core-gateway-upload-cancel/1', 'binding' => $packet->binding(), 'reasonCode' => $reasonCode];
        }
        return $this->cancelRequest;
    }

    private function releaseFromNativeEvent(GatewayModelRequest $packet, array $event): array
    {
        if ($this->appGuard === null || $this->releaseSent || $event['transferRef'] !== $this->transferRef) {
            return ['reasonCode' => 'receipt_changed'];
        }
        $completionRef = $this->nativeProcessor === null ? self::reference()
            : $this->nativeProcessor->gatewayCompletionRef($this, $packet, $event);
        if (!GatewayModelRequest::isReference($completionRef)) {
            return ['reasonCode' => 'gateway_channel_unavailable'];
        }
        $this->releaseSent = true;
        $guard = $this->appGuard;
        $ledgerSaved = true;
        if ($this->ledgerHeld) {
            $saved = $this->receipts->finishUploadScope(function (array &$state) use ($packet, $completionRef, $event): array {
                $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['uploadEvent'] = [
                    'completionRef' => $completionRef, 'transferRef' => $event['transferRef'], 'event' => $event['event'],
                ];
                return ['status' => 'released'];
            });
            $this->ledgerHeld = false;
            if ($saved !== ['status' => 'released']) {
                $ledgerSaved = false;
            }
        }
        $this->phase = 'RELEASE_PENDING';
        try {
            $tuple = $this->appTuple($packet);
            $reply = $this->appRpc('upload_complete', ['schemaVersion' => 'public-core-app-upload-release/1', 'binding' => $tuple,
                'guardRef' => $guard['guardRef'], 'completionRef' => $completionRef], 'uploaded', $packet, $packet->expiresAt);
            if (!GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'binding', 'guardRef'])
                || $reply['schemaVersion'] !== 'public-core-app-upload-released/1' || $reply['binding'] !== $tuple || $reply['guardRef'] !== $guard['guardRef']) {
                $this->phase = 'RELEASE_UNCONFIRMED';
                return ['reasonCode' => 'authorization_changed'];
            }
            $this->appGuard = null;
            if (!$ledgerSaved) {
                $this->phase = 'RELEASE_UNCONFIRMED';
                return ['reasonCode' => 'receipt_unavailable'];
            }
            $this->uploaded = $event['event'] === 'uploaded';
            $this->phase = $this->uploaded ? 'WAITING_RESPONSE' : 'STOPPED';
            return ['projectionDigest' => $packet->projectionDigest, 'bodyLength' => strlen($packet->bodyBytes)];
        } catch (Throwable) {
            $this->phase = 'RELEASE_UNCONFIRMED';
            return ['reasonCode' => 'gateway_channel_unavailable'];
        }
    }

    private function readState(GatewayModelRequest $packet): array
    {
        $read = function (array &$state) use ($packet): array {
            return ['reasonCode' => $this->reasonInState($state, $packet, 'consumed') ?? 'none'];
        };
        return ($this->ledgerHeld ? $this->receipts->inspectUploadScope($read) : $this->receipts->transaction($read))
            ?? ['reasonCode' => 'receipt_unavailable'];
    }

    private function isWaitingResponse(): bool
    {
        return $this->uploaded && $this->phase === 'WAITING_RESPONSE';
    }

    private function reasonInState(array &$state, GatewayModelRequest $packet, string $status): ?string
    {
        $this->heldState =& $state;
        try {
            return $this->currentReason($packet, $status);
        } finally {
            unset($this->heldState);
            $this->heldState = null;
        }
    }

    public function bootstrapViewer(string $viewerTicketRef): ?array
    {
        if (!GatewayModelRequest::isReference($viewerTicketRef) || $this->bootstrapExpiry === null || $this->appControl === null) {
            return null;
        }
        try {
            $expiresAt = ($this->bootstrapExpiry)($viewerTicketRef);
            if (!is_int($expiresAt) || $expiresAt <= $this->now()) {
                return null;
            }
            $reply = $this->appRpc('check_binding', ['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                'viewerTicketRef' => $viewerTicketRef], 'binding', null, $expiresAt);
            return GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'viewerTicketRef', 'currentViewer'])
                && $reply['schemaVersion'] === 'public-core-app-viewer-ticket-binding/1' && $reply['viewerTicketRef'] === $viewerTicketRef
                && $this->validViewer($reply['currentViewer']) ? $reply['currentViewer'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function handleGatewayFrame(array $frame, array $verifiedPeer, GatewayModelRequest $packet): array
    {
        $cleanup = $this->nativeProcessor?->acceptsGatewayCleanupFrame($this, $packet, $frame) === true;
        if ($this->heldPacket !== $packet || !$this->validFrame($frame, $verifiedPeer, 'gateway', $packet, $packet->expiresAt, $cleanup)) {
            return ['reasonCode' => 'gateway_identity_unavailable'];
        }
        $payload = $frame['payload'];
        if ($frame['command'] === 'abort'
            && GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'binding', 'reasonCode'])
            && $payload['schemaVersion'] === 'public-core-gateway-upload-stopped/1' && $payload['binding'] === $packet->binding()
            && in_array($payload['reasonCode'], GatewayModelResponse::REASON_CODES, true) && $payload['reasonCode'] !== 'none'
            && ($this->cancelRequest === null || $payload['reasonCode'] === $this->cancelRequest['reasonCode'])) {
            $event = $this->nativeState('read', $packet);
            return $event !== null && $event['event'] === 'stopped'
                ? $this->releaseFromNativeEvent($packet, $event) : ['reasonCode' => 'gateway_unavailable'];
        }
        if (in_array($frame['command'], ['check_binding', 'authorize_write'], true)) {
            if (!GatewayModelRequest::hasExactKeys($payload, ['projectionDigest', 'profileFingerprint'])
                || $payload['projectionDigest'] !== $packet->projectionDigest || $payload['profileFingerprint'] !== $packet->profileFingerprint) {
                return ['reasonCode' => 'receipt_changed'];
            }
            if ($frame['command'] === 'authorize_write') {
                return $this->authorizeWrite($packet);
            }
            $profile = $this->readiness->qualifiedProfile();
            return $profile === null ? ['reasonCode' => 'profile_changed'] : $this->currentBinding($packet, $profile);
        }
        if ($frame['command'] === 'upload_complete' && GatewayModelRequest::hasExactKeys($payload, ['projectionDigest', 'bodyLength'])
            && $payload['projectionDigest'] === $packet->projectionDigest && $payload['bodyLength'] === strlen($packet->bodyBytes)) {
            return $this->uploadComplete($packet);
        }
        return ['reasonCode' => 'receipt_changed'];
    }

    public function handleAppCancelFrame(array $frame, array $verifiedPeer, GatewayModelRequest $packet): array
    {
        if ($this->heldPacket !== $packet || !$this->validFrame($frame, $verifiedPeer, 'app', $packet, $packet->expiresAt)
            || $frame['command'] !== 'abort' || $this->appGuard === null) {
            return ['reasonCode' => 'gateway_identity_unavailable'];
        }
        $payload = $frame['payload'];
        if (!GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'binding', 'guardRef', 'reasonCode'])
            || $payload['schemaVersion'] !== 'public-core-app-upload-cancel-request/1' || $payload['binding'] !== $this->appTuple($packet)
            || $payload['guardRef'] !== $this->appGuard['guardRef'] || !in_array($payload['reasonCode'], GatewayModelResponse::REASON_CODES, true)
            || $payload['reasonCode'] === 'none') {
            return ['reasonCode' => 'receipt_changed'];
        }
        return $this->cancelUpload($packet, $payload['reasonCode']);
    }

    private function appTuple(GatewayModelRequest $packet): array
    {
        if (!GatewayModelRequest::hasExactKeys($this->viewerBinding, ['viewerTicketRef'])
            || !GatewayModelRequest::isReference($this->viewerBinding['viewerTicketRef']) || $this->sourceState === null) {
            throw new \LogicException('authorization_changed');
        }
        $source = ($this->sourceState)();
        return ['viewerTicketRef' => $this->viewerBinding['viewerTicketRef'], 'requestRef' => $packet->requestRef,
            'attemptRef' => $packet->attemptRef, 'projectionDigest' => $packet->projectionDigest,
            'profileFingerprint' => $packet->profileFingerprint, 'registryDigest' => $source['registryDigest'],
            'manifestGenerationRef' => $source['manifestGenerationRef']];
    }

    private function ownedViewer(GatewayModelRequest $packet): bool
    {
        try {
            $tuple = $this->appTuple($packet);
            $reply = $this->appRpc('check_binding', ['schemaVersion' => 'public-core-app-viewer-check/1', 'binding' => $tuple],
                'binding', $packet, $packet->expiresAt);
            return GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'binding', 'currentViewer'])
                && $reply['schemaVersion'] === 'public-core-app-viewer-binding/1' && $reply['binding'] === $tuple
                && $this->validViewer($reply['currentViewer']) && $reply['currentViewer'] === $this->sessions?->currentViewer($this->viewerBinding);
        } catch (Throwable) {
            return false;
        }
    }

    private function appRpc(string $command, array $payload, string $replyCommand, ?GatewayModelRequest $packet, int $expiresAt): array
    {
        if ($this->appControl === null || !$this->validPins()) {
            throw new \LogicException('gateway_channel_unavailable');
        }
        $result = ($this->appControl)($command, $payload, $packet, $expiresAt);
        if (!GatewayModelRequest::hasExactKeys($result, ['frame', 'peer']) || !is_array($result['frame']) || !is_array($result['peer'])
            || !$this->validFrame($result['frame'], $result['peer'], 'app', $packet, $expiresAt)
            || $result['frame']['command'] !== $replyCommand) {
            throw new \LogicException('gateway_channel_unavailable');
        }
        $reply = $result['frame']['payload'];
        $schema = $reply['schemaVersion'] ?? null;
        if (in_array($schema, ['public-core-app-control-denial/1', 'public-core-app-viewer-ticket-denial/1'], true)) {
            $valid = $packet === null
                ? $schema === 'public-core-app-viewer-ticket-denial/1' && GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'viewerTicketRef', 'reasonCode'])
                    && $reply['viewerTicketRef'] === ($payload['viewerTicketRef'] ?? null)
                : $schema === 'public-core-app-control-denial/1' && GatewayModelRequest::hasExactKeys($reply, ['schemaVersion', 'binding', 'reasonCode'])
                    && $reply['binding'] === $this->appTuple($packet);
            if (!$valid || !in_array($reply['reasonCode'], GatewayModelResponse::REASON_CODES, true) || $reply['reasonCode'] === 'none') {
                throw new \LogicException('gateway_channel_unavailable');
            }
        }
        return $reply;
    }

    private function validFrame(array $frame, array $peer, string $role, ?GatewayModelRequest $packet, int $expiresAt, bool $cleanup = false): bool
    {
        $now = $this->now();
        if (!$this->validPins() || !GatewayModelRequest::hasExactKeys($frame,
            ['schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload'])
            || $peer !== $this->controlPins[$role . 'Peer'] || $frame['schemaVersion'] !== 'public-core-channel/1'
            || $frame['channelRef'] !== $this->controlPins[$role . 'ChannelRef'] || !is_int($frame['sequence'])
            || $frame['sequence'] !== ($this->sequences[$role] ?? 1) + 1 || !is_string($frame['command'])
            || $frame['requestRef'] !== $packet?->requestRef || $frame['attemptRef'] !== $packet?->attemptRef
            || $frame['expiresAt'] !== $expiresAt || $now === null || (!$cleanup && $expiresAt <= $now)
            || ($cleanup && ($role !== 'gateway' || $frame['command'] !== 'abort')) || !is_array($frame['payload'])) {
            return false;
        }
        $this->sequences[$role] = $frame['sequence'];
        return true;
    }

    private function validPins(): bool
    {
        if (!GatewayModelRequest::hasExactKeys($this->controlPins, ['appPeer', 'appChannelRef', 'gatewayPeer', 'gatewayChannelRef'])) {
            return false;
        }
        foreach (['app', 'gateway'] as $role) {
            $peer = $this->controlPins[$role . 'Peer'];
            if (!GatewayModelRequest::hasExactKeys($peer, ['pid', 'uid', 'gid']) || !is_int($peer['pid']) || $peer['pid'] < 1
                || !is_int($peer['uid']) || $peer['uid'] < 0 || !is_int($peer['gid']) || $peer['gid'] < 0
                || !GatewayModelRequest::isReference($this->controlPins[$role . 'ChannelRef'])) {
                return false;
            }
        }
        return $this->controlPins['appChannelRef'] !== $this->controlPins['gatewayChannelRef']
            && $this->controlPins['appPeer'] !== $this->controlPins['gatewayPeer'];
    }

    private function validViewer(mixed $viewer): bool
    {
        if (!GatewayModelRequest::hasExactKeys($viewer, ['authorized', 'viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'])
            || $viewer['authorized'] !== true) {
            return false;
        }
        foreach (['viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'] as $key) {
            if (!is_string($viewer[$key]) || $viewer[$key] === '' || strlen($viewer[$key]) > 160 || preg_match('//u', $viewer[$key]) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function nativeState(string $operation, GatewayModelRequest $packet): ?array
    {
        if (($this->nativeTransfer === null && $this->nativeProcessor === null) || !$this->validPins()) {
            return null;
        }
        try {
            $value = $this->nativeProcessor === null
                ? ($this->nativeTransfer)($operation, $packet, $operation === 'cancel' ? $this->cancelRequest : null)
                : $this->nativeProcessor->nativeGatewayTransfer($this, $operation, $packet, $operation === 'cancel' ? $this->cancelRequest : null);
            $profile = $this->readiness->qualifiedProfile();
            if (!GatewayModelRequest::hasExactKeys($value, ['qualification', 'channelRef', 'transferRef', 'requestRef', 'attemptRef', 'projectionDigest', 'event'])
                || $profile === null || ($profile->isActualProfile() && $this->nativeProcessor === null)
                || $value['qualification'] !== ($profile->isActualProfile() ? 'actual-native' : 'local-source-test')
                || $value['channelRef'] !== $this->controlPins['gatewayChannelRef'] || !GatewayModelRequest::isReference($value['transferRef'])
                || ($this->transferRef !== null && $value['transferRef'] !== $this->transferRef)
                || $value['requestRef'] !== $packet->requestRef || $value['attemptRef'] !== $packet->attemptRef
                || $value['projectionDigest'] !== $packet->projectionDigest || !in_array($value['event'], ['pending', 'uploaded', 'stopped'], true)) {
                return null;
            }
            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function budget(GatewayModelRequest $packet, mixed $remoteMs): ?int
    {
        $bounds = $this->uploadBounds === null ? null : ($this->uploadBounds)($packet);
        $now = $this->now();
        if (!GatewayModelRequest::hasExactKeys($bounds, ['predicateRemainingMs', 'loopRemainingMs']) || $now === null) {
            return null;
        }
        foreach ([$remoteMs, $bounds['predicateRemainingMs'], $bounds['loopRemainingMs']] as $value) {
            if (!is_int($value) || $value <= 0 || $value > PHP_INT_MAX - 2000) {
                return null;
            }
        }
        $seconds = $packet->expiresAt - $now;
        $expiryMs = $seconds > 2 ? 2000 : max(0, $seconds - 1) * 1000;
        return min(2000, $remoteMs, $bounds['predicateRemainingMs'], $bounds['loopRemainingMs'], $expiryMs);
    }

    private function mono(): int
    {
        $now = $this->monotonic === null ? intdiv(hrtime(true), 1000000) : ($this->monotonic)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - 2000 || ($this->lastMono !== null && $now < $this->lastMono)) {
            throw new \LogicException('expired');
        }
        $this->lastMono = $now;
        return $now;
    }

    public function remainingUploadMs(): int
    {
        return $this->uploadDeadline === null ? 0 : max(0, $this->uploadDeadline - $this->mono());
    }

    private function currentReason(GatewayModelRequest $packet, string $status): ?string
    {
        if ($this->heldState === null || $this->heldPacket !== $packet || $this->sessions === null) {
            return 'receipt_unavailable';
        }
        $request = $this->sessions->currentRequest($this->heldState, $this->viewerBinding, $packet->requestRef);
        $saved = $request['dispatchAttempts'][$packet->attemptRef] ?? null;
        if ($request === null) {
            return 'authorization_changed';
        }
        if (!is_array($saved) || $saved['status'] !== $status || $saved['binding'] !== $packet->binding()) {
            return 'receipt_changed';
        }
        foreach ($request['dispatchAttempts'] ?? [] as $attemptRef => $previous) {
            if ($attemptRef !== $packet->attemptRef && $previous['status'] === 'consumed'
                && ($previous['resultStatus'] ?? null) !== 'completed') {
                return 'receipt_changed';
            }
        }
        if (!$this->sourceCurrent($request) || $saved['source'] !== ($this->sourceState)()) {
            return 'source_changed';
        }
        $profile = $this->readiness->qualifiedProfile();
        if ($profile === null || $packet->profileFingerprint !== $profile->fingerprint()) {
            return 'profile_changed';
        }
        $authority = $this->receipts->authorityFromLockedState($this->heldState, $packet->contextReceiptRef);
        if ($authority === null || $authority['receipt']['payloadDigest'] !== $packet->corePayloadDigest
            || $authority['expected']['receiptDigest'] !== $packet->coreReceiptDigest
            || $authority['binding']['trustedModelProfile'] !== $this->readiness->coreProfile()) {
            return 'receipt_changed';
        }
        $now = $this->now();
        return $now === null ? 'expired' : (new GatewayPublicCoreRequestValidator())->validate($packet, $profile, $now);
    }

    private function sourceCurrent(array $request): bool
    {
        $source = $this->sourceState === null ? null : ($this->sourceState)();
        return GatewayModelRequest::hasExactKeys($source, ['registryDigest', 'manifestGenerationRef', 'runtimeGenerationRef'])
            && $source['registryDigest'] === $this->readiness->registryDigest()
            && $source['manifestGenerationRef'] === $request['registered']['source_generation_ref']
            && GatewayModelRequest::isReference($source['runtimeGenerationRef'])
            && ($request['runtimeGenerationRef'] ?? null) === $source['runtimeGenerationRef'];
    }

    public function dispatch(?string $contextRef, ?Closure $writer = null): array
    {
        return $this->unavailable();
    }

    private function now(): ?int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 1 || ($this->lastWall !== null && $now < $this->lastWall)) {
            return null;
        }
        $this->lastWall = $now;
        return $now;
    }

    private static function reference(): string
    {
        return 'ref_' . bin2hex(random_bytes(16));
    }

    private function unavailable(?string $reason = null): array
    {
        return ['schemaVersion' => 'public-core-authority/1', 'status' => 'unavailable',
            'reasonCode' => $reason ?? $this->readiness->resolve()['reason_code'], 'transportAllowed' => false];
    }
}
