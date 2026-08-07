<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use LogicException;

final class ProjectPortfolioHealthOwnerCandidateSelector
{
    /**
     * @param list<array{
     *     classification:string,
     *     identity_key?:string,
     *     component?:ProjectPortfolioHealthSourceComponent,
     *     cohort?:string|null
     * }> $evaluated
     * @return array{
     *     component:ProjectPortfolioHealthSourceComponent|null,
     *     cohort:string|null,
     *     gap_code:string|null,
     *     identity_key:string|null
     * }
     */
    public function select(array $evaluated): array
    {
        $exact = [];
        $unresolvedIdentityKeys = [];
        $targetInvalid = false;
        foreach ($evaluated as $candidate) {
            $classification = $candidate['classification'] ?? null;
            if (! in_array($classification, ['exact', 'foreign', 'target_invalid', 'unresolved'], true)) {
                throw new LogicException('project_portfolio_health_owner_candidate_classification_invalid');
            }
            if ($classification === 'foreign') {
                continue;
            }
            if ($classification === 'target_invalid') {
                $targetInvalid = true;
                continue;
            }

            $identityKey = $candidate['identity_key'] ?? null;
            if (! is_string($identityKey) || trim($identityKey) === '') {
                throw new LogicException('project_portfolio_health_owner_candidate_identity_invalid');
            }
            if ($classification === 'unresolved') {
                $unresolvedIdentityKeys[$identityKey] = true;
                continue;
            }
            if (! ($candidate['component'] ?? null) instanceof ProjectPortfolioHealthSourceComponent) {
                throw new LogicException('project_portfolio_health_owner_candidate_invalid');
            }
            $exact[] = $candidate;
        }

        if ($exact === []) {
            return [
                'component' => null,
                'cohort' => null,
                'gap_code' => 'owner_source_integrity_invalid',
                'identity_key' => null,
            ];
        }
        foreach ($exact as $candidate) {
            if (isset($unresolvedIdentityKeys[$candidate['identity_key']])) {
                $targetInvalid = true;
                break;
            }
        }
        if ($targetInvalid) {
            return [
                'component' => null,
                'cohort' => null,
                'gap_code' => 'owner_source_integrity_invalid',
                'identity_key' => null,
            ];
        }
        if (count($exact) !== 1) {
            return [
                'component' => null,
                'cohort' => null,
                'gap_code' => 'owner_source_integrity_ambiguous',
                'identity_key' => null,
            ];
        }

        return [
            'component' => $exact[0]['component'],
            'cohort' => is_string($exact[0]['cohort'] ?? null) ? $exact[0]['cohort'] : null,
            'gap_code' => null,
            'identity_key' => $exact[0]['identity_key'],
        ];
    }

    /** @param list<string> $discoveredIds @param list<string> $storedIds */
    public function identitySetIsComplete(array $discoveredIds, array $storedIds): bool
    {
        $discovered = $this->identityIds($discoveredIds);
        $stored = $this->identityIds($storedIds);

        return $discovered !== null && $stored !== null && $discovered === $stored;
    }

    /** @param list<string> $ids @return list<string>|null */
    private function identityIds(array $ids): ?array
    {
        if (! array_is_list($ids)) {
            return null;
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (! is_string($id) || trim($id) === '') {
                return null;
            }
            $normalized[$id] = $id;
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }
}
