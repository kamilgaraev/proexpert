<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantSafeContextSegment;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantTaskFrame;
use LogicException;

final readonly class AssistantContextReceipt
{
    private function __construct(private array $prepared, private array $authority, private array $receipt, private array $issuedConversation)
    {
    }

    public static function consume(array $prepared, array $authority, string $profileRef): self
    {
        if (($prepared['status'] ?? null) !== 'READY' || ($prepared['mode'] ?? null) !== 'offline-synthetic' || ($prepared['transportAllowed'] ?? null) !== false || !is_array($prepared['payload'] ?? null)) {
            throw new LogicException('context_not_ready');
        }
        if (!AssistantModelContextProfile::hasExactKeys($authority, ['snapshot', 'profile', 'lineage', 'stored', 'artifacts'])) {
            throw new LogicException('authority_unavailable');
        }
        $snapshot = $authority['snapshot'];
        if (!is_array($snapshot) || !is_array($authority['profile']) || !is_array($authority['lineage']) || !is_array($authority['stored']) || !is_array($authority['artifacts'])) {
            throw new LogicException('authority_unavailable');
        }
        AssistantContextSourceBinding::snapshotHash($snapshot);
        $stored = $authority['stored'];
        if (!AssistantModelContextProfile::hasExactKeys($stored, ['status', 'receipt', 'digest']) || $stored['status'] !== 'committed' || !is_array($stored['receipt']) || !is_string($stored['digest'])) {
            throw new LogicException('receipt_not_committed');
        }
        $receipt = $stored['receipt'];
        if (!AssistantModelContextProfile::hasExactKeys($receipt, ['schemaVersion', 'contextRef', 'currentRef', 'payloadDigest', 'scopeHash', 'scope', 'conversationRef', 'profileRef', 'profileFingerprint', 'modelProfile', 'aliases', 'sources', 'lineage']) || $receipt['schemaVersion'] !== 'assistant-context-receipt/1' || $receipt['profileRef'] !== $profileRef || !hash_equals($stored['digest'], hash('sha256', AssistantContextSourceBinding::canonical($receipt)))) {
            throw new LogicException('receipt_invalid');
        }
        $payload = $prepared['payload'];
        if (!AssistantModelContextProfile::hasExactKeys($payload, ['schemaVersion', 'contextRef', 'currentRef', 'modelProfile', 'messages', 'taskFrame']) || $payload['schemaVersion'] !== 'assistant-context/2' || $payload['contextRef'] !== $receipt['contextRef'] || $payload['currentRef'] !== $receipt['currentRef'] || $receipt['payloadDigest'] !== hash('sha256', AssistantContextSourceBinding::canonical($payload))) {
            throw new LogicException('receipt_payload_mismatch');
        }
        $profile = AssistantModelContextProfile::resolve($profileRef, static fn (string $ref): array => $authority['profile']);
        if ($receipt['profileFingerprint'] !== $profile->fingerprint() || $receipt['modelProfile'] !== $profile->modelPayload() || $payload['modelProfile'] !== $profile->modelPayload() || $profile->adapterRevision() !== $snapshot['adapterRevision'] || $receipt['scope'] !== $snapshot['scope'] || $receipt['scopeHash'] !== hash('sha256', AssistantContextSourceBinding::canonical($snapshot['scope'])) || $receipt['conversationRef'] !== $snapshot['conversation']['ref']) {
            throw new LogicException('receipt_authority_mismatch');
        }
        $lineage = $authority['lineage'];
        if (!AssistantModelContextProfile::hasExactKeys($lineage, ['requestRef', 'requestRevision', 'conversationRef', 'issuedAt', 'expiresAt', 'now']) || array_diff_key($lineage, ['now' => true]) !== $receipt['lineage'] || !is_int($lineage['now']) || !is_int($lineage['issuedAt']) || !is_int($lineage['expiresAt']) || $lineage['issuedAt'] >= $lineage['expiresAt'] || $lineage['now'] < $lineage['issuedAt'] || $lineage['now'] >= $lineage['expiresAt'] || $lineage['conversationRef'] !== $snapshot['conversation']['ref']) {
            throw new LogicException('receipt_lineage_invalid');
        }
        if (!is_array($receipt['aliases']) || !is_array($receipt['sources']) || !is_array($payload['messages']) || !array_is_list($payload['messages'])) {
            throw new LogicException('receipt_aliases_invalid');
        }
        $sources = [];
        foreach ($receipt['sources'] as $alias => $source) {
            AssistantContextSourceBinding::references([$alias]);
            if (!is_array($source) || !AssistantModelContextProfile::hasExactKeys($source, ['sourceRef', 'source']) || ($snapshot['sources'][$source['sourceRef']] ?? null) !== $source['source'] || isset($sources[$source['sourceRef']])) {
                throw new LogicException('receipt_source_stale');
            }
            $sources[$source['sourceRef']] = $alias;
        }
        $seen = [];
        $artifactAliases = [];
        $segments = [];
        $artifactOrder = [];
        foreach ($payload['messages'] as $message) {
            if (!is_array($message) || !AssistantModelContextProfile::hasExactKeys($message, ['role', 'content', 'ref', 'sourceRefs']) || !is_string($message['ref']) || isset($seen[$message['ref']])) {
                throw new LogicException('receipt_message_invalid');
            }
            $alias = $receipt['aliases'][$message['ref']] ?? null;
            if (!is_array($alias) || !AssistantModelContextProfile::hasExactKeys($alias, ['artifactRef', 'kind', 'fields', 'metadata', 'sourceRefs'])) {
                throw new LogicException('receipt_alias_invalid');
            }
            $artifact = $authority['artifacts'][$alias['artifactRef']] ?? null;
            if (isset($artifactAliases[$alias['artifactRef']])) {
                throw new LogicException('receipt_artifact_duplicate');
            }
            $segment = AssistantSafeContextSegment::project($alias['artifactRef'], $snapshot, static fn (string $ref, array $state): mixed => $artifact);
            $expected = $segment->modelMessage($sources);
            $expected['ref'] = $message['ref'];
            if ($message !== $expected || $alias['kind'] !== $segment->kind() || $alias['fields'] !== $segment->fields() || $alias['metadata'] !== $segment->metadata() || $alias['sourceRefs'] !== $message['sourceRefs']) {
                throw new LogicException('receipt_artifact_mismatch');
            }
            $seen[$message['ref']] = true;
            $artifactAliases[$alias['artifactRef']] = $message['ref'];
            $segments[$alias['artifactRef']] = $segment;
            $artifactOrder[] = $alias['artifactRef'];
        }
        if (array_keys($receipt['aliases']) !== array_keys($seen) || ($receipt['aliases'][$receipt['currentRef']]['artifactRef'] ?? null) !== $snapshot['conversation']['currentRef'] || ($receipt['aliases'][$receipt['currentRef']]['kind'] ?? null) !== 'user') {
            throw new LogicException('receipt_current_mismatch');
        }
        $conversation = $snapshot['conversation'];
        $history = $conversation['historyRefs'];
        $prefix = $conversation['systemRefs'];
        $summaryRef = $artifactOrder[count($prefix)] ?? null;
        if (is_string($summaryRef) && $segments[$summaryRef]->kind() === 'summary') {
            $summary = $segments[$summaryRef];
            $covered = $summary->metadata()['coveredArtifactRefs'];
            if ($covered === [] || $covered !== array_slice($history, 0, count($covered))) {
                throw new LogicException('receipt_summary_chronology_invalid');
            }
            $dropped = [];
            foreach ($covered as $ref) {
                $artifact = $authority['artifacts'][$ref] ?? null;
                $dropped[] = AssistantSafeContextSegment::project($ref, $snapshot, static fn (string $ref, array $state): mixed => $artifact);
            }
            if (AssistantTaskFrame::fieldKeys([$summary]) !== AssistantTaskFrame::fieldKeys($dropped)) {
                throw new LogicException('receipt_summary_fields_invalid');
            }
            $prefix[] = $summaryRef;
            $history = array_slice($history, count($covered));
        }
        $prefix = [...$prefix, ...$history, $conversation['currentRef']];
        if (array_slice($artifactOrder, 0, count($prefix)) !== $prefix) {
            throw new LogicException('receipt_chronology_invalid');
        }
        if ($payload['taskFrame'] !== null) {
            $frame = $payload['taskFrame'];
            if (!is_array($frame) || !AssistantModelContextProfile::hasExactKeys($frame, ['proposalRef', 'currentRef', 'topicRef', 'entityRefs', 'filterRefs', 'mediaRefs', 'transcriptRefs', 'coveredArtifactRefs']) || $frame['currentRef'] !== $receipt['currentRef']) {
                throw new LogicException('receipt_frame_invalid');
            }
            $proposalAlias = $receipt['aliases'][$frame['proposalRef']] ?? null;
            if (!is_array($proposalAlias) || $proposalAlias['kind'] !== 'frame') {
                throw new LogicException('receipt_frame_invalid');
            }
            $proposal = $segments[$proposalAlias['artifactRef']];
            AssistantTaskFrame::fromSegment($proposal, $segments, $conversation['currentRef'], $conversation['mediaRefs']);
            $expectedFrame = ['proposalRef' => $frame['proposalRef'], 'currentRef' => $receipt['currentRef']];
            foreach ($proposal->metadata() as $key => $refs) {
                $expectedFrame[$key] = $key === 'topicRef' ? ($artifactAliases[$refs] ?? null) : array_map(static fn (string $ref): mixed => $artifactAliases[$ref] ?? null, $refs);
            }
            if ($frame !== $expectedFrame || ($prepared['frame'] ?? null) !== $frame) {
                throw new LogicException('receipt_frame_mapping_invalid');
            }
        }

        return new self(AssistantContextSourceBinding::detached($prepared), AssistantContextSourceBinding::detached($authority), AssistantContextSourceBinding::detached($receipt), AssistantContextSourceBinding::detached($snapshot['conversation']));
    }

    public function revalidate(array $authority, bool $allowHistoryAdvance = false): self
    {
        $validationAuthority = AssistantContextSourceBinding::detached($authority);
        if ($allowHistoryAdvance) {
            $old = $this->authority['snapshot'];
            $new = $authority['snapshot'];
            AssistantContextSourceBinding::snapshotHash($new);
            foreach (['authorized', 'adapterRevision', 'scope'] as $key) {
                if ($new[$key] !== $old[$key]) {
                    throw new LogicException('context_transition_invalid');
                }
            }
            foreach (['ref', 'currentRef', 'systemRefs', 'mediaRefs'] as $key) {
                if ($new['conversation'][$key] !== $old['conversation'][$key]) {
                    throw new LogicException('context_transition_invalid');
                }
            }
            if (array_slice($new['conversation']['historyRefs'], 0, count($old['conversation']['historyRefs'])) !== $old['conversation']['historyRefs']) {
                throw new LogicException('context_chronology_changed');
            }
            foreach ($old['sources'] as $ref => $source) {
                if (($new['sources'][$ref] ?? null) !== $source) {
                    throw new LogicException('context_transition_source_changed');
                }
            }
            $validationAuthority['snapshot']['conversation'] = $this->issuedConversation;
        }
        $current = self::consume($this->prepared, $validationAuthority, $this->receipt['profileRef']);
        if ($authority['stored'] !== $this->authority['stored'] || $authority['lineage']['now'] < $this->authority['lineage']['now']) {
            throw new LogicException('receipt_changed');
        }
        if (!$allowHistoryAdvance && AssistantContextSourceBinding::snapshotHash($authority['snapshot']) !== AssistantContextSourceBinding::snapshotHash($this->authority['snapshot'])) {
            throw new LogicException('context_snapshot_changed');
        }
        if ($allowHistoryAdvance) {
            return new self($this->prepared, AssistantContextSourceBinding::detached($authority), $this->receipt, $this->issuedConversation);
        }

        return $current;
    }

    public function resolve(string $alias, array $kinds): array
    {
        $value = $this->receipt['aliases'][$alias] ?? null;
        if (!is_array($value) || !in_array($value['kind'], $kinds, true)) {
            throw new LogicException('reference_unavailable');
        }

        return AssistantContextSourceBinding::detached($value);
    }

    public function payload(): array
    {
        return AssistantContextSourceBinding::detached($this->prepared['payload']);
    }

    public function profile(): AssistantModelContextProfile
    {
        return AssistantModelContextProfile::resolve($this->receipt['profileRef'], fn (string $ref): array => $this->authority['profile']);
    }

    public function privateBinding(): array
    {
        return AssistantContextSourceBinding::detached($this->authority + ['receipt' => $this->receipt]);
    }

    public function callBinding(string $callRef): array
    {
        return ['callRef' => $callRef, 'contextRef' => $this->receipt['contextRef'], 'payloadDigest' => $this->receipt['payloadDigest'], 'profileFingerprint' => $this->receipt['profileFingerprint']];
    }

    public function sourceRefs(): array
    {
        return array_keys($this->receipt['sources']);
    }

    public function contextScope(): array
    {
        $refs = [$this->receipt['currentRef']];
        $history = $this->authority['snapshot']['conversation']['historyRefs'];
        foreach ($this->prepared['payload']['messages'] as $message) {
            $ref = $message['ref'];
            $alias = $this->receipt['aliases'][$ref];
            $eligibleHistory = in_array($alias['kind'], ['user', 'assistant'], true) && in_array($alias['artifactRef'], $history, true);
            $covered = $alias['kind'] === 'summary' ? $alias['metadata']['coveredArtifactRefs'] : [];
            $eligibleSummary = $alias['kind'] === 'summary' && $covered !== [] && $covered === array_slice($history, 0, count($covered));
            if (in_array($alias['kind'], ['media', 'transcript', 'entity', 'topic', 'filter'], true) || $eligibleHistory || $eligibleSummary) {
                $refs[] = $ref;
            }
        }

        return ['kind' => 'selected_entity', 'scopeRef' => 'ref_' . substr(hash('sha256', $this->receipt['contextRef'] . '/context-scope'), 0, 32), 'sourceGenerationRef' => 'ref_' . substr(hash('sha256', $this->receipt['contextRef'] . '/context-generation'), 0, 32), 'unitRefs' => $refs];
    }

    public function contextSourceRefs(): array
    {
        $sources = [];
        foreach ($this->contextScope()['unitRefs'] as $ref) {
            $sources = [...$sources, ...$this->receipt['aliases'][$ref]['sourceRefs']];
        }

        return array_values(array_unique($sources));
    }

    public function __serialize(): array
    {
        throw new LogicException('context_receipt_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('context_receipt_deserialization_forbidden');
    }
}
