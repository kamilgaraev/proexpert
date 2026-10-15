<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceIdentityQueryBuilder;
use PHPUnit\Framework\TestCase;

final class AssistantSourceIdentityQueryBuilderTest extends TestCase
{
    public function test_large_identity_stays_out_of_bounded_candidates(): void
    {
        $counts = [
            'design_ifc_model_element' => ['design_additional' => 195756],
            'design_package_section' => ['design_additional' => 28],
            'design_model_derivative' => ['design_additional' => 15],
        ];

        self::assertSame(['design_model_derivative', 'design_package_section'], (new AssistantSourceIdentityQueryBuilder)->boundedIdentityTypes($counts, 'design_additional'));
    }

    public function test_existing_row_limit_applies_to_the_whole_materialized_subset(): void
    {
        $builder = new AssistantSourceIdentityQueryBuilder;
        $counts = ['section' => ['design_additional' => 6000], 'round' => ['design_additional' => 4000]];
        self::assertSame(['round', 'section'], $builder->boundedIdentityTypes($counts, 'design_additional'));
        $counts['round']['design_additional']++;
        self::assertSame([], $builder->boundedIdentityTypes($counts, 'design_additional'));
    }

    public function test_source_type_counts_are_not_combined_or_inferred(): void
    {
        $counts = [
            'section' => ['design_additional' => 3, 'other_source' => 20000],
            'unknown' => ['other_source' => 1],
            'empty' => ['design_additional' => 0],
        ];

        self::assertSame(['section'], (new AssistantSourceIdentityQueryBuilder)->boundedIdentityTypes($counts, 'design_additional'));
    }
}
