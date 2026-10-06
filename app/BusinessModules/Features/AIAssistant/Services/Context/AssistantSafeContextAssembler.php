<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;

final class AssistantSafeContextAssembler
{
    public function __construct(
        private readonly ?Closure $trustedSnapshot = null,
        private readonly ?Closure $trustedCandidateSink = null,
    ) {
    }

    private function fresh(array $baseline): void
    {
        if ($this->trustedSnapshot === null) {
            throw new LogicException('context_authority_unavailable');
        }
        $snapshot = ($this->trustedSnapshot)();
        if (!is_array($snapshot) || !hash_equals(AssistantContextSourceBinding::snapshotHash($baseline), AssistantContextSourceBinding::snapshotHash($snapshot))) {
            throw new LogicException('context_snapshot_changed');
        }
    }

    public function assemble(AssistantModelContextProfile $profile, array $segments, ?AssistantTaskFrame $frame, array $snapshot): array
    {
        if ($this->trustedCandidateSink === null) {
            throw new LogicException('context_candidate_sink_unavailable');
        }
        AssistantContextSourceBinding::snapshotHash($snapshot);
        $snapshot = AssistantContextSourceBinding::detached($snapshot);
        $this->fresh($snapshot);
        $sources = [];
        $artifactRefs = [];
        $currentRef = null;
        foreach ($segments as $segment) {
            if (!$segment instanceof AssistantSafeContextSegment) {
                throw new LogicException('context_unsealed_segment');
            }
            if (in_array($segment->artifactRef(), $artifactRefs, true)) {
                throw new LogicException('context_segment_duplicate');
            }
            $artifactRefs[] = $segment->artifactRef();
            $segment->revalidate($snapshot);
            if ($segment->artifactRef() === $snapshot['conversation']['currentRef']) {
                if ($segment->kind() !== 'user') {
                    throw new LogicException('context_current_reference_mismatch');
                }
                $currentRef = $segment->opaqueRef();
            }
            foreach (array_keys($segment->fields()) as $sourceRef) {
                $sources[$sourceRef] ??= 'ref_' . bin2hex(random_bytes(16));
            }
        }
        if ($currentRef === null) {
            throw new LogicException('context_current_reference_unavailable');
        }
        $payload = AssistantContextSourceBinding::detached([
            'schemaVersion' => 'assistant-context/2',
            'contextRef' => 'ref_' . bin2hex(random_bytes(16)),
            'currentRef' => $currentRef,
            'modelProfile' => $profile->modelPayload(),
            'messages' => array_map(static fn (AssistantSafeContextSegment $segment): array => $segment->modelMessage($sources), $segments),
            'taskFrame' => $frame?->modelPayload(),
        ]);
        $aliases = [];
        foreach ($segments as $segment) {
            $message = $segment->modelMessage($sources);
            if (isset($aliases[$segment->opaqueRef()])) {
                throw new LogicException('context_alias_duplicate');
            }
            $aliases[$segment->opaqueRef()] = [
                'artifactRef' => $segment->artifactRef(),
                'kind' => $segment->kind(),
                'fields' => $segment->fields(),
                'metadata' => $segment->metadata(),
                'sourceRefs' => $message['sourceRefs'],
            ];
        }
        $sourceAliases = [];
        foreach ($sources as $sourceRef => $opaqueRef) {
            if (!isset($snapshot['sources'][$sourceRef]) || isset($sourceAliases[$opaqueRef])) {
                throw new LogicException('context_source_alias_invalid');
            }
            $sourceAliases[$opaqueRef] = ['sourceRef' => $sourceRef, 'source' => $snapshot['sources'][$sourceRef]];
        }
        $candidate = AssistantContextSourceBinding::detached([
            'contextRef' => $payload['contextRef'],
            'currentRef' => $currentRef,
            'payloadDigest' => hash('sha256', AssistantContextSourceBinding::canonical($payload)),
            'scopeHash' => hash('sha256', AssistantContextSourceBinding::canonical($snapshot['scope'])),
            'scope' => $snapshot['scope'],
            'conversationRef' => $snapshot['conversation']['ref'],
            'profileFingerprint' => $profile->fingerprint(),
            'modelProfile' => $profile->modelPayload(),
            'aliases' => $aliases,
            'sources' => $sourceAliases,
        ]);
        $this->fresh($snapshot);
        ($this->trustedCandidateSink)(AssistantContextSourceBinding::detached($candidate));
        $this->fresh($snapshot);

        return $payload;
    }
}