<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResult;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\Contracts\GatewayModelTransport;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use App\Services\Privacy\PublicCore\PublicCoreDispatchAuthority;
use App\Services\Privacy\PublicCore\PublicCoreProcessor;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Closure;
use LogicException;
use Throwable;

final class PublicCoreGatewayModelDriver
{
    private ?string $observedModel = null;
    private bool $attemptActive = false;
    private int $attemptCount = 0;
    private ?PublicCoreDispatchAuthority $lastDispatch = null;
    private ?GatewayModelRequest $lastPacket = null;

    public function __construct(private readonly GatewayModelProfile $profile,
        private readonly ?PublicCoreDispatchAuthority $dispatch = null, private readonly ?GatewayModelTransport $transport = null,
        private readonly ?Closure $privateBindingSource = null, private readonly ?PublicCoreProcessor $nativeProcessor = null,
        private readonly ?AuthenticatedPublicCoreChannel $nativeChannel = null,
        private readonly ?Closure $nativeAttemptFactory = null)
    {
    }

    public function bodyBytes(array $input): string
    {
        if (!$this->profile->isQualified()) {
            throw new LogicException('model_profile_unqualified');
        }
        return self::nativeBodyBytes($this->profile, $input);
    }

    public static function nativeBodyBytes(GatewayModelProfile $profile, array $input): string
    {
        if (!$profile->isQualified()) { throw new LogicException('model_profile_unqualified'); }
        $bytes = self::wireBodyBytes($profile->values(), $input);
        $reason = (new GatewayPublicCoreRequestValidator())->validateBody($profile, $bytes);
        if ($reason !== null) { throw new LogicException($reason); }
        return $bytes;
    }

    public static function wireBodyBytes(array $model, array $input): string
    {
        if (!GatewayModelRequest::hasExactKeys($input, ['schemaVersion', 'context', 'contextScope', 'tools', 'toolReferences', 'repair', 'nativeHistory'])
            || $input['schemaVersion'] !== 'assistant-loop-input/2' || !is_array($input['nativeHistory'])
            || !array_is_list($input['nativeHistory'])) { throw new LogicException('source_changed'); }
        $history = $input['nativeHistory'];
        unset($input['nativeHistory']);
        $message = static fn (string $role, string $text): array => ['type' => 'message', 'role' => $role,
            'content' => [['type' => 'input_text', 'text' => $text]]];
        return GatewayModelRequest::canonicalJson([
            'model' => $model['modelId'],
            'input' => [$message('system', 'Return one validated JSON action: plan(type,plan), refine or summary(type,ref), '
                .'final(type,text,claims,sourceRefs,claimScope). Each claim has value,unit,currency,sourceRefs. '
                .'Use only supplied public context and current opaque references; copy claimScope exactly. '
                .'Use native material_search or material_read_selected for tools. Answer in Russian; no plaintext reasoning.'),
                $message('user', GatewayModelRequest::canonicalJson($input)), ...$history],
            'stream' => false, 'store' => false, 'max_output_tokens' => $model['maxOutputTokens'],
            'reasoning' => ['effort' => 'none'], 'parallel_tool_calls' => false,
            'tools' => GatewayPublicCoreRequestValidator::tools(), 'text' => ['format' => ['type' => 'json_object']],
        ]);
    }

    public function action(GatewayModelRequest $request, GatewayModelResponse $response): array
    {
        $this->observedModel = null;
        $profile = $this->profile->values();
        if (!$this->profile->isQualified() || $request->profileRef !== $profile['profileRef']
            || !hash_equals($this->profile->fingerprint(), $request->profileFingerprint)
            || $response->requestRef !== $request->requestRef || $response->attemptRef !== $request->attemptRef
            || !hash_equals($request->profileFingerprint, $response->profileFingerprint)) {
            throw new LogicException('profile_changed');
        }
        if ($response->status !== 'completed' || $response->outputItemsBytes === null) {
            throw new LogicException($response->reasonCode);
        }
        if ($response->actualModel !== $profile['modelId']) {
            throw new LogicException('invalid_model_output');
        }
        $usageError = (new GatewayPublicCoreRequestValidator())->validateUsage($this->profile, $response->usage);
        if ($usageError !== null) {
            throw new LogicException($usageError);
        }

        $reason = (new GatewayPublicCoreRequestValidator())->validateOutput($request->bodyBytes, $response->outputItemsBytes);
        if ($reason !== null) { throw new LogicException($reason); }
        $items = GatewayModelResponse::outputItems($response->outputItemsBytes);
        $action = AssistantModelAction::native($items);
        $this->observedModel = $action->type() === 'final' && $this->profile->isActualProfile() ? $response->actualModel : null;
        return $items;
    }

    public function resetRun(): void
    {
        $this->observedModel = null;
        $this->lastDispatch = null;
        $this->lastPacket = null;
        $this->attemptCount = 0;
    }

    public function actualModel(): ?string
    {
        return $this->observedModel;
    }

    public function __invoke(array $input, ?AssistantToolResult $sealed = null, ?AssistantContextReceipt $receipt = null): array
    {
        $this->observedModel = null;
        if ($sealed !== null && ($receipt === null || $this->lastDispatch === null || $this->lastPacket === null
            || !$this->lastDispatch->commitNativeToolResult($this->lastPacket, $sealed, $receipt))) {
            $this->resetRun();
            throw new LogicException('receipt_changed');
        }
        if ($this->nativeAttemptFactory !== null) {
            if ($this->attemptActive || $this->attemptCount >= 12 || !$this->profile->isActualProfile()
                || $this->nativeProcessor === null || $this->privateBindingSource === null || $this->transport !== null
                || !AuthenticatedPublicCoreChannel::isNativeAvailable()) { throw new LogicException('gateway_identity_unavailable'); }
            $this->attemptActive = true;
            $this->attemptCount++;
            $channel = null;
            try {
                $attempt = ($this->nativeAttemptFactory)($this->profile);
                if (!GatewayModelRequest::hasExactKeys($attempt, ['dispatch', 'channel'])
                    || !$attempt['dispatch'] instanceof PublicCoreDispatchAuthority
                    || !$attempt['channel'] instanceof AuthenticatedPublicCoreChannel) { throw new LogicException('gateway_identity_unavailable'); }
                $channel = $attempt['channel'];
                $driver = new self($this->profile, $attempt['dispatch'], null, $this->privateBindingSource, $this->nativeProcessor, $channel);
                $action = $driver($input);
                $this->observedModel = $driver->actualModel();
                $this->lastDispatch = $driver->lastDispatch;
                $this->lastPacket = $driver->lastPacket;
                return $action;
            } finally { $channel?->close(); $this->attemptActive = false; }
        }
        if ($this->dispatch === null || $this->privateBindingSource === null) {
            throw new LogicException('receipt_unavailable');
        }
        $native = $this->nativeProcessor !== null || $this->nativeChannel !== null;
        if ($native && ($this->nativeProcessor === null || $this->nativeChannel === null || $this->transport !== null
            || !AuthenticatedPublicCoreChannel::isNativeAvailable() || !$this->dispatch->matchesGatewayChannel($this->nativeChannel))) {
            throw new LogicException('gateway_identity_unavailable');
        }
        if (!$native && ($this->transport === null || $this->profile->isActualProfile())) {
            throw new LogicException('receipt_unavailable');
        }
        $contextRef = $input['context']['contextRef'] ?? null;
        if (!GatewayModelRequest::isReference($contextRef)) { throw new LogicException('receipt_changed'); }
        try {
            $binding = ($this->privateBindingSource)($contextRef);
        } catch (Throwable $error) {
            throw new LogicException('receipt_unavailable', 0, $error);
        }
        if (!is_array($binding)) { throw new LogicException('receipt_unavailable'); }
        $packet = $this->dispatch->projectForDispatch($input, $binding, $this->profile);
        if (!$packet instanceof GatewayModelRequest) {
            $reason = $packet['reasonCode'] ?? 'receipt_unavailable';
            throw new LogicException(in_array($reason, GatewayModelResponse::REASON_CODES, true) ? $reason : 'receipt_unavailable');
        }
        $response = $native
            ? $this->nativeProcessor->dispatchGateway($this->dispatch, $this->nativeChannel, $packet)
            : $this->dispatch->withDispatchFence($packet, $this->transport->send(...));

        try {
            $items = $this->action($packet, $response);
            $this->lastPacket = $packet;
            $this->lastDispatch = $this->dispatch;
            return $items;
        } catch (Throwable $error) {
            $this->resetRun();
            throw $error;
        }
    }
}
