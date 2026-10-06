<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;

final class AssistantSafeContextAssembler
{
    public function __construct(private readonly ?Closure $trustedSnapshot = null)
    {
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
        AssistantContextSourceBinding::snapshotHash($snapshot);
        $snapshot = AssistantContextSourceBinding::detached($snapshot);
        $this->fresh($snapshot);
        $sources = [];
        $artifactRefs = [];
        foreach ($segments as $segment) {
            if (!$segment instanceof AssistantSafeContextSegment) {
                throw new LogicException('context_unsealed_segment');
            }
            if (in_array($segment->artifactRef(), $artifactRefs, true)) {
                throw new LogicException('context_segment_duplicate');
            }
            $artifactRefs[] = $segment->artifactRef();
            $segment->revalidate($snapshot);
            foreach (array_keys($segment->fields()) as $sourceRef) {
                $sources[$sourceRef] ??= 'ref_' . bin2hex(random_bytes(16));
            }
        }

        $payload = [
            'schemaVersion' => 'assistant-context/1',
            'modelProfile' => $profile->modelPayload(),
            'messages' => array_map(static fn (AssistantSafeContextSegment $segment): array => $segment->modelMessage($sources), $segments),
            'taskFrame' => $frame?->modelPayload(),
        ];
        $this->fresh($snapshot);

        return $payload;
    }
}