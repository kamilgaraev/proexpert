<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use JsonException;

final readonly class VisionTopLevelExtensionPolicy
{
    private const MAX_FIELDS = 8;

    private const MAX_BYTES = 16_384;

    private const MAX_DEPTH = 3;

    private const RESERVED_KEYS = [
        'organization_id', 'tenant_id', 'project_id', 'session_id', 'document_id', 'page_id',
        'processing_unit_id', 'source', 'source_version', 'evidence', 'provenance', 'schema_version',
        'provider', 'requested_model', 'reported_model', 'model_version', 'usage', 'input_tokens',
        'output_tokens', 'cost', 'quota', 'prompt_contract', 'operation_context', 'correlation_id',
        'attempt_id',
    ];

    private const RESERVED_SEMANTICS = [
        'organization', 'tenant', 'project', 'session', 'document', 'page', 'processing', 'unit',
        'source', 'evidence', 'provenance', 'schema', 'provider', 'model', 'usage', 'token', 'cost',
        'quota', 'prompt', 'operation', 'correlation', 'attempt', 'security', 'authorization',
        'authentication', 'scope',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $knownKeys
     * @return array{data:array<string,mixed>,quarantine:list<array{section:string,index:int,reason:string}>}
     */
    public function sanitize(array $data, array $knownKeys): array
    {
        $extensions = array_values(array_diff(array_keys($data), $knownKeys));
        sort($extensions, SORT_STRING);
        if (count($extensions) > self::MAX_FIELDS) {
            throw new VisionContractException('analysis_extension_limit_exceeded');
        }

        $bytes = 0;
        $quarantine = [];
        foreach ($extensions as $index => $key) {
            if (preg_match('/^(?:diagnostic|extension)_[a-z0-9_]{1,48}$/D', $key) !== 1
                || in_array($key, self::RESERVED_KEYS, true)
                || $this->hasReservedSemantic($key)
                || $this->containsReservedKey($data[$key] ?? null)) {
                throw new VisionContractException('unsafe_analysis_extension');
            }
            if ($this->depth($data[$key] ?? null) > self::MAX_DEPTH) {
                throw new VisionContractException('analysis_extension_limit_exceeded');
            }
            try {
                $encodedExtension = json_encode(
                    $data[$key],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            } catch (JsonException) {
                throw new VisionContractException('unsafe_analysis_extension');
            }
            $bytes += strlen($encodedExtension);
            if ($bytes > self::MAX_BYTES) {
                throw new VisionContractException('analysis_extension_limit_exceeded');
            }
            unset($data[$key]);
            $quarantine[] = [
                'section' => 'top_level_extension',
                'index' => $index,
                'reason' => 'safe_extension_ignored',
            ];
        }

        return ['data' => $data, 'quarantine' => $quarantine];
    }

    private function containsReservedKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $nested) {
            if (is_string($key) && (in_array(mb_strtolower($key), self::RESERVED_KEYS, true)
                || $this->hasReservedSemantic($key))) {
                return true;
            }
            if ($this->containsReservedKey($nested)) {
                return true;
            }
        }

        return false;
    }

    private function hasReservedSemantic(string $key): bool
    {
        $snakeCase = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/u', '_', $key);
        $tokens = preg_split('/[^a-z0-9]+/D', mb_strtolower(is_string($snakeCase) ? $snakeCase : $key));

        return array_intersect(is_array($tokens) ? $tokens : [], self::RESERVED_SEMANTICS) !== [];
    }

    private function depth(mixed $value): int
    {
        if (! is_array($value) || $value === []) {
            return 1;
        }

        return 1 + max(array_map(fn (mixed $nested): int => $this->depth($nested), $value));
    }
}
