<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use LogicException;

final readonly class AssistantTaskFrame
{
    private function __construct(private array $payload)
    {
    }

    public static function fieldKeys(array $segments): array
    {
        $keys = [];
        foreach ($segments as $segment) {
            if (!$segment instanceof AssistantSafeContextSegment) {
                throw new LogicException('context_unsealed_segment');
            }
            foreach ($segment->fields() as $sourceRef => $fieldRefs) {
                foreach ($fieldRefs as $fieldRef) {
                    $keys[] = AssistantContextSourceBinding::canonical([$sourceRef, $fieldRef]);
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public static function fromSegment(AssistantSafeContextSegment $proposal, array $segments, string $currentRef, array $approvedMediaRefs): self
    {
        if ($proposal->kind() !== 'frame') {
            throw new LogicException('context_frame_kind_mismatch');
        }
        $metadata = $proposal->metadata();
        $refs = [$currentRef, $metadata['topicRef'], ...$metadata['entityRefs'], ...$metadata['filterRefs'], ...$metadata['mediaRefs'], ...$metadata['transcriptRefs']];
        $refs = AssistantContextSourceBinding::references($refs);
        $covered = $metadata['coveredArtifactRefs'];
        sort($refs);
        sort($covered);
        if ($refs !== $covered || $metadata['mediaRefs'] !== $approvedMediaRefs) {
            throw new LogicException('context_frame_coverage_mismatch');
        }
        $targets = [];
        foreach ($refs as $ref) {
            $segment = $segments[$ref] ?? null;
            if (!$segment instanceof AssistantSafeContextSegment) {
                throw new LogicException('context_frame_reference_unavailable');
            }
            $targets[] = $segment;
        }
        if (self::fieldKeys([$proposal]) !== self::fieldKeys($targets)) {
            throw new LogicException('context_frame_binding_mismatch');
        }
        foreach (['topicRef' => 'topic', 'entityRefs' => 'entity', 'filterRefs' => 'filter', 'mediaRefs' => 'media', 'transcriptRefs' => 'transcript'] as $key => $kind) {
            foreach ($key === 'topicRef' ? [$metadata[$key]] : $metadata[$key] as $ref) {
                if ($segments[$ref]->kind() !== $kind) {
                    throw new LogicException('context_frame_reference_kind_mismatch');
                }
            }
        }
        $transcriptRefs = [];
        foreach ($metadata['mediaRefs'] as $mediaRef) {
            $transcriptRef = $segments[$mediaRef]->metadata()['transcriptRef'];
            if (!isset($segments[$transcriptRef]) || $segments[$transcriptRef]->kind() !== 'transcript' || $segments[$transcriptRef]->metadata()['mediaRef'] !== $mediaRef) {
                throw new LogicException('context_media_transcript_mismatch');
            }
            $transcriptRefs[] = $transcriptRef;
        }
        if ($transcriptRefs !== $metadata['transcriptRefs']) {
            throw new LogicException('context_media_transcript_mismatch');
        }
        $payload = ['proposalRef' => $proposal->opaqueRef(), 'currentRef' => $segments[$currentRef]->opaqueRef()];
        foreach ($metadata as $key => $value) {
            $payload[$key] = $key === 'topicRef'
                ? $segments[$value]->opaqueRef()
                : array_map(static fn (string $ref): string => $segments[$ref]->opaqueRef(), $value);
        }

        return new self($payload);
    }

    public function modelPayload(): array
    {
        return $this->payload;
    }

    public function __serialize(): array
    {
        throw new LogicException('context_frame_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('context_frame_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}