<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use LogicException;

final readonly class AssistantContextSourceBinding
{
    private function __construct(private string $snapshotHash, private array $fields)
    {
    }

    public static function canonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function detached(array $value): array
    {
        $detached = json_decode(self::canonical($value), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($detached)) {
            throw new LogicException('context_snapshot_invalid');
        }

        return $detached;
    }

    public static function references(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException('context_references_invalid');
        }
        foreach ($value as $ref) {
            if (!is_string($ref) || $ref === '' || strlen($ref) > 256) {
                throw new LogicException('context_references_invalid');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new LogicException('context_references_duplicate');
        }

        return $value;
    }

    public static function snapshotHash(array $snapshot): string
    {
        if (!AssistantModelContextProfile::hasExactKeys($snapshot, ['authorized', 'adapterRevision', 'scope', 'conversation', 'sources']) || $snapshot['authorized'] !== true || !is_string($snapshot['adapterRevision']) || $snapshot['adapterRevision'] === '') {
            throw new LogicException('context_authority_unavailable');
        }
        $scope = $snapshot['scope'];
        if (!is_array($scope) || !AssistantModelContextProfile::hasExactKeys($scope, ['actor', 'tenant', 'project', 'acl', 'consent', 'policy'])) {
            throw new LogicException('context_scope_invalid');
        }
        foreach ($scope as $version) {
            if (!is_string($version) || $version === '') {
                throw new LogicException('context_scope_invalid');
            }
        }
        $conversation = $snapshot['conversation'];
        if (!is_array($conversation) || !AssistantModelContextProfile::hasExactKeys($conversation, ['ref', 'currentRef', 'historyRefs', 'systemRefs', 'mediaRefs'])) {
            throw new LogicException('context_conversation_invalid');
        }
        self::references([$conversation['ref'], $conversation['currentRef']]);
        $all = [$conversation['currentRef']];
        foreach (['historyRefs', 'systemRefs', 'mediaRefs'] as $key) {
            $all = [...$all, ...self::references($conversation[$key])];
        }
        self::references($all);
        if (!is_array($snapshot['sources']) || $snapshot['sources'] === []) {
            throw new LogicException('context_sources_unavailable');
        }
        foreach ($snapshot['sources'] as $sourceRef => $source) {
            self::references([$sourceRef]);
            if (!is_array($source) || !AssistantModelContextProfile::hasExactKeys($source, ['version', 'creator', 'provenance', 'class', 'fields', 'scope', 'conversationRef']) || !in_array($source['class'], ['public', 'synthetic'], true) || $source['scope'] !== $scope || $source['conversationRef'] !== $conversation['ref']) {
                throw new LogicException('context_source_class_blocked');
            }
            foreach (['version', 'creator', 'provenance'] as $key) {
                if (!is_string($source[$key]) || $source[$key] === '') {
                    throw new LogicException('context_source_invalid');
                }
            }
            if (!is_array($source['fields']) || $source['fields'] === []) {
                throw new LogicException('context_source_fields_invalid');
            }
            foreach ($source['fields'] as $fieldRef => $field) {
                self::references([$fieldRef]);
                if (!is_array($field) || !AssistantModelContextProfile::hasExactKeys($field, ['hash', 'provenance', 'artifacts']) || !self::isHash($field['hash']) || !is_string($field['provenance']) || $field['provenance'] === '' || !is_array($field['artifacts']) || $field['artifacts'] === []) {
                    throw new LogicException('context_source_fields_invalid');
                }
                foreach ($field['artifacts'] as $artifactRef => $annotation) {
                    self::references([$artifactRef]);
                    if (!is_array($annotation) || !AssistantModelContextProfile::hasExactKeys($annotation, ['contentHash', 'kind', 'bindingHash', 'metadataHash']) || !in_array($annotation['kind'], AssistantSafeContextSegment::KINDS, true)) {
                        throw new LogicException('context_artifact_annotation_invalid');
                    }
                    foreach (['contentHash', 'bindingHash', 'metadataHash'] as $key) {
                        if (!self::isHash($annotation[$key])) {
                            throw new LogicException('context_artifact_annotation_invalid');
                        }
                    }
                }
            }
        }

        return hash('sha256', self::canonical($snapshot));
    }

    private static function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    public static function capture(array $snapshot, array $artifact): self
    {
        $snapshotHash = self::snapshotHash($snapshot);
        $bindings = $artifact['bindings'];
        if (!is_array($bindings) || !array_is_list($bindings) || $bindings === []) {
            throw new LogicException('context_binding_invalid');
        }
        $bindingHash = hash('sha256', self::canonical($bindings));
        $metadataHash = hash('sha256', self::canonical($artifact['metadata']));
        $fields = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding) || !AssistantModelContextProfile::hasExactKeys($binding, ['sourceRef', 'fieldRefs'])) {
                throw new LogicException('context_binding_invalid');
            }
            self::references([$binding['sourceRef']]);
            $fieldRefs = self::references($binding['fieldRefs']);
            if ($fieldRefs === [] || isset($fields[$binding['sourceRef']])) {
                throw new LogicException('context_binding_duplicate');
            }
            foreach ($fieldRefs as $fieldRef) {
                $annotation = $snapshot['sources'][$binding['sourceRef']]['fields'][$fieldRef]['artifacts'][$artifact['artifactRef']] ?? null;
                if (!is_array($annotation) || $annotation['kind'] !== $artifact['kind'] || !hash_equals($annotation['contentHash'], hash('sha256', $artifact['text'])) || !hash_equals($annotation['bindingHash'], $bindingHash) || !hash_equals($annotation['metadataHash'], $metadataHash)) {
                    throw new LogicException('context_source_artifact_mismatch');
                }
            }
            $fields[$binding['sourceRef']] = $fieldRefs;
        }

        return new self($snapshotHash, self::detached($fields));
    }

    public function matches(array $snapshot): bool
    {
        return hash_equals($this->snapshotHash, self::snapshotHash($snapshot));
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function __serialize(): array
    {
        throw new LogicException('context_binding_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('context_binding_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}