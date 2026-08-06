<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOwnerCandidateSelector;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceComponent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioHealthOwnerCandidateSelectorTest extends TestCase
{
    #[Test]
    public function a_foreign_narrowed_snapshot_does_not_poison_the_exact_candidate(): void
    {
        $exact = new ProjectPortfolioHealthSourceComponent(
            'project_margin',
            '01K1EXACT00000000000000000',
            str_repeat('1', 64),
            'project_margin_core.v1|project_margin_source_v1',
            '2026-08-04T00:00:00+00:00',
        );

        $selection = (new ProjectPortfolioHealthOwnerCandidateSelector)->select([
            [
                'classification' => 'exact',
                'identity_key' => 'definition-a:query-a',
                'component' => $exact,
                'cohort' => str_repeat('2', 64),
            ],
            ['classification' => 'unresolved', 'identity_key' => 'definition-b:query-b'],
        ]);

        self::assertSame($exact, $selection['component']);
        self::assertSame(str_repeat('2', 64), $selection['cohort']);
        self::assertNull($selection['gap_code']);
    }

    #[Test]
    public function a_damaged_candidate_with_the_exact_identity_fails_closed(): void
    {
        $exact = new ProjectPortfolioHealthSourceComponent(
            'project_margin',
            '01K1EXACT00000000000000000',
            str_repeat('1', 64),
            'project_margin_core.v1|project_margin_source_v1',
            '2026-08-04T00:00:00+00:00',
        );

        $selection = (new ProjectPortfolioHealthOwnerCandidateSelector)->select([
            [
                'classification' => 'exact',
                'identity_key' => 'definition-a:query-a',
                'component' => $exact,
            ],
            ['classification' => 'unresolved', 'identity_key' => 'definition-a:query-a'],
        ]);

        self::assertNull($selection['component']);
        self::assertSame('owner_source_integrity_invalid', $selection['gap_code']);
    }

    #[Test]
    public function multiple_exact_candidates_fail_closed(): void
    {
        $first = new ProjectPortfolioHealthSourceComponent(
            'project_margin',
            '01K1FIRST00000000000000000',
            str_repeat('1', 64),
            'project_margin_core.v1|project_margin_source_v1',
            '2026-08-04T00:00:00+00:00',
        );
        $second = new ProjectPortfolioHealthSourceComponent(
            'project_margin',
            '01K1SECOND0000000000000000',
            str_repeat('2', 64),
            'project_margin_core.v1|project_margin_source_v1',
            '2026-08-04T00:00:00+00:00',
        );

        $selection = (new ProjectPortfolioHealthOwnerCandidateSelector)->select([
            ['classification' => 'exact', 'identity_key' => 'definition-a:query-a', 'component' => $first],
            ['classification' => 'exact', 'identity_key' => 'definition-b:query-b', 'component' => $second],
        ]);

        self::assertNull($selection['component']);
        self::assertSame('owner_source_integrity_ambiguous', $selection['gap_code']);
    }

    #[Test]
    public function an_identity_record_filtered_from_discovery_fails_completeness(): void
    {
        $selector = new ProjectPortfolioHealthOwnerCandidateSelector;

        self::assertTrue($selector->identitySetIsComplete(['valid'], ['valid']));
        self::assertFalse($selector->identitySetIsComplete(['valid'], ['corrupt', 'valid']));
    }
}
