<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use InvalidArgumentException;

final readonly class CrossDocumentFactLinker
{
    private const STRATEGY_VERSION = 1;

    private const MAX_EVIDENCE_PER_FACT = 20;

    public function __construct(
        private TargetedConflictResolver $conflictResolver,
        private ?CrossDocumentFactArbitrator $arbitrator = null,
        private int $maxCandidatesPerFact = 20,
    ) {
        if ($maxCandidatesPerFact < 1 || $maxCandidatesPerFact > 100) {
            throw new InvalidArgumentException('Cross-document candidate limit is invalid.');
        }
    }

    public function link(array $entities, array $facts, array $evidence): ProjectUnderstandingResult
    {
        [$entityById, $factList, $evidenceById] = $this->validate($entities, $facts, $evidence);
        $groups = [];
        foreach ($factList as $fact) {
            if ($fact->status === 'invalidated' || $fact->origin === 'ai_technology_recommendation') {
                continue;
            }
            $descriptor = $this->descriptor($entityById[$fact->entityId]);
            if ($descriptor === null) {
                continue;
            }
            $groupKey = $descriptor['strategy'].'|'.$descriptor['match_key'].'|'.$fact->type;
            $groups[$groupKey][$descriptor['side']][] = $fact;
        }
        ksort($groups, SORT_STRING);

        $links = [];
        $conflicts = [];
        $questions = [];
        $limitations = [];
        $providerCalls = 0;
        foreach ($groups as $groupKey => $group) {
            $left = $group['left'] ?? [];
            $right = $group['right'] ?? [];
            if ($left === [] || $right === []) {
                continue;
            }
            usort($left, static fn (Fact $first, Fact $second): int => $first->id <=> $second->id);
            usort($right, static fn (Fact $first, Fact $second): int => $first->id <=> $second->id);
            if (count($left) > $this->maxCandidatesPerFact || count($right) > $this->maxCandidatesPerFact) {
                throw new InvalidArgumentException('Cross-document candidate limit exceeded.');
            }
            [$strategy, $matchKey] = explode('|', $groupKey, 3);
            foreach ($left as $subject) {
                if (count($left) === 1 && count($right) === 1) {
                    $this->deterministic(
                        $subject,
                        $right[0],
                        $strategy,
                        $matchKey,
                        $evidenceById,
                        $links,
                        $conflicts,
                        $questions,
                        $limitations,
                    );

                    continue;
                }
                if (! $this->allEvidenceAvailable([$subject, ...$right], $evidenceById)) {
                    $limitations[] = $this->conflictResolver->insufficientEvidence();

                    continue;
                }
                if ($this->arbitrator === null) {
                    $limitations[] = $this->conflictResolver->insufficientEvidence();

                    continue;
                }
                $operationIdentity = $this->operationIdentity($subject, $right, $strategy, $matchKey);
                $payload = $this->arbitrationPayload(
                    $operationIdentity,
                    $strategy,
                    $matchKey,
                    $subject,
                    $right,
                    $evidenceById,
                );
                $providerCalls++;
                $verdict = $this->arbitrator->arbitrate($operationIdentity, $payload, [
                    'organization_id' => $subject->organizationId,
                    'project_id' => $subject->projectId,
                    'session_id' => $subject->sessionId,
                    'source_version' => $subject->sourceVersion,
                ]);
                $selected = $this->selectedFact($verdict, $right);
                if ($selected === null) {
                    $limitations[] = $this->conflictResolver->insufficientEvidence();

                    continue;
                }
                if (! $this->compatible($subject, $selected)) {
                    $this->addConflict($subject, $selected, $evidenceById, $conflicts, $questions);

                    continue;
                }
                $links[$operationIdentity] = $this->linkData(
                    $subject,
                    $selected,
                    'ai_arbitration',
                    $matchKey,
                    'suggested',
                    $operationIdentity,
                    is_string($verdict['reason'] ?? null) ? $verdict['reason'] : 'ambiguous_match',
                );
            }
        }

        return new ProjectUnderstandingResult(
            array_values($links),
            array_values($conflicts),
            array_values($questions),
            $limitations,
            $providerCalls,
        );
    }

    private function validate(array $entities, array $facts, array $evidence): array
    {
        $scope = null;
        $entityById = [];
        foreach ($entities as $entity) {
            if (! $entity instanceof Entity) {
                throw new InvalidArgumentException('Cross-document entity input is invalid.');
            }
            $scope ??= [$entity->organizationId, $entity->projectId, $entity->sessionId, $entity->sourceVersion];
            $this->assertModelScope($entity, $scope);
            if (isset($entityById[$entity->id])) {
                throw new InvalidArgumentException('Cross-document entity is duplicated.');
            }
            $entityById[$entity->id] = $entity;
        }
        $factList = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Cross-document fact input is invalid.');
            }
            $scope ??= [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion];
            $this->assertModelScope($fact, $scope);
            if (! isset($entityById[$fact->entityId])) {
                throw new InvalidArgumentException('Cross-document fact entity is outside the requested scope.');
            }
            if (count($fact->evidenceIds) > self::MAX_EVIDENCE_PER_FACT) {
                throw new InvalidArgumentException('Cross-document evidence limit exceeded.');
            }
            $factList[$fact->id] = $fact;
        }
        ksort($factList, SORT_STRING);
        $evidenceById = [];
        foreach ($evidence as $item) {
            if (! $item instanceof Evidence) {
                throw new InvalidArgumentException('Cross-document evidence input is invalid.');
            }
            if ($scope !== null && [$item->organizationId, $item->projectId, $item->sessionId] !== array_slice($scope, 0, 3)) {
                throw new InvalidArgumentException('Cross-document evidence is outside the requested scope.');
            }
            if (isset($evidenceById[$item->id])) {
                throw new InvalidArgumentException('Cross-document evidence is duplicated.');
            }
            $evidenceById[$item->id] = $item;
        }

        return [$entityById, array_values($factList), $evidenceById];
    }

    private function assertModelScope(object $record, array $scope): void
    {
        if ([$record->organizationId, $record->projectId, $record->sessionId, $record->sourceVersion] !== $scope) {
            throw new InvalidArgumentException('Cross-document record is outside the requested scope.');
        }
    }

    private function descriptor(Entity $entity): ?array
    {
        $role = $this->token($entity->attributes['document_role'] ?? null);
        if ($role === null) {
            return null;
        }
        foreach ([
            ['strategy' => 'native_id', 'attribute' => 'native_id', 'types' => Entity::TYPES, 'left' => ['left', 'plan', 'equipment_plan', 'equipment_scheme', 'facade'], 'right' => ['right', 'room_schedule', 'explication', 'section', 'equipment_specification', 'finish_schedule'], 'side_attribute' => true],
            ['strategy' => 'stable_key', 'attribute' => 'cross_document_key', 'types' => Entity::TYPES, 'left' => ['left', 'plan', 'equipment_plan', 'equipment_scheme', 'facade'], 'right' => ['right', 'room_schedule', 'explication', 'section', 'equipment_specification', 'finish_schedule'], 'side_attribute' => true],
            ['strategy' => 'room_number', 'attribute' => 'room_number', 'types' => ['room'], 'left' => ['plan'], 'right' => ['room_schedule', 'explication']],
            ['strategy' => 'axes', 'attribute' => 'axes', 'types' => Entity::TYPES, 'left' => ['plan'], 'right' => ['section']],
            ['strategy' => 'equipment_position', 'attribute' => 'position', 'types' => ['equipment'], 'left' => ['equipment_plan', 'equipment_scheme', 'plan'], 'right' => ['equipment_specification']],
            ['strategy' => 'facade_material', 'attribute' => 'facade_zone', 'types' => ['material'], 'left' => ['facade'], 'right' => ['finish_schedule']],
        ] as $rule) {
            if (! in_array($entity->type, $rule['types'], true) || ! array_key_exists($rule['attribute'], $entity->attributes)) {
                continue;
            }
            $sideRole = ($rule['side_attribute'] ?? false)
                ? $this->token($entity->attributes['link_side'] ?? $role)
                : $role;
            $side = in_array($sideRole, $rule['left'], true)
                ? 'left'
                : (in_array($sideRole, $rule['right'], true) ? 'right' : null);
            $matchKey = $this->matchKey($entity->attributes[$rule['attribute']]);
            if ($side !== null && $matchKey !== null) {
                return ['strategy' => $rule['strategy'], 'match_key' => $matchKey, 'side' => $side];
            }
        }

        return null;
    }

    private function deterministic(
        Fact $left,
        Fact $right,
        string $strategy,
        string $matchKey,
        array $evidenceById,
        array &$links,
        array &$conflicts,
        array &$questions,
        array &$limitations,
    ): void {
        if (! $this->allEvidenceAvailable([$left, $right], $evidenceById)) {
            $limitations[] = $this->conflictResolver->insufficientEvidence();

            return;
        }
        if (! $this->compatible($left, $right)) {
            $this->addConflict($left, $right, $evidenceById, $conflicts, $questions);

            return;
        }
        $operationIdentity = $this->operationIdentity($left, [$right], $strategy, $matchKey);
        $links[$operationIdentity] = $this->linkData(
            $left,
            $right,
            $strategy,
            $matchKey,
            'linked',
            $operationIdentity,
            'deterministic_exact_match',
        );
    }

    private function addConflict(
        Fact $left,
        Fact $right,
        array $evidenceById,
        array &$conflicts,
        array &$questions,
    ): void {
        $identity = hash('sha256', implode('|', [$left->id, $right->id, $left->sourceVersion, 'cross-document-conflict:v1']));
        $conflict = Conflict::between('conflict:cross-document:'.substr($identity, 0, 48), [$left, $right], 'cross_document_incompatible_values');
        $conflicts[$conflict->id] = $conflict;
        $questions[$conflict->id] = $this->conflictResolver->question($conflict, $evidenceById);
    }

    private function allEvidenceAvailable(array $facts, array $evidenceById): bool
    {
        foreach ($facts as $fact) {
            if ($fact->evidenceIds === []) {
                return false;
            }
            foreach ($fact->evidenceIds as $evidenceId) {
                if (! isset($evidenceById[$evidenceId])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function compatible(Fact $left, Fact $right): bool
    {
        return hash_equals($this->valueFingerprint($left), $this->valueFingerprint($right));
    }

    private function valueFingerprint(Fact $fact): string
    {
        return hash('sha256', json_encode(
            [$fact->type, $fact->value, $fact->unit],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function operationIdentity(Fact $subject, array $candidates, string $strategy, string $matchKey): string
    {
        $candidateIds = array_map(static fn (Fact $fact): string => $fact->id, $candidates);
        sort($candidateIds, SORT_STRING);

        return hash('sha256', implode('|', [
            $subject->organizationId,
            $subject->projectId,
            $subject->sessionId,
            $subject->sourceVersion,
            $strategy,
            $matchKey,
            $subject->id,
            ...$candidateIds,
            'cross-document-link:v'.self::STRATEGY_VERSION,
        ]));
    }

    private function arbitrationPayload(
        string $operationIdentity,
        string $strategy,
        string $matchKey,
        Fact $subject,
        array $candidates,
        array $evidenceById,
    ): array {
        return [
            'operation_identity' => $operationIdentity,
            'strategy' => $strategy,
            'match_key' => $matchKey,
            'source_version' => $subject->sourceVersion,
            'subject' => $this->scopedFact($subject, $evidenceById),
            'candidates' => array_map(fn (Fact $fact): array => $this->scopedFact($fact, $evidenceById), $candidates),
        ];
    }

    private function scopedFact(Fact $fact, array $evidenceById): array
    {
        return [
            'fact' => [
                'id' => $fact->id,
                'type' => $fact->type,
                'value' => $fact->value,
                'unit' => $fact->unit,
                'confidence' => $fact->confidence,
                'origin' => $fact->origin,
                'status' => $fact->status,
            ],
            'evidence' => array_map(static function (string $id) use ($evidenceById): array {
                $item = $evidenceById[$id];

                return [
                    'id' => $item->id,
                    'source_artifact_id' => $item->sourceArtifactId,
                    'source_type' => $item->sourceType,
                    'source_version' => $item->sourceVersion,
                    'page' => $item->page,
                    'region' => $item->region,
                    'native_reference' => $item->nativeReference,
                ];
            }, $fact->evidenceIds),
        ];
    }

    private function selectedFact(array $verdict, array $candidates): ?Fact
    {
        if (($verdict['status'] ?? null) !== 'suggested' || ! is_string($verdict['selected_fact_id'] ?? null)) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if (hash_equals($candidate->id, $verdict['selected_fact_id'])) {
                return $candidate;
            }
        }

        return null;
    }

    private function linkData(
        Fact $left,
        Fact $right,
        string $strategy,
        string $matchKey,
        string $status,
        string $operationIdentity,
        string $reason,
    ): array {
        return [
            'id' => 'link:'.substr($operationIdentity, 0, 48),
            'organization_id' => $left->organizationId,
            'project_id' => $left->projectId,
            'session_id' => $left->sessionId,
            'source_version' => $left->sourceVersion,
            'left_fact_id' => $left->id,
            'right_fact_id' => $right->id,
            'strategy' => $strategy,
            'match_key' => $matchKey,
            'reason' => $reason,
            'strategy_version' => self::STRATEGY_VERSION,
            'operation_identity' => $operationIdentity,
            'status' => $status,
            'evidence' => ['left' => $left->evidenceIds, 'right' => $right->evidenceIds],
        ];
    }

    private function matchKey(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $tokens = array_values(array_filter(array_map($this->token(...), $value)));
            sort($tokens, SORT_STRING);

            return $tokens === [] ? null : implode(':', $tokens);
        }

        return $this->token($value);
    }

    private function token(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $token = mb_strtolower(trim((string) $value));

        return preg_match('/^[\pL\pN._:-]{1,191}$/uD', $token) === 1 ? $token : null;
    }
}
