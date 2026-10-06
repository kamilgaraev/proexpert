<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use LogicException;

final readonly class MaterialSearchResult
{
    private function __construct(private array $localEnvelope, private bool $hasMore)
    {
    }

    public static function blocked(string $toolKind, string $reason): self
    {
        self::validateToolKind($toolKind);

        if (!in_array($reason, ['dependency_unavailable', 'access_denied', 'privacy_not_ready', 'source_stale'], true)) {
            throw new LogicException('invalid_material_result_reason');
        }

        $scope = ['kind' => $toolKind === 'search' ? 'search_subset' : 'selected_entity',
            'scopeRef' => MaterialSearchRecord::opaqueRef(),
            'sourceGenerationRef' => MaterialSearchRecord::opaqueRef(), 'unitRefs' => []];

        return new self(self::envelope($toolKind, 'blocked', $reason, [],
            ['status' => 'unknown', 'claimScope' => $scope, 'inspectedUnits' => 0,
                'totalUnits' => null, 'omittedUnitRefs' => []], [],
            MaterialSearchRecord::opaqueRef(), 'most-ai-local-material-search/1'), false);
    }

    public static function fromRecords(
        string $toolKind,
        SyntheticMaterialSearchCorpus $corpus,
        array $selected,
        array $omitted,
        AuthenticatedPrivateContext $context,
    ): self {
        self::validateToolKind($toolKind);
        $reason = $corpus->guard($context);

        if ($reason !== null) {
            return self::blocked($toolKind, $reason);
        }

        $financeAllowed = $corpus->priceAllowed($context);
        $seen = [];

        foreach (array_merge($selected, $omitted) as $record) {
            if (!$record instanceof MaterialSearchRecord || !in_array($record, $corpus->records(), true)
                || isset($seen[$record->ref])) {
                throw new LogicException('unregistered_material_evidence');
            }

            if (!$corpus->recordAllowed($context, $record->ref)) {
                return self::blocked($toolKind, 'access_denied');
            }

            $seen[$record->ref] = true;
        }

        $refs = [];
        $facts = [];
        $incomplete = false;

        foreach ($selected as $record) {
            $refs[] = $record->ref;
            $facts = array_merge($facts, $record->facts($financeAllowed));
            $incomplete = $incomplete || !$financeAllowed || !$record->hasAuthoritativePrice();
        }

        $omittedRefs = array_map(static fn (MaterialSearchRecord $row): string => $row->ref, $omitted);
        $hasMore = $omittedRefs !== [];
        $scope = $corpus->scope($toolKind === 'search' ? 'search_subset' : 'selected_entity', $refs);
        $partial = $hasMore || $incomplete;
        $status = $facts === [] ? 'no_data' : ($partial ? 'partial' : 'verified');
        $coverage = ['status' => $partial ? 'partial' : 'complete', 'claimScope' => $scope,
            'inspectedUnits' => count($refs), 'totalUnits' => count($refs) + count($omittedRefs),
            'omittedUnitRefs' => $omittedRefs];

        return new self(self::envelope($toolKind, $status,
            $status === 'no_data' ? 'no_evidence' : ($partial ? 'scope_limited' : 'none'),
            $facts, $coverage, array_merge($refs, $omittedRefs),
            $corpus->profileRef(), $corpus->profileVersion()), $hasMore);
    }

    private static function envelope(
        string $toolKind,
        string $status,
        string $reason,
        array $facts,
        array $coverage,
        array $refs,
        string $profileRef,
        string $profileVersion,
    ): array {
        return ['schemaVersion' => 'safe-tool-result/1', 'requestRef' => MaterialSearchRecord::opaqueRef(),
            'profileRef' => $profileRef, 'profileVersion' => $profileVersion, 'toolKind' => $toolKind,
            'status' => $status, 'reason' => $reason, 'facts' => $facts, 'coverage' => $coverage,
            'nextSafeRefs' => $refs, 'resultGenerationRef' => $coverage['claimScope']['sourceGenerationRef']];
    }

    public function localEnvelope(): array
    {
        return $this->localEnvelope;
    }

    private static function validateToolKind(string $kind): void
    {
        if (!in_array($kind, ['search', 'read_selected'], true)) {
            throw new LogicException('invalid_material_tool_kind');
        }
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    public function __serialize(): array
    {
        throw new LogicException('local_unsealed_material_result_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('local_unsealed_material_result_deserialization_forbidden');
    }
}
