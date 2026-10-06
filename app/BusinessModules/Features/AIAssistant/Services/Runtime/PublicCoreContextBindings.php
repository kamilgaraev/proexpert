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
use Closure;
use LogicException;

final readonly class PublicCoreContextBindings
{
    private function __construct(private string $sourceChannelRef, private string $sourcePeerRole,
        private Closure $sourceCompletionReader, private object $sourceSequence, private ?object $sourcePublicationState = null)
    {
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
        return true;
    }

    public function sourceChannel(): string
    {
        return $this->sourceChannelRef;
    }

    public function consumeSourceFrame(array $frame, string $command, array $binding, int $expiresAt): array
    {
        $keys = ['schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload'];
        if ($this->sourcePeerRole !== 'Processor' || count($frame) !== 8 || array_diff(array_keys($frame), $keys) !== []
            || ($frame['schemaVersion'] ?? null) !== 'public-core-channel/1'
            || ($frame['channelRef'] ?? null) !== $this->sourceChannelRef
            || ($frame['sequence'] ?? null) !== $this->sourceSequence->next || ($frame['command'] ?? null) !== $command
            || ($frame['requestRef'] ?? null) !== ($binding['requestRef'] ?? null)
            || ($frame['attemptRef'] ?? null) !== ($binding['attemptRef'] ?? null)
            || $expiresAt <= time() || ($frame['expiresAt'] ?? null) !== $expiresAt
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
        $connectors = '(?<![\p{L}\p{N}])(?:от|до|или|либо|и|около|примерно|более|менее|свыше|минимум|максимум|or|to|from|between|about|approximately)';
        if (preg_match('/(?:[+\-−±<>≤≥]\s*|'.$number.'\s*[\p{Pd}×*÷\/:=^]\s*|'.$number.'\s+|'.$connectors.'\s+)\z/iu', $before) !== 0
            || preg_match('/\A\s*(?:[\p{Pd}+−±×*÷\/:=^<>≤≥]|(?:или|либо|и|до|or|to)\b)\s*[+\-−]?\s*[0-9]/iu', $after) !== 0) {
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
            || !PublicCoreRuntimeResource::opaqueRef($channelRef) || ($frame['channelRef'] ?? null) !== $channelRef
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
