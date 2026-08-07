<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class ProjectPortfolioHealthSourceTupleAssembler
{
    public const REQUIRED_KINDS = [
        'project_margin',
        'budget_plan_fact',
        'wip_completion_forecast',
        'portfolio_liquidity',
    ];

    /** @param list<ProjectPortfolioHealthSourceComponent> $components @param list<array{code:string,kind?:string}> $reportedGaps */
    public function assemble(array $components, array $reportedGaps = []): ProjectPortfolioHealthSourceTuple
    {
        $byKind = [];
        $gaps = [];
        foreach ($components as $component) {
            if (! $component instanceof ProjectPortfolioHealthSourceComponent) {
                throw new InvalidArgumentException('project_portfolio_health_source_tuple_invalid');
            }
            $byKind[$component->kind][] = $component;
        }
        foreach (self::REQUIRED_KINDS as $kind) {
            if (! isset($byKind[$kind])) {
                $gaps[] = new ProjectPortfolioHealthSourceGap('mandatory_owner_source_missing', $kind);
            } elseif (count($byKind[$kind]) !== 1) {
                $gaps[] = new ProjectPortfolioHealthSourceGap('ambiguous_owner_source', $kind);
                unset($byKind[$kind]);
            }
        }
        foreach ($reportedGaps as $gap) {
            $kind = $gap['kind'] ?? 'portfolio_liquidity';
            if (! is_string($gap['code'] ?? null) || ! is_string($kind)) {
                throw new InvalidArgumentException('project_portfolio_health_source_gap_invalid');
            }
            $gaps[] = new ProjectPortfolioHealthSourceGap($gap['code'], $kind);
        }

        $gapKeys = [];
        foreach ($gaps as $gap) {
            $gapKeys[$gap->code."\0".$gap->kind] = $gap;
        }
        $gaps = array_values($gapKeys);
        ksort($byKind, SORT_STRING);
        usort($gaps, static fn (ProjectPortfolioHealthSourceGap $left, ProjectPortfolioHealthSourceGap $right): int => $left->code <=> $right->code ?: $left->kind <=> $right->kind);
        $tuple = new ProjectPortfolioHealthSourceTuple(
            array_map(static fn (array $candidates): ProjectPortfolioHealthSourceComponent => $candidates[0], array_values($byKind)),
            $gaps,
            '',
        );

        return new ProjectPortfolioHealthSourceTuple(
            $tuple->components,
            $tuple->gaps,
            hash('sha256', CanonicalJson::encode($tuple->canonicalIdentity())),
        );
    }
}
