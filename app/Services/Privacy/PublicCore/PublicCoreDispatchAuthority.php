<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use Closure;
use Throwable;

final class PublicCoreDispatchAuthority
{
    private ?array $heldState = null;
    private ?GatewayModelRequest $heldPacket = null;
    private bool $gatewayEntered = false;

    public function __construct(
        private readonly PublicCoreReceiptStore $receipts,
        private readonly PublicCoreRuntimeReadiness $readiness,
        private readonly ?PublicCoreSessionAuthority $sessions = null,
        private readonly array $viewerBinding = [],
        private readonly ?string $requestRef = null,
        private readonly ?Closure $sourceState = null,
        private readonly ?Closure $backendFence = null,
        private readonly ?Closure $clock = null,
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
            || $this->requestRef === null || $this->sourceState === null || $this->backendFence === null) {
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
        if ($this->heldState !== null || $this->backendFence === null || $this->sessions === null || $this->requestRef !== $packet->requestRef) {
            return GatewayModelResponse::unavailable($packet, 'receipt_unavailable');
        }
        $entered = false;
        $open = true;
        $isOpen = static function () use (&$open): bool {
            return $open;
        };
        $response = null;
        try {
            $fenced = ($this->backendFence)($this->viewerBinding, $packet->binding(), function () use ($packet, $operation, &$entered, $isOpen, &$response): GatewayModelResponse {
                if (!$isOpen() || $entered) {
                    return GatewayModelResponse::blocked($packet, 'receipt_changed');
                }
                $entered = true;
                $saved = $this->receipts->withLockedState(function (array &$state) use ($packet, $operation, &$response): array {
                    $this->heldState =& $state;
                    $this->heldPacket = $packet;
                    $this->gatewayEntered = false;
                    try {
                        $reason = $this->currentReason($packet, 'prepared');
                        if ($reason !== null) {
                            $response = GatewayModelResponse::blocked($packet, $reason);
                            return $response->values();
                        }
                        $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['status'] = 'consumed';
                        if (!$this->receipts->checkpointLockedState($state)) {
                            $response = GatewayModelResponse::unavailable($packet, 'receipt_unavailable');
                            return $response->values();
                        }
                        try {
                            $reason = $this->currentReason($packet, 'consumed');
                            $value = $reason === null ? $operation($packet) : GatewayModelResponse::blocked($packet, $reason);
                            $response = $value instanceof GatewayModelResponse && $value->requestRef === $packet->requestRef
                                && $value->attemptRef === $packet->attemptRef && $value->profileFingerprint === $packet->profileFingerprint
                                ? $value : GatewayModelResponse::blocked($packet, 'invalid_model_output');
                        } catch (Throwable) {
                            $response = GatewayModelResponse::unavailable($packet, 'gateway_unavailable');
                        }
                        $state['requests'][$packet->requestRef]['dispatchAttempts'][$packet->attemptRef]['resultStatus'] = $response->status;
                        return $response->values();
                    } finally {
                        unset($this->heldState);
                        $this->heldState = null;
                        $this->heldPacket = null;
                    }
                });
                return $saved !== null && $response instanceof GatewayModelResponse && $saved === $response->values()
                    ? $response : GatewayModelResponse::unavailable($packet, 'receipt_unavailable');
            });
            return $entered && $response instanceof GatewayModelResponse && $fenced === $response
                ? $response : GatewayModelResponse::unavailable($packet, 'authorization_changed');
        } catch (Throwable) {
            return GatewayModelResponse::unavailable($packet, 'authorization_changed');
        } finally {
            $open = false;
        }
    }

    public function currentBinding(GatewayModelRequest $packet, GatewayModelProfile $profile): array
    {
        $current = $this->readiness->qualifiedProfile();
        $reason = $current === null || $current->fingerprint() !== $profile->fingerprint()
            ? 'profile_changed' : $this->currentReason($packet, 'consumed');
        return $reason === null ? $packet->binding() : ['reasonCode' => $reason];
    }

    public function withGatewayFence(GatewayModelRequest $packet, Closure $operation): GatewayModelResponse
    {
        $reason = $this->currentReason($packet, 'consumed');
        if ($reason !== null || $this->gatewayEntered) {
            return GatewayModelResponse::blocked($packet, $reason ?? 'receipt_changed');
        }
        $this->gatewayEntered = true;
        return $operation();
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
        return is_int($now) && $now > 0 ? $now : null;
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
