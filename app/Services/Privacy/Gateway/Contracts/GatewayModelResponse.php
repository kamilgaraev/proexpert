<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway\Contracts;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use LogicException;

final readonly class GatewayModelResponse
{
    public const SCHEMA_VERSION = 'public-core-model-response/1';

    public const REASON_CODES = [
        'none', 'runtime_not_activated', 'gateway_not_configured',
        'gateway_identity_unavailable', 'gateway_channel_unavailable',
        'provider_credentials_unavailable', 'model_profile_unqualified',
        'tokenizer_unqualified', 'source_unavailable', 'receipt_unavailable',
        'authorization_changed', 'source_changed', 'profile_changed',
        'receipt_changed', 'expired', 'budget_exceeded', 'invalid_model_output',
        'gateway_unavailable',
    ];

    private function __construct(
        public string $requestRef,
        public string $attemptRef,
        public string $profileFingerprint,
        public string $status,
        public string $reasonCode,
        public ?string $actionBytes,
        public ?array $usage,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (! GatewayModelRequest::hasExactKeys($value, [
            'schemaVersion', 'contractVersion', 'requestRef', 'attemptRef',
            'profileFingerprint', 'status', 'reasonCode', 'actionBytes', 'usage',
        ]) || $value['schemaVersion'] !== self::SCHEMA_VERSION
            || $value['contractVersion'] !== GatewayModelRequest::CONTRACT_VERSION
            || ! GatewayModelRequest::isReference($value['requestRef'])
            || ! GatewayModelRequest::isReference($value['attemptRef'])
            || ! GatewayModelRequest::isDigest($value['profileFingerprint'])
            || ! in_array($value['status'], ['completed', 'blocked', 'unavailable'], true)
            || ! in_array($value['reasonCode'], self::REASON_CODES, true)) {
            throw new LogicException('invalid_model_output');
        }
        if ($value['status'] === 'completed') {
            if ($value['reasonCode'] !== 'none' || ! is_string($value['actionBytes'])
                || strlen($value['actionBytes']) > 131072 || str_contains($value['actionBytes'], "\0")) {
                throw new LogicException('invalid_model_output');
            }
            $action = AssistantModelAction::parse(json_decode($value['actionBytes'], true, 64, JSON_THROW_ON_ERROR));
            if ($value['actionBytes'] !== GatewayModelRequest::canonicalJson($action->values())) {
                throw new LogicException('invalid_model_output');
            }
            if ($value['usage'] !== null && (! GatewayModelRequest::hasExactKeys($value['usage'], ['inputTokens', 'outputTokens', 'totalTokens'])
                || ! is_int($value['usage']['inputTokens']) || $value['usage']['inputTokens'] < 0
                || ! is_int($value['usage']['outputTokens']) || $value['usage']['outputTokens'] < 0
                || ! is_int($value['usage']['totalTokens'])
                || $value['usage']['totalTokens'] !== $value['usage']['inputTokens'] + $value['usage']['outputTokens'])) {
                throw new LogicException('invalid_model_output');
            }
        } elseif ($value['reasonCode'] === 'none' || $value['actionBytes'] !== null || $value['usage'] !== null) {
            throw new LogicException('invalid_model_output');
        }

        return new self(
            $value['requestRef'], $value['attemptRef'], $value['profileFingerprint'],
            $value['status'], $value['reasonCode'], $value['actionBytes'], $value['usage'],
        );
    }

    public static function unavailable(GatewayModelRequest $request, string $reasonCode): self
    {
        return self::result($request, 'unavailable', $reasonCode, null, null);
    }

    public static function blocked(GatewayModelRequest $request, string $reasonCode): self
    {
        return self::result($request, 'blocked', $reasonCode, null, null);
    }

    public static function completed(GatewayModelRequest $request, string $actionBytes, ?array $usage): self
    {
        return self::result($request, 'completed', 'none', $actionBytes, $usage);
    }

    private static function result(GatewayModelRequest $request, string $status, string $reasonCode, ?string $actionBytes, ?array $usage): self
    {
        return self::fromArray([
            'schemaVersion' => self::SCHEMA_VERSION,
            'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'requestRef' => $request->requestRef,
            'attemptRef' => $request->attemptRef,
            'profileFingerprint' => $request->profileFingerprint,
            'status' => $status,
            'reasonCode' => $reasonCode,
            'actionBytes' => $actionBytes,
            'usage' => $usage,
        ]);
    }

    public function values(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'requestRef' => $this->requestRef,
            'attemptRef' => $this->attemptRef,
            'profileFingerprint' => $this->profileFingerprint,
            'status' => $this->status,
            'reasonCode' => $this->reasonCode,
            'actionBytes' => $this->actionBytes,
            'usage' => $this->usage,
        ];
    }
}
