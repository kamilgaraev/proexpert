<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactConfidence;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
use Closure;

final class CanonicalDocumentFactPresenter
{
    private readonly ?Closure $translator;

    public function __construct(?callable $translator = null)
    {
        $this->translator = $translator === null ? null : Closure::fromCallable($translator);
    }

    public function present(array $payload, int $pageNumber): array
    {
        $claims = $this->claims($payload);
        $groups = $this->decisionGroups($payload);
        $roomNames = $this->roomNames($claims);
        $areaEntities = [];
        foreach ($groups as $group) {
            $canonical = $group['canonical'];
            if (($canonical['fact_type'] ?? null) === 'area') {
                $areaEntities[$this->entity((string) ($canonical['entity_key'] ?? ''))] = true;
            }
        }

        $facts = [];
        foreach ($groups as $semanticKey => $group) {
            $canonical = $group['canonical'];
            $factType = is_string($canonical['fact_type'] ?? null) ? $canonical['fact_type'] : '';
            $entityKey = $this->preferredEntity($canonical, $group['supporting_claim_ids'], $claims);
            if ($factType === 'room' && isset($areaEntities[$this->entity($entityKey)])) {
                continue;
            }
            $lineage = $this->lineage($group['supporting_claim_ids'], $claims);
            $evidenceRefs = array_values(array_unique(array_filter([
                ...$group['evidence_refs'],
                ...array_column($lineage, 'evidence_ref'),
            ], 'is_string')));
            sort($evidenceRefs, SORT_STRING);
            $canonical['entity_key'] = $entityKey;
            if (in_array($factType, ['level', 'elevation'], true)
                && ! is_string($canonical['unit'] ?? null)) {
                $canonical['unit'] = 'm';
            }
            $label = $this->label($canonical, $roomNames);
            if ($label === null) {
                continue;
            }
            $facts[] = [
                'canonical_id' => 'sha256:'.hash('sha256', $semanticKey),
                'entity_key' => $entityKey,
                'fact_type' => $factType,
                'label' => $label,
                'value' => is_array($canonical['value'] ?? null) ? $canonical['value'] : ['type' => 'unknown', 'data' => null],
                'unit' => is_string($canonical['unit'] ?? null) ? $this->displayUnit($canonical['unit']) : null,
                'confidence' => (new CanonicalFactConfidence)->forLineage($lineage, $group['status'] === 'accepted'),
                'lineage' => $lineage,
                'source' => [
                    'page_number' => $pageNumber,
                    'evidence_refs' => $evidenceRefs,
                ],
            ];
        }
        usort($facts, static fn (array $left, array $right): int => $left['canonical_id'] <=> $right['canonical_id']);

        return $facts;
    }

    private function claims(array $payload): array
    {
        $result = [];
        $observers = is_array($payload['independent_observations'] ?? null)
            ? $payload['independent_observations']
            : [];
        foreach ($observers as $role => $observer) {
            if (! is_string($role) || ! is_array($observer) || ! is_array($observer['claims'] ?? null)) {
                continue;
            }
            $shortRole = str_replace('observer_', '', $role);
            foreach ($observer['claims'] as $index => $claim) {
                if (! is_array($claim) || ! is_string($claim['entityKey'] ?? null)
                    || ! is_string($claim['factType'] ?? null) || ! is_array($claim['value'] ?? null)) {
                    continue;
                }
                $id = is_string($claim['id'] ?? null) ? $claim['id'] : $shortRole.':'.($index + 1);
                $result[$id] = [
                    'claim_id' => $id,
                    'role' => $role,
                    'entity_key' => $claim['entityKey'],
                    'fact_type' => $claim['factType'],
                    'value' => $claim['value'],
                    'unit' => is_string($claim['unit'] ?? null) ? $claim['unit'] : null,
                    'confidence' => is_numeric($claim['confidence'] ?? null) ? (float) $claim['confidence'] : 0.0,
                    'evidence_ref' => is_string($claim['evidenceRef'] ?? null) ? $claim['evidenceRef'] : null,
                ];
            }
        }

        return $result;
    }

    private function decisionGroups(array $payload): array
    {
        $arbitration = is_array($payload['document_arbitration'] ?? null) ? $payload['document_arbitration'] : [];
        $decisions = is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [];
        $groups = [];
        $matcher = new ClaimSemanticMatcher;
        foreach (array_slice($decisions, 0, 192) as $decision) {
            $canonical = is_array($decision) && is_array($decision['canonical_claim'] ?? null)
                ? $decision['canonical_claim']
                : null;
            $status = is_array($decision) && is_string($decision['status'] ?? null) ? $decision['status'] : null;
            if ($canonical === null || ! in_array($status, ['accepted', 'candidate'], true)) {
                continue;
            }
            $key = $matcher->keyForCanonical($canonical);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'canonical' => $canonical,
                    'status' => $status,
                    'supporting_claim_ids' => [],
                    'evidence_refs' => [],
                ];
            }
            if ($status === 'accepted') {
                $groups[$key]['status'] = 'accepted';
            }
            $claimId = is_string($decision['claim_id'] ?? null) ? $decision['claim_id'] : null;
            $supporting = is_array($decision['supporting_claim_ids'] ?? null) ? $decision['supporting_claim_ids'] : [];
            $evidence = is_array($decision['evidence_refs'] ?? null) ? $decision['evidence_refs'] : [];
            $groups[$key]['supporting_claim_ids'] = array_values(array_unique(array_filter([
                ...$groups[$key]['supporting_claim_ids'],
                $claimId,
                ...$supporting,
            ], 'is_string')));
            $groups[$key]['evidence_refs'] = array_values(array_unique(array_filter([
                ...$groups[$key]['evidence_refs'],
                ...$evidence,
            ], 'is_string')));
        }
        $groups = $this->coalesceDecisionGroups($groups, $matcher);
        ksort($groups, SORT_STRING);

        return $groups;
    }

    private function coalesceDecisionGroups(array $groups, ClaimSemanticMatcher $matcher): array
    {
        ksort($groups, SORT_STRING);
        $result = [];
        $metadata = [];
        foreach ($groups as $key => $group) {
            if (($group['status'] ?? null) !== 'accepted') {
                $result[$key] = $group;

                continue;
            }
            $signature = $matcher->factSignatureForCanonical($group['canonical']);
            $support = array_fill_keys($group['supporting_claim_ids'], true);
            $target = null;
            foreach ($metadata as $candidateKey => $candidate) {
                if ($candidate['signature'] === $signature
                    && array_intersect_key($candidate['support'], $support) !== []) {
                    $target = $candidateKey;
                    break;
                }
            }
            if ($target === null) {
                $result[$key] = $group;
                $metadata[$key] = ['signature' => $signature, 'support' => $support];

                continue;
            }
            $result[$target]['supporting_claim_ids'] = array_values(array_unique([
                ...$result[$target]['supporting_claim_ids'],
                ...$group['supporting_claim_ids'],
            ]));
            $result[$target]['evidence_refs'] = array_values(array_unique([
                ...$result[$target]['evidence_refs'],
                ...$group['evidence_refs'],
            ]));
            $metadata[$target]['support'] += $support;
        }

        return $result;
    }

    private function lineage(array $claimIds, array $claims): array
    {
        $lineage = [];
        $claimIds = array_values(array_unique(array_filter($claimIds, 'is_string')));
        sort($claimIds, SORT_STRING);
        foreach (array_slice($claimIds, 0, 16) as $claimId) {
            $claim = $claims[$claimId] ?? null;
            if (! is_array($claim)) {
                continue;
            }
            $lineage[] = [
                'claim_id' => $claimId,
                'role' => $claim['role'],
                'confidence' => $claim['confidence'],
                'evidence_ref' => $claim['evidence_ref'],
            ];
        }

        return $lineage;
    }

    private function roomNames(array $claims): array
    {
        $names = [];
        foreach ($claims as $claim) {
            $value = $claim['value']['data'] ?? null;
            if (($claim['fact_type'] ?? null) === 'room' && is_string($value) && trim($value) !== '') {
                $names[$this->entity((string) $claim['entity_key'])] = trim($value);
            }
        }

        return $names;
    }

    private function preferredEntity(array $canonical, array $supportingClaimIds, array $claims): string
    {
        $entities = [is_string($canonical['entity_key'] ?? null) ? $canonical['entity_key'] : ''];
        foreach ($supportingClaimIds as $claimId) {
            if (is_string($claimId) && is_string($claims[$claimId]['entity_key'] ?? null)) {
                $entities[] = $claims[$claimId]['entity_key'];
            }
        }
        usort($entities, fn (string $left, string $right): int => $this->entityScore($right) <=> $this->entityScore($left) ?: $left <=> $right);

        return $entities[0] ?? '';
    }

    private function entityScore(string $entity): int
    {
        $normalized = $this->entity($entity);

        return match (true) {
            str_contains($normalized, 'overallwidth'), str_contains($normalized, 'overallheight') => 50,
            str_contains($normalized, 'gridspan') => 40,
            str_starts_with($normalized, 'room'), str_contains($normalized, 'externalwall') => 30,
            str_contains($normalized, 'level') => 20,
            default => 10,
        };
    }

    private function label(array $canonical, array $roomNames): ?string
    {
        $factType = is_string($canonical['fact_type'] ?? null) ? $canonical['fact_type'] : '';
        $entityKey = is_string($canonical['entity_key'] ?? null) ? $canonical['entity_key'] : '';
        $value = is_array($canonical['value'] ?? null) ? $canonical['value'] : [];
        $data = $value['data'] ?? null;
        if (! is_scalar($data) || $data === '') {
            return null;
        }
        $rendered = $this->value($value, $factType);
        $unit = is_string($canonical['unit'] ?? null) ? $this->displayUnit($canonical['unit']) : null;
        $renderedWithUnit = $this->withUnit($rendered, $unit);
        $entity = $this->entity($entityKey);

        if ($factType === 'area') {
            $roomName = $roomNames[$entity] ?? null;
            $prefix = $roomName ?? (str_contains($entity, 'totalarea') || str_contains($entity, 'areatotal')
                ? $this->translate('estimate_generation.canonical_result.total_area')
                : $this->translate('estimate_generation.canonical_result.unknown_dimension'));

            return $prefix.' — '.$renderedWithUnit;
        }
        if ($factType === 'dimension_chain') {
            $prefix = match (true) {
                str_contains($entity, 'overallwidth') => $this->translate('estimate_generation.canonical_result.overall_width'),
                str_contains($entity, 'overallheight') => $this->translate('estimate_generation.canonical_result.overall_height'),
                str_contains($entity, 'gridspan') => str_replace(':axes', $this->axes($entityKey), $this->translate('estimate_generation.canonical_result.grid_span')),
                default => $this->translate('estimate_generation.canonical_result.unknown_dimension'),
            };

            return $prefix.' — '.$renderedWithUnit;
        }
        if (in_array($factType, ['level', 'elevation'], true)) {
            return $this->translate('estimate_generation.canonical_result.elevation').' — '.$renderedWithUnit;
        }
        if ($factType === 'floor_count') {
            $count = (int) $data;

            return $this->translate('estimate_generation.canonical_result.floor_count').' — '.$count.' '.$this->floorWord($count);
        }
        if ($factType === 'wall') {
            return $this->withUnit((string) $data, $unit);
        }
        $prefix = match ($factType) {
            'material' => $this->translate('estimate_generation.canonical_result.material'),
            'technology_candidate', 'roof_geometry' => $this->translate('estimate_generation.canonical_result.work_scope'),
            default => $this->translate('estimate_generation.canonical_result.fact'),
        };

        return $prefix.' — '.$renderedWithUnit;
    }

    private function value(array $value, string $factType): string
    {
        $data = $value['data'] ?? null;
        if (($value['type'] ?? null) !== 'number' || ! is_numeric($data)) {
            return is_bool($data)
                ? $this->translate('estimate_generation.canonical_result.'.($data ? 'yes' : 'no'))
                : (string) $data;
        }
        $source = (string) $data;
        $decimals = $factType === 'area'
            ? 2
            : (str_contains($source, '.') ? strlen(rtrim(substr(strrchr($source, '.'), 1), '0')) : 0);

        return number_format((float) $source, $decimals, ',', ' ');
    }

    private function withUnit(string $value, ?string $unit): string
    {
        if ($unit === null || $unit === '' || preg_match('/(?:^|\s)'.preg_quote($unit, '/').'$/iu', trim($value)) === 1) {
            return trim($value);
        }

        return trim($value).' '.$unit;
    }

    private function displayUnit(string $unit): string
    {
        return match (mb_strtolower(trim($unit))) {
            'm2', 'm²', 'м2', 'м²' => 'м²',
            'm3', 'm³', 'м3', 'м³' => 'м³',
            'mm', 'мм' => 'мм',
            'cm', 'см' => 'см',
            'm', 'м' => 'м',
            default => trim($unit),
        };
    }

    private function axes(string $entityKey): string
    {
        if (preg_match('/grid[._:-]?span[._:-]([a-zа-я0-9]+)[._:-]([a-zа-я0-9]+)/iu', $entityKey, $match) !== 1) {
            return $this->translate('estimate_generation.canonical_result.axes_unknown');
        }
        $map = ['a' => 'А', 'b' => 'Б', 'v' => 'В', 'g' => 'Г', 'd' => 'Д'];
        $from = $map[mb_strtolower($match[1])] ?? mb_strtoupper($match[1]);
        $to = $map[mb_strtolower($match[2])] ?? mb_strtoupper($match[2]);

        return $from.'–'.$to;
    }

    private function floorWord(int $count): string
    {
        $lastTwo = $count % 100;
        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return $this->translate('estimate_generation.canonical_result.floor_many');
        }

        return match ($count % 10) {
            1 => $this->translate('estimate_generation.canonical_result.floor_one'),
            2, 3, 4 => $this->translate('estimate_generation.canonical_result.floor_few'),
            default => $this->translate('estimate_generation.canonical_result.floor_many'),
        };
    }

    private function entity(string $entity): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($entity)) ?? mb_strtolower($entity);
    }

    private function translate(string $key): string
    {
        return $this->translator === null ? trans_message($key) : ($this->translator)($key);
    }
}
