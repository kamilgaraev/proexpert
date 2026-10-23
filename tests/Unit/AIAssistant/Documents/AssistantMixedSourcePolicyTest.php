<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantMixedSourcePolicyTest extends TestCase
{
    public function test_mixed_policy_binds_existing_projection_versions_to_exactly_one_sql_and_one_document_tool(): void
    {
        foreach (OrdinaryAssistantSourceProjection::TOOLS as $sql) {
            $sources = $this->sources($sql);
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::MIXED_VERSION, $sources));
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::MIXED_VERSION, array_reverse($sources)));
            self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::VERSION, $sources));
            self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantDocumentProjection::VERSION, $sources));
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::VERSION, [$sources[0]]));
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantDocumentProjection::VERSION, [$sources[1]]));
        }
    }

    public static function invalidSources(): iterable
    {
        yield 'no sources' => [[]];
        yield 'only SQL' => [[['tool' => 'get_project_attention', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION]]];
        yield 'only document' => [[['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION]]];
        yield 'two SQL' => [[['tool' => 'get_project_attention', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION],
            ['tool' => 'get_project_production_schedule_summary', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION]]];
        yield 'two documents' => [[['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION],
            ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION]]];
        foreach (['unknown', OrdinaryAssistantSourceProjection::MIXED_VERSION, OrdinaryAssistantDocumentProjection::VERSION] as $wrong) {
            yield 'SQL marker '.$wrong => [[['tool' => 'get_project_attention', 'projection_version' => $wrong],
                ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION]]];
        }
        yield 'unknown tool' => [[['tool' => 'get_live_project_financial_evidence', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION],
            ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION]]];
        yield 'malformed' => [[null, 'get_project_attention']];
    }

    #[DataProvider('invalidSources')]
    public function test_mixed_policy_does_not_accept_markers_or_partial_or_duplicate_source_sets(array $sources): void
    {
        self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::MIXED_VERSION, $sources));
    }

    public function test_unknown_and_oversized_tool_sets_do_not_grant_a_mixed_cohort(): void
    {
        self::assertFalse(OrdinaryAssistantSourceProjection::allowsTools('unknown', ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL]));
        self::assertFalse(OrdinaryAssistantSourceProjection::allowsTools(OrdinaryAssistantSourceProjection::MIXED_VERSION,
            [...OrdinaryAssistantSourceProjection::TOOLS, OrdinaryAssistantDocumentProjection::TOOL]));
        self::assertFalse(OrdinaryAssistantSourceProjection::allowsTools(OrdinaryAssistantSourceProjection::MIXED_VERSION, [[], []]));
    }

    private function sources(string $sql): array
    {
        return [['tool' => $sql, 'projection_version' => OrdinaryAssistantSourceProjection::VERSION],
            ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => OrdinaryAssistantDocumentProjection::VERSION]];
    }
}
