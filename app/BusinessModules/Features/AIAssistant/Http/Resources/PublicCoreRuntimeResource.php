<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Responses\AdminResponse;
use LogicException;

final class PublicCoreRuntimeResource extends JsonResource
{
    public const SCHEMA = 'public-core-runtime-api/1';

    public const CONTRACT = 'public-core-runtime-api/0.1-candidate';

    public const REASONS = ['none', 'runtime_not_activated', 'gateway_not_configured',
        'gateway_identity_unavailable', 'gateway_channel_unavailable', 'provider_credentials_unavailable',
        'model_profile_unqualified', 'tokenizer_unqualified', 'source_unavailable', 'receipt_unavailable',
        'authorization_changed', 'source_changed', 'profile_changed', 'receipt_changed', 'expired',
        'budget_exceeded', 'invalid_model_output', 'gateway_unavailable'];

    public static function unavailable(string $reason = 'runtime_not_activated'): array
    {
        return self::common() + [
            'status' => 'unavailable', 'reason_code' => $reason,
            'source_contract_version' => self::CONTRACT, 'actual_model' => null, 'model_enabled' => false,
            'capabilities' => [], 'free_input_enabled' => false, 'uploads_enabled' => false,
            'actions_enabled' => false, 'private_ready' => false, 'fixtures' => [],
        ];
    }

    public static function blocked(string $reason = 'runtime_not_activated'): array
    {
        return self::common() + [
            'status' => 'blocked', 'reason_code' => $reason, 'request_ref' => null,
            'public_session_ref' => null, 'reply' => null, 'actual_model' => null, 'tools' => [], 'sources' => [], 'trace' => [],
        ];
    }

    public static function stageCompletedEnvelope(array $dto): array
    {
        $keys = ['schema_version', 'mode', 'data_scope', 'status', 'reason_code', 'request_ref',
            'public_session_ref', 'reply', 'actual_model', 'tools', 'sources', 'trace'];
        if (count($dto) !== 12 || array_diff(array_keys($dto), $keys) !== [] || ($dto['status'] ?? null) !== 'completed') {
            throw new LogicException('public_core_response_invalid');
        }
        $resource = new self($dto);
        $validated = $resource->resolve();
        if ($validated != $dto) { throw new LogicException('public_core_response_invalid'); }
        $bytes = AdminResponse::success($validated)->getContent();
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 262144) {
            throw new LogicException('public_core_response_invalid');
        }

        return ['envelopeBytes' => $bytes, 'envelopeDigest' => hash('sha256', $bytes)];
    }

    public static function stageCoreCompletedEnvelope(string $resultBytes, array $binding): array
    {
        $bindingKeys = ['schemaVersion', 'requestRef', 'sessionRef', 'processRef', 'ownerDigest', 'profileFingerprint',
            'registryDigest', 'manifestGenerationRef', 'runtimeGenerationRef', 'runtimeInstanceRef', 'resultDigest'];
        if (count($binding) !== 11 || array_diff(array_keys($binding), $bindingKeys) !== []
            || ($binding['schemaVersion'] ?? null) !== 'public-core-result-binding/1'
            || !self::opaqueRef($binding['requestRef'] ?? null) || !self::opaqueRef($binding['sessionRef'] ?? null)
            || !is_string($binding['resultDigest'] ?? null) || !hash_equals($binding['resultDigest'], hash('sha256', $resultBytes))
            || $resultBytes === '' || strlen($resultBytes) > 131072) {
            throw new LogicException('public_core_response_invalid');
        }
        $core = json_decode($resultBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($core) || array_keys($core) !== ['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed', 'actual_model', 'tools', 'sources']
            || $core['status'] !== 'completed' || $core['reasonCode'] !== 'none' || $core['request_ref'] !== $binding['requestRef']
            || $core['transportAllowed'] !== false || !self::text($core['reply'], 32768) || trim($core['reply']) === ''
            || strlen($core['reply']) > 32768 || !is_array($core['trace']) || !array_is_list($core['trace']) || count($core['trace']) > 64
            || json_encode($core, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== $resultBytes) {
            throw new LogicException('public_core_response_invalid');
        }
        foreach ($core['trace'] as $event) {
            if (!is_array($event) || count($event) !== 4 || array_diff(array_keys($event), ['action', 'step', 'tokens', 'callRef']) !== []
                || !in_array($event['action'] ?? null, ['plan', 'tool', 'refine', 'summary', 'final', 'repair', 'ready', 'blocked'], true)
                || !is_int($event['step'] ?? null) || $event['step'] < 0 || !is_int($event['tokens'] ?? null) || $event['tokens'] < 0
                || !array_key_exists('callRef', $event) || ($event['callRef'] !== null
                    && (!is_string($event['callRef']) || preg_match('/\Aref_[a-f0-9]{32}\z/D', $event['callRef']) !== 1))) {
                throw new LogicException('public_core_response_invalid');
            }
        }
        $dto = self::common() + ['status' => 'completed', 'reason_code' => 'none', 'request_ref' => $binding['requestRef'],
            'public_session_ref' => $binding['sessionRef'], 'reply' => $core['reply'], 'actual_model' => $core['actual_model'],
            'tools' => $core['tools'], 'sources' => $core['sources'], 'trace' => $core['trace']];
        $envelope = self::stageCompletedEnvelope($dto);

        return ['binding' => $binding, 'resultDigest' => $binding['resultDigest'],
            'envelopeDigest' => $envelope['envelopeDigest'], 'bodyBytes' => $envelope['envelopeBytes']];
    }

    public static function committedEnvelopeResponse(string $bytes, string $expectedDigest): JsonResponse
    {
        if ($bytes === '' || strlen($bytes) > 262144 || preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
            || !hash_equals($expectedDigest, hash('sha256', $bytes))) {
            throw new LogicException('public_core_response_invalid');
        }

        return JsonResponse::fromJsonString($bytes, 200);
    }

    public function toArray(Request $request): array
    {
        $value = $this->resource;
        if (!is_array($value) || ($value['schema_version'] ?? null) !== self::SCHEMA
            || ($value['mode'] ?? null) !== 'public_core_test'
            || ($value['data_scope'] ?? null) !== 'registered_public_fixture'
            || !in_array($value['reason_code'] ?? null, self::REASONS, true)) {
            throw new LogicException('public_core_response_invalid');
        }
        if (in_array($value['status'] ?? null, ['unavailable', 'ready'], true)) {
            return $this->readiness($value);
        }
        if (!in_array($value['status'] ?? null, ['accepted', 'running', 'completed', 'blocked'], true)) {
            throw new LogicException('public_core_response_invalid');
        }
        foreach (['request_ref', 'public_session_ref'] as $key) {
            if (!array_key_exists($key, $value) || ($value[$key] !== null && !self::opaqueRef($value[$key]))) {
                throw new LogicException('public_core_response_invalid');
            }
        }
        if ($value['status'] !== 'blocked' && ($value['request_ref'] === null
            || $value['public_session_ref'] === null || $value['reason_code'] !== 'none')) {
            throw new LogicException('public_core_response_invalid');
        }
        if (!array_key_exists('actual_model', $value) || ($value['actual_model'] !== null && !self::modelIdentity($value['actual_model']))
            || !is_array($value['tools'] ?? null) || !array_is_list($value['tools']) || count($value['tools']) > 64
            || !is_array($value['sources'] ?? null) || !array_is_list($value['sources']) || count($value['sources']) > 64
            || !is_array($value['trace'] ?? null) || !array_is_list($value['trace']) || count($value['trace']) > 64) {
            throw new LogicException('public_core_response_invalid');
        }
        $reply = $value['reply'] ?? null;
        if ($value['status'] === 'completed' ? !self::text($reply, 32768)
            : ($reply !== null || $value['actual_model'] !== null || $value['tools'] !== [] || $value['sources'] !== [] || ($value['status'] === 'blocked' && $value['reason_code'] === 'none'))) {
            throw new LogicException('public_core_response_invalid');
        }
        $trace = [];
        foreach ($value['trace'] as $event) {
            if (!is_array($event) || !in_array($event['action'] ?? null,
                ['plan', 'tool', 'refine', 'summary', 'final', 'repair', 'ready', 'blocked'], true)
                || !is_int($event['step'] ?? null) || $event['step'] < 0 || !is_int($event['tokens'] ?? null)
                || $event['tokens'] < 0 || !array_key_exists('callRef', $event)
                || ($event['callRef'] !== null && !self::opaqueRef($event['callRef']))) {
                throw new LogicException('public_core_response_invalid');
            }
            $trace[] = array_intersect_key($event, array_flip(['action', 'step', 'tokens', 'callRef']));
        }

        $evidence = self::completedEvidence($value);

        return self::common() + [
            'status' => $value['status'], 'reason_code' => $value['reason_code'],
            'request_ref' => $value['request_ref'], 'public_session_ref' => $value['public_session_ref'],
            'reply' => $reply, 'actual_model' => $evidence['actual_model'], 'tools' => $evidence['tools'],
            'sources' => $evidence['sources'], 'trace' => $trace,
        ];
    }

    public static function completedEvidence(array $value): array
    {
        if (!array_key_exists('actual_model', $value) || ($value['actual_model'] !== null && !self::modelIdentity($value['actual_model']))
            || !is_array($value['tools'] ?? null) || !array_is_list($value['tools']) || count($value['tools']) > 64
            || !is_array($value['sources'] ?? null) || !array_is_list($value['sources']) || count($value['sources']) > 64
            || !is_array($value['trace'] ?? null) || !array_is_list($value['trace']) || count($value['trace']) > 64) {
            throw new LogicException('public_core_response_invalid');
        }
        $trace = $value['trace'];
        foreach ($trace as $event) {
            if (!is_array($event) || !is_string($event['action'] ?? null) || !array_key_exists('callRef', $event)) {
                throw new LogicException('public_core_response_invalid');
            }
        }
        $sources = [];
        $sourceRefs = [];
        foreach ($value['sources'] as $source) {
            if (!is_array($source) || count($source) !== 2 || array_diff(array_keys($source), ['ref', 'label']) !== []
                || !self::opaqueRef($source['ref'] ?? null) || !self::text($source['label'] ?? null)
                || in_array($source['ref'], $sourceRefs, true)) {
                throw new LogicException('public_core_response_invalid');
            }
            $sourceRefs[] = $source['ref'];
            $sources[] = ['ref' => $source['ref'], 'label' => $source['label']];
        }
        $tools = [];
        $callRefs = [];
        foreach ($value['tools'] as $tool) {
            if (!is_array($tool) || count($tool) !== 2 || array_diff(array_keys($tool), ['label', 'call_ref']) !== []
                || !self::text($tool['label'] ?? null) || !self::opaqueRef($tool['call_ref'] ?? null)
                || in_array($tool['call_ref'], $callRefs, true)
                || !in_array($tool['call_ref'], array_column(array_filter($trace,
                    static fn (array $event): bool => $event['action'] === 'tool'), 'callRef'), true)) {
                throw new LogicException('public_core_response_invalid');
            }
            $callRefs[] = $tool['call_ref'];
            $tools[] = ['label' => $tool['label'], 'call_ref' => $tool['call_ref']];
        }

        return ['actual_model' => $value['actual_model'], 'tools' => $tools, 'sources' => $sources];
    }

    public static function opaqueRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{20,160}\z/D', $value) === 1;
    }

    private function readiness(array $value): array
    {
        if (!self::text($value['source_contract_version'] ?? null) || !array_key_exists('actual_model', $value)
            || ($value['actual_model'] !== null && !self::text($value['actual_model']))
            || !is_bool($value['model_enabled'] ?? null) || !is_array($value['capabilities'] ?? null)
            || !array_is_list($value['capabilities']) || !is_array($value['fixtures'] ?? null)
            || !array_is_list($value['fixtures'])) {
            throw new LogicException('public_core_response_invalid');
        }
        foreach (['free_input_enabled', 'uploads_enabled', 'actions_enabled', 'private_ready'] as $key) {
            if (($value[$key] ?? null) !== false) {
                throw new LogicException('public_core_response_invalid');
            }
        }
        if ($value['status'] === 'ready' ? (!$value['model_enabled'] || $value['actual_model'] === null
            || $value['reason_code'] !== 'none' || !in_array('text', $value['capabilities'], true))
            : ($value['model_enabled'] || $value['reason_code'] === 'none')) {
            throw new LogicException('public_core_response_invalid');
        }
        foreach ($value['capabilities'] as $capability) {
            if (!in_array($capability, ['text', 'material_search', 'selected_media_metadata', 'history_recall', 'topic_switch'], true)) {
                throw new LogicException('public_core_response_invalid');
            }
        }
        $fixtures = [];
        $seen = [];
        foreach ($value['fixtures'] as $fixture) {
            if (!is_array($fixture) || !self::selector($fixture['fixture_id'] ?? null)
                || !self::selector($fixture['fixture_version'] ?? null) || !self::text($fixture['label'] ?? null)
                || !is_array($fixture['inputs'] ?? null) || !array_is_list($fixture['inputs'])
                || in_array($fixture['fixture_id'], $seen, true)) {
                throw new LogicException('public_core_response_invalid');
            }
            $seen[] = $fixture['fixture_id'];
            $inputs = [];
            $inputIds = [];
            foreach ($fixture['inputs'] as $input) {
                if (!is_array($input) || !self::selector($input['input_id'] ?? null)
                    || !self::text($input['label'] ?? null) || in_array($input['input_id'], $inputIds, true)) {
                    throw new LogicException('public_core_response_invalid');
                }
                $inputIds[] = $input['input_id'];
                $inputs[] = ['input_id' => $input['input_id'], 'label' => $input['label']];
            }
            $fixtures[] = ['fixture_id' => $fixture['fixture_id'], 'fixture_version' => $fixture['fixture_version'],
                'label' => $fixture['label'], 'inputs' => $inputs];
        }

        return self::common() + [
            'status' => $value['status'], 'reason_code' => $value['reason_code'],
            'source_contract_version' => $value['source_contract_version'], 'actual_model' => $value['actual_model'],
            'model_enabled' => $value['model_enabled'], 'capabilities' => $value['capabilities'],
            'free_input_enabled' => false, 'uploads_enabled' => false, 'actions_enabled' => false,
            'private_ready' => false, 'fixtures' => $fixtures,
        ];
    }

    private static function modelIdentity(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_.:\/-]{1,128}\z/D', $value) === 1;
    }

    private static function selector(mixed $value): bool
    {
        return self::text($value, 128) && preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $value) === 1;
    }

    private static function text(mixed $value, int $maximum = 200): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('//u', $value) === 1 && !str_contains($value, "\0");
    }

    private static function common(): array
    {
        return ['schema_version' => self::SCHEMA, 'mode' => 'public_core_test', 'data_scope' => 'registered_public_fixture'];
    }
}
