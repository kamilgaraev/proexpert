<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
            'public_session_ref' => null, 'reply' => null, 'sources' => [], 'trace' => [],
        ];
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
        if (!is_array($value['sources'] ?? null) || !array_is_list($value['sources'])
            || !is_array($value['trace'] ?? null) || !array_is_list($value['trace']) || count($value['trace']) > 64) {
            throw new LogicException('public_core_response_invalid');
        }
        $reply = $value['reply'] ?? null;
        if ($value['status'] === 'completed' ? !self::text($reply, 32768)
            : ($reply !== null || $value['sources'] !== [] || ($value['status'] === 'blocked' && $value['reason_code'] === 'none'))) {
            throw new LogicException('public_core_response_invalid');
        }
        $sources = [];
        foreach ($value['sources'] as $source) {
            if (!is_array($source) || !self::opaqueRef($source['ref'] ?? null) || !self::text($source['label'] ?? null)) {
                throw new LogicException('public_core_response_invalid');
            }
            $sources[] = ['ref' => $source['ref'], 'label' => $source['label']];
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

        return self::common() + [
            'status' => $value['status'], 'reason_code' => $value['reason_code'],
            'request_ref' => $value['request_ref'], 'public_session_ref' => $value['public_session_ref'],
            'reply' => $reply, 'sources' => $sources, 'trace' => $trace,
        ];
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
