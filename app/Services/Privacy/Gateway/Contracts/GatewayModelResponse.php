<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway\Contracts;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use LogicException;

final readonly class GatewayModelResponse
{
    public const SCHEMA_VERSION = 'public-core-model-response/3';

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
        public ?string $outputItemsBytes,
        public ?string $providerResponseId,
        public ?array $usage,
        public ?string $actualModel,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (! GatewayModelRequest::hasExactKeys($value, [
            'schemaVersion', 'contractVersion', 'requestRef', 'attemptRef',
            'profileFingerprint', 'status', 'reasonCode', 'outputItemsBytes', 'providerResponseId', 'usage', 'actualModel',
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
            if ((! is_string($value['actualModel'])
                || preg_match('~\A[A-Za-z0-9_.:/-]{1,128}\z~D', $value['actualModel']) !== 1)
                || $value['reasonCode'] !== 'none' || ! is_string($value['outputItemsBytes'])
                || strlen($value['outputItemsBytes']) > 131072 || str_contains($value['outputItemsBytes'], "\0")) {
                throw new LogicException('invalid_model_output');
            }
            self::outputItems($value['outputItemsBytes']);
            if (! self::providerId($value['providerResponseId'])) {
                throw new LogicException('invalid_model_output');
            }
            if ($value['usage'] !== null && (! GatewayModelRequest::hasExactKeys($value['usage'], ['inputTokens', 'outputTokens', 'totalTokens'])
                || ! is_int($value['usage']['inputTokens']) || $value['usage']['inputTokens'] < 0
                || ! is_int($value['usage']['outputTokens']) || $value['usage']['outputTokens'] < 0
                || ! is_int($value['usage']['totalTokens'])
                || $value['usage']['totalTokens'] !== $value['usage']['inputTokens'] + $value['usage']['outputTokens'])) {
                throw new LogicException('invalid_model_output');
            }
        } elseif ($value['reasonCode'] === 'none' || $value['outputItemsBytes'] !== null || $value['providerResponseId'] !== null || $value['usage'] !== null || $value['actualModel'] !== null) {
            throw new LogicException('invalid_model_output');
        }

        return new self(
            $value['requestRef'], $value['attemptRef'], $value['profileFingerprint'],
            $value['status'], $value['reasonCode'], $value['outputItemsBytes'], $value['providerResponseId'], $value['usage'], $value['actualModel'],
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

    public static function completed(GatewayModelRequest $request, string $outputItemsBytes, string $providerResponseId, ?array $usage, string $actualModel): self
    {
        return self::result($request, 'completed', 'none', $outputItemsBytes, $usage, $actualModel, $providerResponseId);
    }

    private static function result(GatewayModelRequest $request, string $status, string $reasonCode, ?string $outputItemsBytes, ?array $usage, ?string $actualModel = null, ?string $providerResponseId = null): self
    {
        return self::fromArray([
            'schemaVersion' => self::SCHEMA_VERSION,
            'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'requestRef' => $request->requestRef,
            'attemptRef' => $request->attemptRef,
            'profileFingerprint' => $request->profileFingerprint,
            'status' => $status,
            'reasonCode' => $reasonCode,
            'outputItemsBytes' => $outputItemsBytes,
            'providerResponseId' => $providerResponseId,
            'usage' => $usage,
            'actualModel' => $actualModel,
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
            'outputItemsBytes' => $this->outputItemsBytes,
            'providerResponseId' => $this->providerResponseId,
            'usage' => $this->usage,
            'actualModel' => $this->actualModel,
        ];
    }

    /** Validate the ordered wire items without converting the provider protocol. */
    public static function outputItems(string $bytes): array
    {
        $items = GatewayModelRequest::decodeJson($bytes, 131072);
        if (! array_is_list($items) || $items === [] || count($items) > 128
            || $bytes !== GatewayModelRequest::canonicalJson($items)) {
            throw new LogicException('invalid_model_output');
        }
        $ids = [];
        $calls = 0;
        $texts = [];
        foreach ($items as $item) {
            self::outputItem($item);
            if (isset($ids[$item['id']])) {
                throw new LogicException('invalid_model_output');
            }
            $ids[$item['id']] = true;
            if ($item['type'] === 'function_call') {
                $calls++;
            } elseif ($item['type'] === 'message') {
                $texts[] = $item['content'][0]['text'];
            }
        }
        if ($calls > 1 || ($calls === 0 && count($texts) !== 1)) {
            throw new LogicException('invalid_model_output');
        }
        if ($calls === 0) {
            // Domain actions remain content; a JSON tool action is never executable.
            $value = GatewayModelRequest::decodeJson($texts[0], 32768);
            if (! in_array($value['type'] ?? null, ['plan', 'refine', 'summary', 'final'], true)) {
                throw new LogicException('invalid_model_output');
            }
            try {
                AssistantModelAction::parse($value);
            } catch (\Throwable) {
                throw new LogicException('invalid_model_output');
            }
        }

        return $items;
    }

    public static function outputItem(mixed $item): void
    {
        if (! is_array($item) || ! self::providerId($item['id'] ?? null)) {
            throw new LogicException('invalid_model_output');
        }
        switch ($item['type'] ?? null) {
            case 'message':
                if (! GatewayModelRequest::hasExactKeys($item, ['id', 'type', 'status', 'role', 'content'])
                    || $item['status'] !== 'completed' || $item['role'] !== 'assistant'
                    || ! is_array($item['content']) || ! array_is_list($item['content']) || count($item['content']) !== 1
                    || ! GatewayModelRequest::hasExactKeys($item['content'][0], ['type', 'text', 'annotations'])
                    || $item['content'][0]['type'] !== 'output_text' || $item['content'][0]['annotations'] !== []
                    || ! self::text($item['content'][0]['text'], 32768)) {
                    throw new LogicException('invalid_model_output');
                }
                break;
            case 'function_call':
                if (! GatewayModelRequest::hasExactKeys($item, ['id', 'type', 'status', 'call_id', 'name', 'arguments'])
                    || $item['status'] !== 'completed' || ! self::providerId($item['call_id'])
                    || ! is_string($item['name']) || ! is_string($item['arguments'])) {
                    throw new LogicException('invalid_model_output');
                }
                self::functionArguments($item['name'], $item['arguments']);
                break;
            case 'reasoning':
                // Only opaque stateless replay; no plaintext reasoning/summary accepted.
                if (! GatewayModelRequest::hasExactKeys($item, ['id', 'type', 'summary', 'encrypted_content'])
                    || $item['summary'] !== [] || ! is_string($item['encrypted_content'])
                    || strlen($item['encrypted_content']) > 65536
                    || preg_match('~\A[A-Za-z0-9_+/=-]+\z~D', $item['encrypted_content']) !== 1) {
                    throw new LogicException('invalid_model_output');
                }
                break;
            default:
                throw new LogicException('invalid_model_output');
        }
    }

    public static function functionArguments(string $name, string $bytes): array
    {
        $args = GatewayModelRequest::decodeJson($bytes, 4096);
        if ($name === 'material_search') {
            if (! GatewayModelRequest::hasExactKeys($args, ['query', 'limit'])
                || ! self::text($args['query'], 512) || ! is_int($args['limit'])
                || $args['limit'] < 1 || $args['limit'] > 10) {
                throw new LogicException('invalid_model_output');
            }
        } elseif ($name !== 'material_read_selected' || ! GatewayModelRequest::hasExactKeys($args, ['ref'])
            || ! self::opaqueRef($args['ref'])) {
            throw new LogicException('invalid_model_output');
        }

        return $args;
    }

    public static function providerId(mixed $value): bool
    {
        return is_string($value) && preg_match('~\A[A-Za-z0-9_.:-]{1,128}\z~D', $value) === 1;
    }

    public static function opaqueRef(mixed $value): bool
    {
        return is_string($value) && preg_match('~\Aref_[0-9a-f]{32}\z~D', $value) === 1;
    }

    public static function text(mixed $value, int $maxBytes): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maxBytes
            && preg_match('//u', $value) === 1 && ! str_contains($value, "\0");
    }

}
