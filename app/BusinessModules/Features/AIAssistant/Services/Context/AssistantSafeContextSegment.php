<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;

final readonly class AssistantSafeContextSegment
{
    public const KINDS = ['system', 'user', 'assistant', 'tool', 'summary', 'frame', 'media', 'transcript', 'topic', 'entity', 'filter'];

    private function __construct(
        private string $artifactRef,
        private string $kind,
        private string $text,
        private array $metadata,
        private AssistantContextSourceBinding $binding,
        private string $opaqueRef,
    ) {
    }

    public static function project(string $artifactRef, array $snapshot, Closure $trustedProjector): self
    {
        $artifact = $trustedProjector($artifactRef, $snapshot);
        if (!is_array($artifact) || !AssistantModelContextProfile::hasExactKeys($artifact, ['artifactRef', 'adapterRevision', 'kind', 'text', 'bindings', 'metadata']) || $artifact['artifactRef'] !== $artifactRef || $artifact['adapterRevision'] !== $snapshot['adapterRevision'] || !in_array($artifact['kind'], self::KINDS, true) || !is_string($artifact['text']) || preg_match('//u', $artifact['text']) !== 1 || str_contains($artifact['text'], "\0") || !is_array($artifact['metadata'])) {
            throw new LogicException('context_projection_unavailable');
        }
        if (($artifact['kind'] === 'media' && $artifact['text'] !== '') || ($artifact['kind'] !== 'media' && $artifact['text'] === '')) {
            throw new LogicException('context_projection_text_invalid');
        }
        $metadata = $artifact['metadata'];
        $keys = match ($artifact['kind']) {
            'summary' => ['coveredArtifactRefs'],
            'frame' => ['topicRef', 'entityRefs', 'filterRefs', 'mediaRefs', 'transcriptRefs', 'coveredArtifactRefs'],
            'media' => ['mediaRef', 'transcriptRef'],
            'transcript' => ['mediaRef'],
            default => [],
        };
        if (!AssistantModelContextProfile::hasExactKeys($metadata, $keys)) {
            throw new LogicException('context_projection_metadata_invalid');
        }
        foreach ($metadata as $key => $value) {
            AssistantContextSourceBinding::references(str_ends_with($key, 'Refs') ? $value : [$value]);
        }
        if ($artifact['kind'] === 'media' && $metadata['mediaRef'] !== $artifactRef) {
            throw new LogicException('context_media_reference_mismatch');
        }

        return new self($artifactRef, $artifact['kind'], $artifact['text'], AssistantContextSourceBinding::detached($metadata), AssistantContextSourceBinding::capture($snapshot, $artifact), 'ref_' . bin2hex(random_bytes(16)));
    }

    public function artifactRef(): string
    {
        return $this->artifactRef;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function opaqueRef(): string
    {
        return $this->opaqueRef;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function fields(): array
    {
        return $this->binding->fields();
    }

    public function revalidate(array $snapshot): void
    {
        if (!$this->binding->matches($snapshot)) {
            throw new LogicException('context_snapshot_changed');
        }
    }

    public function modelMessage(array $opaqueSources): array
    {
        $sourceRefs = [];
        foreach (array_keys($this->fields()) as $sourceRef) {
            if (!isset($opaqueSources[$sourceRef])) {
                throw new LogicException('context_opaque_source_unavailable');
            }
            $sourceRefs[] = $opaqueSources[$sourceRef];
        }

        return [
            'role' => in_array($this->kind, ['system', 'user', 'assistant', 'tool'], true) ? $this->kind : 'user',
            'content' => $this->text,
            'ref' => $this->opaqueRef,
            'sourceRefs' => $sourceRefs,
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('context_segment_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('context_segment_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}