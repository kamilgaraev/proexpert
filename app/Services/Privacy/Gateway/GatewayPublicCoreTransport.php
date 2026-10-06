<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\Contracts\GatewayModelTransport;
use Closure;
use Throwable;

final class GatewayPublicCoreTransport implements GatewayModelTransport
{
    private readonly GatewayModelProfile $profile;

    private readonly GatewayPublicCoreRequestValidator $validator;

    private array $attempts = [];

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
        } catch (Throwable) {
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
