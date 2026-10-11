<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;
use PHPUnit\Framework\TestCase;

final class AssistantIndexSourceAliasesTest extends TestCase
{
    public function test_aliases_keep_the_current_canonical_source_and_are_limited_to_four_indexed_entities(): void
    {
        $aliases = [
            'estimate_template' => ['core_business', 'estimate_reference'],
            'estimate_library_item' => ['core_business', 'estimate_reference'],
            'normative_rate' => ['core_business_money', 'estimate_reference'],
            'construction_journal_entry' => ['core_business', 'construction_journal'],
        ];
        foreach (AssistantEntityAccessCatalog::entityDefinitions() as $type => [$canonical]) {
            self::assertSame($aliases[$type] ?? [$canonical], AssistantEntityAccessCatalog::indexedSourceTypes($type, $canonical), $type);
        }
        self::assertSame(['unknown_source'], AssistantEntityAccessCatalog::indexedSourceTypes('unknown_entity', 'unknown_source'));
        self::assertSame(['estimate_reference'], AssistantEntityAccessCatalog::indexedSourceTypes('estimate_template', 'estimate_reference'));
    }
}
