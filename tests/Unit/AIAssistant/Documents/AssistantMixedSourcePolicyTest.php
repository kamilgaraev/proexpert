<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantMixedSourcePolicyTest extends TestCase
{
    public function test_three_source_policy_requires_both_existing_sql_tools_and_one_document_with_unchanged_projection_versions(): void
    {
        $policy = 'ordinary-project-sql-document/2';
        $tools = [...OrdinaryAssistantSourceProjection::TOOLS, OrdinaryAssistantDocumentProjection::TOOL];
        $sources = [...$this->sources('get_project_attention'),
            ['tool' => 'get_project_production_schedule_summary', 'projection_version' => OrdinaryAssistantSourceProjection::VERSION]];
        self::assertTrue(OrdinaryAssistantSourceProjection::allowsTools($policy, $tools));
        self::assertEqualsCanonicalizing($tools, OrdinaryAssistantSourceProjection::toolsForPolicy($policy));
        foreach ([$sources, array_reverse($sources), [$sources[1], $sources[2], $sources[0]]] as $order) {
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources($policy, $order));
            self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::MIXED_VERSION, $order));
        }
        foreach (OrdinaryAssistantSourceProjection::TOOLS as $sql) {
            self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources($policy, $this->sources($sql)));
            self::assertTrue(OrdinaryAssistantSourceProjection::allowsSources(OrdinaryAssistantSourceProjection::MIXED_VERSION, $this->sources($sql)));
        }
    }

    public static function invalidThreeSourceSets(): iterable
    {
        $sql = OrdinaryAssistantSourceProjection::VERSION;
        $doc = OrdinaryAssistantDocumentProjection::VERSION;
        $valid = [['tool' => 'get_project_attention', 'projection_version' => $sql],
            ['tool' => 'get_project_production_schedule_summary', 'projection_version' => $sql],
            ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'projection_version' => $doc]];
        yield 'empty' => [[]];
        yield 'one' => [[$valid[0]]];
        yield 'two SQL only' => [array_slice($valid, 0, 2)];
        yield 'missing schedule' => [[$valid[0], $valid[2]]];
        yield 'duplicate SQL' => [[$valid[0], $valid[0], $valid[2]]];
        yield 'duplicate document' => [[$valid[0], $valid[2], $valid[2]]];
        yield 'fourth source' => [[...$valid, $valid[0]]];
        yield 'unknown tool' => [[$valid[0], ['tool' => 'get_project_workforce_summary', 'projection_version' => $sql], $valid[2]]];
        yield 'composition marker on SQL' => [[array_replace($valid[0], ['projection_version' => 'ordinary-project-sql-document/2']), $valid[1], $valid[2]]];
        yield 'composition marker on doc' => [[$valid[0], $valid[1], array_replace($valid[2], ['projection_version' => 'ordinary-project-sql-document/2'])]];
        yield 'wrong SQL marker' => [[array_replace($valid[0], ['projection_version' => $doc]), $valid[1], $valid[2]]];
        yield 'wrong doc marker' => [[$valid[0], $valid[1], array_replace($valid[2], ['projection_version' => $sql])]];
        yield 'malformed' => [[null, 'get_project_attention', $valid[2]]];
    }

    #[DataProvider('invalidThreeSourceSets')]
    public function test_three_source_policy_denies_every_incomplete_duplicate_or_wrong_version_set(array $sources): void
    {
        self::assertFalse(OrdinaryAssistantSourceProjection::allowsSources('ordinary-project-sql-document/2', $sources));
    }

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
