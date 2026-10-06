<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;
use Throwable;

final class AssistantContextPreparationService
{
    public function __construct(
        private readonly ?Closure $trustedSnapshot = null,
        private readonly ?Closure $trustedProjector = null,
        private readonly ?Closure $trustedProfileSource = null,
        private readonly ?Closure $trustedTokenizer = null,
        private readonly ?Closure $trustedReceiptPublisher = null,
    ) {
    }

    public function prepare(string $profileRef, array $request): array
    {
        if ($this->trustedSnapshot === null || $this->trustedProjector === null || $this->trustedProfileSource === null || $this->trustedTokenizer === null || $this->trustedReceiptPublisher === null) {
            return ['status' => 'BLOCKED', 'reason' => 'required_stage_unavailable'];
        }
        try {
            return $this->prepareGuarded($profileRef, $request);
        } catch (Throwable) {
            return ['status' => 'BLOCKED', 'reason' => 'context_preparation_blocked'];
        }
    }

    private function invoke(Closure $callback, mixed ...$arguments): mixed
    {
        try {
            return $callback(...$arguments);
        } catch (Throwable) {
            throw new LogicException('trusted_callback_failed');
        }
    }

    private function snapshot(): array
    {
        if ($this->trustedSnapshot === null) {
            throw new LogicException('context_authority_unavailable');
        }
        $snapshot = $this->invoke($this->trustedSnapshot);
        if (!is_array($snapshot)) {
            throw new LogicException('context_authority_unavailable');
        }
        AssistantContextSourceBinding::snapshotHash($snapshot);

        return AssistantContextSourceBinding::detached($snapshot);
    }

    private function fresh(array $baseline): array
    {
        $snapshot = $this->snapshot();
        if (!hash_equals(AssistantContextSourceBinding::snapshotHash($baseline), AssistantContextSourceBinding::snapshotHash($snapshot))) {
            throw new LogicException('context_snapshot_changed');
        }

        return $snapshot;
    }

    private function guarded(array $baseline, Closure $operation): mixed
    {
        $this->fresh($baseline);
        $result = $operation();
        $this->fresh($baseline);

        return $result;
    }

    private function project(string $ref, array $baseline): AssistantSafeContextSegment
    {
        $snapshot = $this->fresh($baseline);
        if ($this->trustedProjector === null) {
            throw new LogicException('context_projection_unavailable');
        }
        $projector = fn (string $artifactRef, array $sourceSnapshot): mixed => $this->invoke($this->trustedProjector, $artifactRef, $sourceSnapshot);
        $segment = $this->guarded($baseline, static fn (): AssistantSafeContextSegment => AssistantSafeContextSegment::project($ref, $snapshot, $projector));
        if (!$segment instanceof AssistantSafeContextSegment) {
            throw new LogicException('context_unsealed_segment');
        }

        return $segment;
    }

    private function profile(string $profileRef, array $baseline): AssistantModelContextProfile
    {
        if ($this->trustedProfileSource === null) {
            throw new LogicException('model_profile_unavailable');
        }
        $source = fn (string $ref): mixed => $this->invoke($this->trustedProfileSource, $ref);
        $profile = $this->guarded($baseline, static fn (): AssistantModelContextProfile => AssistantModelContextProfile::resolve($profileRef, $source));
        if (!$profile instanceof AssistantModelContextProfile || $profile->adapterRevision() !== $baseline['adapterRevision']) {
            throw new LogicException('context_adapter_mismatch');
        }

        return $profile;
    }

    private function freshProfile(string $profileRef, AssistantModelContextProfile $profile, array $baseline): void
    {
        $current = $this->profile($profileRef, $baseline);
        if (!hash_equals($profile->fingerprint(), $current->fingerprint())) {
            throw new LogicException('model_profile_changed');
        }
    }

    private function publicationEvent(string $event, array $data, array $expected, string $profileRef, AssistantModelContextProfile $profile, array $baseline): mixed
    {
        if ($this->trustedReceiptPublisher === null) {
            throw new LogicException('context_receipt_publisher_unavailable');
        }
        $this->freshProfile($profileRef, $profile, $baseline);
        $this->fresh($baseline);
        try {
            return $this->invoke($this->trustedReceiptPublisher, $event, AssistantContextSourceBinding::detached($data), AssistantContextSourceBinding::detached($expected));
        } finally {
            $this->freshProfile($profileRef, $profile, $baseline);
            $this->fresh($baseline);
        }
    }

    private function lineage(string $profileRef, AssistantModelContextProfile $profile, array $baseline, ?array $previous): array
    {
        $value = $this->publicationEvent('lineage', ['scope' => $baseline['scope'], 'conversationRef' => $baseline['conversation']['ref']], [], $profileRef, $profile, $baseline);

        return $this->validateLineage($value, $baseline, $previous);
    }

    private function validateLineage(mixed $value, array $baseline, ?array $previous): array
    {
        if (!is_array($value) || !AssistantModelContextProfile::hasExactKeys($value, ['requestRef', 'requestRevision', 'conversationRef', 'issuedAt', 'expiresAt', 'now'])) {
            throw new LogicException('context_lineage_unavailable');
        }
        foreach (['requestRef', 'requestRevision', 'conversationRef'] as $key) {
            AssistantContextSourceBinding::references([$value[$key]]);
        }
        foreach (['issuedAt', 'expiresAt', 'now'] as $key) {
            if (!is_int($value[$key]) || $value[$key] < 1) {
                throw new LogicException('context_lineage_lifetime_invalid');
            }
        }
        if ($value['conversationRef'] !== $baseline['conversation']['ref'] || $value['issuedAt'] >= $value['expiresAt'] || $value['now'] < $value['issuedAt'] || $value['now'] >= $value['expiresAt']) {
            throw new LogicException('context_lineage_invalid');
        }
        $value = AssistantContextSourceBinding::detached($value);
        if ($previous !== null && ($value['now'] < $previous['now'] || array_diff_key($value, ['now' => true]) !== array_diff_key($previous, ['now' => true]))) {
            throw new LogicException('context_lineage_changed');
        }

        return $value;
    }

    private function finalGuard(array $receipt, array $expected, string $profileRef, AssistantModelContextProfile $profile, array $baseline, array $lineage): void
    {
        if ($this->trustedReceiptPublisher === null) {
            throw new LogicException('context_receipt_publisher_unavailable');
        }
        $snapshotHash = AssistantContextSourceBinding::snapshotHash($baseline);
        $profileFingerprint = $profile->fingerprint();
        $this->freshProfile($profileRef, $profile, $baseline);
        $this->fresh($baseline);
        $guard = $this->invoke($this->trustedReceiptPublisher, 'final_guard', AssistantContextSourceBinding::detached($receipt), AssistantContextSourceBinding::detached($expected));
        if (!is_array($guard) || !AssistantModelContextProfile::hasExactKeys($guard, ['schemaVersion', 'status', 'contextRef', 'payloadDigest', 'receiptDigest', 'snapshotHash', 'profileFingerprint', 'lineage']) || $guard['schemaVersion'] !== 'assistant-context-final-guard/1' || $guard['status'] !== 'committed') {
            throw new LogicException('context_final_guard_invalid');
        }
        foreach ($expected + ['snapshotHash' => $snapshotHash, 'profileFingerprint' => $profileFingerprint] as $key => $value) {
            if (!is_string($guard[$key]) || !hash_equals($value, $guard[$key])) {
                throw new LogicException('context_final_guard_mismatch');
            }
        }
        $this->validateLineage($guard['lineage'], $baseline, $lineage);
    }

    private function acknowledge(mixed $ack, string $status, array $expected): void
    {
        if (!is_array($ack) || !AssistantModelContextProfile::hasExactKeys($ack, ['schemaVersion', 'status', 'contextRef', 'payloadDigest', 'receiptDigest']) || $ack['schemaVersion'] !== 'assistant-context-receipt-ack/1' || $ack['status'] !== $status) {
            throw new LogicException('context_receipt_ack_invalid');
        }
        foreach ($expected as $key => $value) {
            if (!is_string($ack[$key]) || !hash_equals($value, $ack[$key])) {
                throw new LogicException('context_receipt_ack_mismatch');
            }
        }
    }

    private function abortReceipt(array $receipt, array $expected, string $profileRef, AssistantModelContextProfile $profile, array $baseline, array $lineage): void
    {
        try {
            $this->freshProfile($profileRef, $profile, $baseline);
            $this->fresh($baseline);
            $lineage = $this->lineage($profileRef, $profile, $baseline, $lineage);
        } catch (Throwable) {
        }
        try {
            if ($this->trustedReceiptPublisher !== null) {
                $this->invoke($this->trustedReceiptPublisher, 'abort', AssistantContextSourceBinding::detached($receipt), AssistantContextSourceBinding::detached($expected));
            }
        } catch (Throwable) {
        }
        try {
            $this->freshProfile($profileRef, $profile, $baseline);
            $this->fresh($baseline);
            $this->lineage($profileRef, $profile, $baseline, $lineage);
        } catch (Throwable) {
        }
    }

    private function publishCandidate(array $candidate, array $payload, string $profileRef, AssistantModelContextProfile $profile, array $baseline, array $lineage): void
    {
        if (!AssistantModelContextProfile::hasExactKeys($candidate, ['contextRef', 'currentRef', 'payloadDigest', 'scopeHash', 'scope', 'conversationRef', 'profileFingerprint', 'modelProfile', 'aliases', 'sources']) || $candidate['contextRef'] !== $payload['contextRef'] || $candidate['currentRef'] !== $payload['currentRef'] || $candidate['payloadDigest'] !== hash('sha256', AssistantContextSourceBinding::canonical($payload)) || $candidate['scope'] !== $baseline['scope'] || $candidate['scopeHash'] !== hash('sha256', AssistantContextSourceBinding::canonical($baseline['scope'])) || $candidate['conversationRef'] !== $baseline['conversation']['ref'] || $candidate['profileFingerprint'] !== $profile->fingerprint() || $candidate['modelProfile'] !== $profile->modelPayload()) {
            throw new LogicException('context_candidate_mismatch');
        }
        if (($candidate['aliases'][$payload['currentRef']]['artifactRef'] ?? null) !== $baseline['conversation']['currentRef']) {
            throw new LogicException('context_candidate_current_mismatch');
        }
        foreach ($candidate['sources'] as $source) {
            if (!is_array($source) || !AssistantModelContextProfile::hasExactKeys($source, ['sourceRef', 'source']) || !isset($baseline['sources'][$source['sourceRef']]) || $source['source'] !== $baseline['sources'][$source['sourceRef']]) {
                throw new LogicException('context_candidate_source_mismatch');
            }
        }
        $receipt = AssistantContextSourceBinding::detached([
            'schemaVersion' => 'assistant-context-receipt/1',
            'contextRef' => $candidate['contextRef'],
            'currentRef' => $candidate['currentRef'],
            'payloadDigest' => $candidate['payloadDigest'],
            'scopeHash' => $candidate['scopeHash'],
            'scope' => $candidate['scope'],
            'conversationRef' => $candidate['conversationRef'],
            'profileRef' => $profileRef,
            'profileFingerprint' => $candidate['profileFingerprint'],
            'modelProfile' => $candidate['modelProfile'],
            'aliases' => $candidate['aliases'],
            'sources' => $candidate['sources'],
            'lineage' => array_diff_key($lineage, ['now' => true]),
        ]);
        $expected = AssistantContextSourceBinding::detached([
            'contextRef' => $receipt['contextRef'],
            'payloadDigest' => $receipt['payloadDigest'],
            'receiptDigest' => hash('sha256', AssistantContextSourceBinding::canonical($receipt)),
        ]);
        $stageAttempted = false;
        try {
            $lineage = $this->lineage($profileRef, $profile, $baseline, $lineage);
            $stageAttempted = true;
            $ack = $this->publicationEvent('stage', $receipt, $expected, $profileRef, $profile, $baseline);
            $this->acknowledge($ack, 'staged', $expected);
            $lineage = $this->lineage($profileRef, $profile, $baseline, $lineage);
            $this->freshProfile($profileRef, $profile, $baseline);
            $lineage = $this->lineage($profileRef, $profile, $baseline, $lineage);
            $ack = $this->publicationEvent('commit', $receipt, $expected, $profileRef, $profile, $baseline);
            $this->acknowledge($ack, 'committed', $expected);
            $lineage = $this->lineage($profileRef, $profile, $baseline, $lineage);
            $this->finalGuard($receipt, $expected, $profileRef, $profile, $baseline, $lineage);
        } catch (Throwable $error) {
            if ($stageAttempted) {
                $this->abortReceipt($receipt, $expected, $profileRef, $profile, $baseline, $lineage);
            }
            throw $error;
        }
    }

    private function requireKind(AssistantSafeContextSegment $segment, array $kinds): void
    {
        if (!in_array($segment->kind(), $kinds, true)) {
            throw new LogicException('context_role_mismatch');
        }
    }

    private function prepareGuarded(string $profileRef, array $request): array
    {
        $allowed = ['systemRefs', 'currentRef', 'historyRefs', 'mediaRefs', 'summaryRef', 'taskFrameRef'];
        if (array_diff(array_keys($request), $allowed) !== [] || !isset($request['currentRef']) || !is_string($request['currentRef'])) {
            throw new LogicException('context_request_invalid');
        }
        $baseline = $this->snapshot();
        $conversation = $baseline['conversation'];
        if ($request['currentRef'] !== $conversation['currentRef']) {
            throw new LogicException('context_current_reference_mismatch');
        }
        foreach (['systemRefs', 'historyRefs', 'mediaRefs'] as $key) {
            if (array_key_exists($key, $request) && AssistantContextSourceBinding::references($request[$key]) !== $conversation[$key]) {
                throw new LogicException('context_chronology_mismatch');
            }
        }
        $authoritativeRefs = [...$conversation['systemRefs'], ...$conversation['historyRefs'], $conversation['currentRef'], ...$conversation['mediaRefs']];
        $optionalRefs = [];
        foreach (['summaryRef', 'taskFrameRef'] as $key) {
            if (isset($request[$key])) {
                $optionalRefs[] = $request[$key];
            }
        }
        AssistantContextSourceBinding::references([...$authoritativeRefs, ...$optionalRefs]);
        $request = AssistantContextSourceBinding::detached($request);
        $profile = $this->profile($profileRef, $baseline);
        $lineage = $this->lineage($profileRef, $profile, $baseline, null);
        $segments = [];
        foreach ($conversation['systemRefs'] as $ref) {
            $segments[$ref] = $this->project($ref, $baseline);
            $this->requireKind($segments[$ref], ['system']);
        }
        foreach ($conversation['historyRefs'] as $ref) {
            $segments[$ref] = $this->project($ref, $baseline);
            $this->requireKind($segments[$ref], ['user', 'assistant', 'tool']);
        }
        $currentRef = $conversation['currentRef'];
        $segments[$currentRef] = $this->project($currentRef, $baseline);
        $this->requireKind($segments[$currentRef], ['user']);
        $auxiliaryRefs = [];
        foreach ($conversation['mediaRefs'] as $ref) {
            $segments[$ref] = $this->project($ref, $baseline);
            $this->requireKind($segments[$ref], ['media']);
            $transcriptRef = $segments[$ref]->metadata()['transcriptRef'];
            if (isset($segments[$transcriptRef])) {
                throw new LogicException('context_media_transcript_duplicate');
            }
            $segments[$transcriptRef] = $this->project($transcriptRef, $baseline);
            $this->requireKind($segments[$transcriptRef], ['transcript']);
            if ($segments[$transcriptRef]->metadata()['mediaRef'] !== $ref) {
                throw new LogicException('context_media_transcript_mismatch');
            }
            $auxiliaryRefs = [...$auxiliaryRefs, $ref, $transcriptRef];
        }
        $frame = null;
        if (isset($request['taskFrameRef'])) {
            $frameRef = $request['taskFrameRef'];
            $proposal = $this->project($frameRef, $baseline);
            $this->requireKind($proposal, ['frame']);
            $metadata = $proposal->metadata();
            $targetRefs = [$metadata['topicRef'], ...$metadata['entityRefs'], ...$metadata['filterRefs'], ...$metadata['mediaRefs'], ...$metadata['transcriptRefs']];
            AssistantContextSourceBinding::references([$frameRef, $currentRef, ...$targetRefs]);
            foreach ($targetRefs as $ref) {
                if (!isset($segments[$ref])) {
                    $segments[$ref] = $this->project($ref, $baseline);
                    $auxiliaryRefs[] = $ref;
                }
            }
            $segments[$frameRef] = $proposal;
            $auxiliaryRefs[] = $frameRef;
            $frame = $this->guarded($baseline, static fn (): AssistantTaskFrame => AssistantTaskFrame::fromSegment($proposal, $segments, $currentRef, $conversation['mediaRefs']));
        }
        $summary = null;
        $covered = [];
        if (isset($request['summaryRef'])) {
            $summary = $this->project($request['summaryRef'], $baseline);
            $this->requireKind($summary, ['summary']);
            $covered = $summary->metadata()['coveredArtifactRefs'];
            if ($covered === [] || $covered !== array_slice($conversation['historyRefs'], 0, count($covered))) {
                throw new LogicException('context_summary_coverage_mismatch');
            }
            $dropped = array_map(static fn (string $ref): AssistantSafeContextSegment => $segments[$ref], $covered);
            if (AssistantTaskFrame::fieldKeys([$summary]) !== AssistantTaskFrame::fieldKeys($dropped)) {
                throw new LogicException('context_summary_binding_mismatch');
            }
        }
        $candidate = null;
        $assembler = new AssistantSafeContextAssembler(
            fn (): array => $this->fresh($baseline),
            static function (array $value) use (&$candidate): void {
                $candidate = AssistantContextSourceBinding::detached($value);
            },
        );
        $tokenizer = $this->trustedTokenizer;
        if ($tokenizer === null) {
            throw new LogicException('tokenizer_unavailable');
        }
        $counter = new AssistantContextTokenCounter($profile, fn (string $json, array $identity): mixed => $this->invoke($tokenizer, $json, $identity));
        $orderedRefs = [...$conversation['systemRefs'], ...$conversation['historyRefs'], $currentRef, ...$auxiliaryRefs];
        $ordered = array_map(static fn (string $ref): AssistantSafeContextSegment => $segments[$ref], $orderedRefs);
        $payload = $this->guarded($baseline, static fn (): array => $assembler->assemble($profile, $ordered, $frame, $baseline));
        $count = $this->guarded($baseline, static fn (): int => $counter->count($payload));
        if ($count > $profile->inputBudget()) {
            if ($summary === null) {
                throw new LogicException('context_input_budget_exceeded');
            }
            $system = array_map(static fn (string $ref): AssistantSafeContextSegment => $segments[$ref], $conversation['systemRefs']);
            $remainingRefs = [...array_slice($conversation['historyRefs'], count($covered)), $currentRef, ...$auxiliaryRefs];
            $remaining = array_map(static fn (string $ref): AssistantSafeContextSegment => $segments[$ref], $remainingRefs);
            $ordered = [...$system, $summary, ...$remaining];
            $payload = $this->guarded($baseline, static fn (): array => $assembler->assemble($profile, $ordered, $frame, $baseline));
            $count = $this->guarded($baseline, static fn (): int => $counter->count($payload));
            if ($count > $profile->inputBudget()) {
                throw new LogicException('context_input_budget_exceeded');
            }
        }
        if (!is_array($candidate)) {
            throw new LogicException('context_candidate_unavailable');
        }
        $this->publishCandidate($candidate, $payload, $profileRef, $profile, $baseline, $lineage);

        return [
            'status' => 'READY',
            'mode' => 'offline-synthetic',
            'transportAllowed' => false,
            'payload' => $payload,
            'tokenCount' => $count,
            'inputBudget' => $profile->inputBudget(),
            'frame' => $frame?->modelPayload(),
        ];
    }
}