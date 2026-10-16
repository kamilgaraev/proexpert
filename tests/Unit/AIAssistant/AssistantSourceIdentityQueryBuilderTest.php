<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceIdentityQueryBuilder;
use PHPUnit\Framework\TestCase;

final class AssistantSourceIdentityQueryBuilderTest extends TestCase
{
    public function test_document_visibility_has_its_own_counter_batch_without_displacing_other_types(): void
    {
        $types = ['time_tracking', 'contract', 'knowledge', 'performance_act', 'catalog_measurement_units',
            'construction_journal', 'project_pulse', 'file_document', 'catalog_work_types'];
        $identities = [];
        foreach ($types as $type) { $identities[$type] = [$type => true]; }
        $counts = array_fill_keys($types, 20);
        $counts['file_document'] = 2;
        $expected = [
            ['types' => ['file_document'], 'entity' => null],
            ['types' => array_values(array_diff($types, ['file_document'])), 'entity' => null],
        ];

        foreach ([false, true] as $expectedProjection) {
            self::assertSame($expected, (new AssistantSourceIdentityQueryBuilder)->planBatches($identities, $counts, 8, $expectedProjection));
        }
    }

    public function test_document_only_batch_does_not_add_an_empty_counter_batch(): void
    {
        self::assertSame([['types' => ['file_document'], 'entity' => null]],
            (new AssistantSourceIdentityQueryBuilder)->planBatches(['assistant_document' => ['file_document' => true]], ['file_document' => 2], 8, false));
    }

    public function test_unbatched_identity_queries_keep_the_existing_shape(): void
    {
        self::assertSame([['types' => null, 'entity' => null]], (new AssistantSourceIdentityQueryBuilder)->planBatches(
            ['assistant_document' => ['file_document' => true], 'contract' => ['contract' => true]],
            ['file_document' => 2, 'contract' => 20], null, false));
    }

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
