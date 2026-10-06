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
        try {
            $body = json_decode($request->bodyBytes, true, 64, JSON_THROW_ON_ERROR);
            if (! GatewayModelRequest::hasExactKeys($body, ['model', 'messages', 'stream', 'store', 'max_completion_tokens', 'response_format'])
                || $request->bodyBytes !== GatewayModelRequest::canonicalJson($body)
                || $body['model'] !== $settings['modelId'] || $body['stream'] !== false || $body['store'] !== false
                || $body['max_completion_tokens'] !== $settings['maxOutputTokens']
                || $body['response_format'] !== ['type' => 'json_object']
                || ! is_array($body['messages']) || ! array_is_list($body['messages'])
                || count($body['messages']) < 1 || count($body['messages']) > 128) {
                return 'source_changed';
            }
            foreach ($body['messages'] as $message) {
                if (! GatewayModelRequest::hasExactKeys($message, ['role', 'content'])
                    || ! in_array($message['role'], ['system', 'user', 'assistant'], true)
                    || ! is_string($message['content']) || trim($message['content']) === ''
                    || strlen($message['content']) > 65536 || preg_match('//u', $message['content']) !== 1
                    || str_contains($message['content'], "\0")) {
                    return 'source_changed';
                }
            }
        } catch (Throwable) {
            return 'source_changed';
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
