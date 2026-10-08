<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use Throwable;

final class GatewayPublicCoreRequestValidator
{
    public function validate(GatewayModelRequest $request, GatewayModelProfile $profile, int $now): ?string
    {
        if (! $profile->isQualified()) {
            return 'model_profile_unqualified';
        }
        $settings = $profile->values();
        if ($request->profileRef !== $settings['profileRef'] || ! hash_equals($request->profileFingerprint, $profile->fingerprint())) {
            return 'profile_changed';
        }
        if ($now < 1 || $request->expiresAt <= $now) {
            return 'expired';
        }
        if (! hash_equals($request->projectionDigest, hash('sha256', $request->bodyBytes))) {
            return 'source_changed';
        }

        return $this->validateBody($profile, $request->bodyBytes);
    }

    public function validateBody(GatewayModelProfile $profile, string $bodyBytes): ?string
    {
        if (! $profile->isQualified()) {
            return 'model_profile_unqualified';
        }
        $settings = $profile->values();
        try {
            $body = GatewayModelRequest::decodeJson($bodyBytes);
            if (! GatewayModelRequest::hasExactKeys($body, ['model', 'input', 'stream', 'store', 'max_output_tokens', 'reasoning', 'parallel_tool_calls', 'tools', 'text'])
                || $bodyBytes !== GatewayModelRequest::canonicalJson($body)
                || $body['model'] !== $settings['modelId'] || $body['stream'] !== false || $body['store'] !== false
                || $body['max_output_tokens'] !== $settings['maxOutputTokens']
                || $body['reasoning'] !== ['effort' => 'none'] || $body['parallel_tool_calls'] !== false
                || $body['text'] !== ['format' => ['type' => 'json_object']]
                || GatewayModelRequest::canonicalJson($body['tools']) !== GatewayModelRequest::canonicalJson(self::tools())
                || ! is_array($body['input']) || ! array_is_list($body['input'])
                || count($body['input']) < 1 || count($body['input']) > 128) {
                return 'source_changed';
            }
            $this->validateInput($body['input']);
        } catch (Throwable) {
            return 'source_changed';
        }

        return null;
    }

    /** Exact two native functions; never a chat function wrapper. */
    public static function tools(): array
    {
        return [
            ['type' => 'function', 'name' => 'material_search', 'strict' => true,
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                ], 'required' => ['query', 'limit'], 'additionalProperties' => false]],
            ['type' => 'function', 'name' => 'material_read_selected', 'strict' => true,
                'parameters' => ['type' => 'object', 'properties' => [
                    'ref' => ['type' => 'string', 'pattern' => '^ref_[0-9a-f]{32}$'],
                ], 'required' => ['ref'], 'additionalProperties' => false]],
        ];
    }

    private function validateInput(array $items): void
    {
        $ids = [];
        $callIds = [];
        $batch = [];
        $contextCount = 0;
        $historyStarted = false;
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new \LogicException('source_changed');
            }
            if (($item['type'] ?? null) === 'message' && in_array($item['role'] ?? null, ['system', 'user'], true)) {
                if ($historyStarted || ! GatewayModelRequest::hasExactKeys($item, ['type', 'role', 'content'])
                    || ! is_array($item['content']) || ! array_is_list($item['content']) || count($item['content']) !== 1
                    || ! GatewayModelRequest::hasExactKeys($item['content'][0], ['type', 'text'])
                    || $item['content'][0]['type'] !== 'input_text'
                    || ! GatewayModelResponse::text($item['content'][0]['text'], 65536)) {
                    throw new \LogicException('source_changed');
                }
                $contextCount++;
                continue;
            }
            $historyStarted = true;
            if ($contextCount === 0) {
                throw new \LogicException('source_changed');
            }
            if (($item['type'] ?? null) === 'function_call_output') {
                if (! GatewayModelRequest::hasExactKeys($item, ['type', 'call_id', 'output'])
                    || ! GatewayModelResponse::providerId($item['call_id']) || ! is_string($item['output'])) {
                    throw new \LogicException('source_changed');
                }
                $accepted = GatewayModelResponse::outputItems(GatewayModelRequest::canonicalJson($batch));
                $calls = array_values(array_filter($accepted, static fn (array $value): bool => $value['type'] === 'function_call'));
                if (count($calls) !== 1 || $calls[0]['call_id'] !== $item['call_id'] || isset($callIds[$item['call_id']])) {
                    throw new \LogicException('source_changed');
                }
                $this->validateToolOutput($item['output'], $calls[0]['name']);
                $callIds[$item['call_id']] = true;
                $batch = [];
                continue;
            }
            GatewayModelResponse::outputItem($item);
            if (isset($ids[$item['id']])) {
                throw new \LogicException('source_changed');
            }
            $ids[$item['id']] = true;
            $batch[] = $item;
        }
        // A pending call is never sent to the provider without its checked result.
        if ($contextCount === 0) {
            throw new \LogicException('source_changed');
        }
        foreach ($batch as $item) {
            if ($item['type'] === 'function_call') {
                throw new \LogicException('source_changed');
            }
            if ($item['type'] === 'message') {
                GatewayModelResponse::outputItems(GatewayModelRequest::canonicalJson([$item]));
            }
        }
        if ($batch !== [] && $batch[count($batch) - 1]['type'] !== 'message') {
            throw new \LogicException('source_changed');
        }
        // Attempt/tenant/source ownership of replay is enforced by the committed
        // Backend authority, not inferred from structurally valid provider IDs.
    }

    private function validateToolOutput(string $bytes, string $name): void
    {
        $value = GatewayModelRequest::decodeJson($bytes, 32768);
        if ($bytes !== GatewayModelRequest::canonicalJson($value)
            || ! GatewayModelRequest::hasExactKeys($value, ['selectionRefs', 'claimScope', 'toolKind', 'status'])
            || $value['toolKind'] !== ($name === 'material_search' ? 'search' : 'read_selected')
            || ! in_array($value['status'], ['verified', 'partial', 'no_data'], true)
            || ! GatewayModelRequest::hasExactKeys($value['claimScope'], ['kind', 'scopeRef', 'sourceGenerationRef', 'unitRefs'])
            || ! in_array($value['claimScope']['kind'], ['search_subset', 'selected_entity'], true)
            || ! GatewayModelResponse::opaqueRef($value['claimScope']['scopeRef'])
            || ! GatewayModelResponse::opaqueRef($value['claimScope']['sourceGenerationRef'])) {
            throw new \LogicException('source_changed');
        }
        foreach ([$value['selectionRefs'], $value['claimScope']['unitRefs']] as $refs) {
            if (! is_array($refs) || ! array_is_list($refs) || count($refs) > 128
                || count(array_unique($refs, SORT_REGULAR)) !== count($refs)) {
                throw new \LogicException('source_changed');
            }
            foreach ($refs as $ref) {
                if (! GatewayModelResponse::opaqueRef($ref)) {
                    throw new \LogicException('source_changed');
                }
            }
        }
        if (array_diff($value['claimScope']['unitRefs'], $value['selectionRefs']) !== []) {
            throw new \LogicException('source_changed');
        }
    }

    public function validateOutput(string $bodyBytes, string $outputItemsBytes): ?string
    {
        try {
            $body = GatewayModelRequest::decodeJson($bodyBytes);
            $seenIds = []; $seenCalls = [];
            foreach ($body['input'] as $item) {
                if (isset($item['id'])) { $seenIds[$item['id']] = true; }
                if (isset($item['call_id'])) { $seenCalls[$item['call_id']] = true; }
            }
            foreach (GatewayModelResponse::outputItems($outputItemsBytes) as $item) {
                if (isset($seenIds[$item['id']]) || ($item['type'] === 'function_call' && isset($seenCalls[$item['call_id']]))) {
                    return 'invalid_model_output';
                }
            }
        } catch (Throwable) {
            return 'invalid_model_output';
        }

        return null;
    }

    public function validateBinding(GatewayModelRequest $request, mixed $binding): ?string
    {
        if (GatewayModelRequest::hasExactKeys($binding, ['reasonCode'])
            && in_array($binding['reasonCode'], GatewayModelResponse::REASON_CODES, true)
            && $binding['reasonCode'] !== 'none') {
            return $binding['reasonCode'];
        }
        $expected = $request->binding();
        if (! GatewayModelRequest::hasExactKeys($binding, array_keys($expected))) {
            return 'receipt_unavailable';
        }
        foreach ($expected as $key => $value) {
            if ($binding[$key] !== $value) {
                return match ($key) {
                    'profileRef', 'profileFingerprint' => 'profile_changed',
                    'corePayloadDigest', 'projectionRef', 'projectionDigest' => 'source_changed',
                    'expiresAt' => 'expired',
                    'publicAdmissionRef', 'requestRef', 'attemptRef', 'purpose' => 'authorization_changed',
                    default => 'receipt_changed',
                };
            }
        }

        return null;
    }

    public function validateTokenCount(GatewayModelProfile $profile, mixed $count): ?string
    {
        if (! $profile->isQualified()) {
            return 'model_profile_unqualified';
        }
        $settings = $profile->values();
        if (! GatewayModelRequest::hasExactKeys($count, ['inputTokens', 'tokenizerId', 'tokenizerRevision', 'mappingEvidenceRef'])
            || ! is_int($count['inputTokens']) || $count['inputTokens'] < 0
            || $count['tokenizerId'] !== $settings['tokenizerId']
            || $count['tokenizerRevision'] !== $settings['tokenizerRevision']
            || $count['mappingEvidenceRef'] !== $settings['mappingEvidenceRef']) {
            return 'tokenizer_unqualified';
        }
        if ($count['inputTokens'] > $profile->inputBudget()) {
            return 'budget_exceeded';
        }

        return null;
    }

    public function validateUsage(GatewayModelProfile $profile, ?array $usage): ?string
    {
        if (! $profile->isQualified()) {
            return 'model_profile_unqualified';
        }
        if ($usage === null) {
            return null;
        }
        if (! GatewayModelRequest::hasExactKeys($usage, ['inputTokens', 'outputTokens', 'totalTokens'])
            || ! is_int($usage['inputTokens']) || $usage['inputTokens'] < 0
            || ! is_int($usage['outputTokens']) || $usage['outputTokens'] < 0
            || ! is_int($usage['totalTokens'])
            || $usage['totalTokens'] !== $usage['inputTokens'] + $usage['outputTokens']) {
            return 'invalid_model_output';
        }
        if ($usage['inputTokens'] > $profile->inputBudget()
            || $usage['outputTokens'] > $profile->values()['maxOutputTokens']) {
            return 'budget_exceeded';
        }

        return null;
    }
}
