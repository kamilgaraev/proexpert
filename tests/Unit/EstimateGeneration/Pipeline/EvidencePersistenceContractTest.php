<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvidencePersistenceContractTest extends TestCase
{
    #[Test]
    public function postgres_schema_and_repository_encode_graph_invariants(): void
    {
        $migration = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000300_create_estimate_generation_evidence_table.php'));
        $repository = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/Evidence/EloquentEvidenceRepository.php'));

        self::assertIsString($migration);
        self::assertStringContainsString("jsonb('locator')", $migration);
        self::assertStringContainsString('eg_evidence_confidence_ck', $migration);
        self::assertStringContainsString('eg_evidence_edge_self_ck', $migration);
        self::assertStringContainsString('eg_evidence_edge_parent_scope_fk', $migration);
        self::assertStringContainsString('eg_evidence_edge_child_scope_fk', $migration);
        self::assertStringContainsString('eg_evidence_session_scope_fk', $migration);
        self::assertStringContainsString('eg_sessions_scope_uq', $migration);
        self::assertStringContainsString('eg_evidence_immutable_trg', $migration);
        self::assertStringContainsString('eg_evidence_source_active_idx', $migration);
        self::assertIsString($repository);
        self::assertStringContainsString('pg_advisory_xact_lock', $repository);
        self::assertStringContainsString('insertOrIgnore', $repository);
        self::assertStringNotContainsString('->delete(', $repository);
    }

    #[Test]
    public function accepted_document_source_replacement_invokes_scoped_invalidation_seam(): void
    {
        $source = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/Application/Documents/CreateDocumentProcessingUnits.php'));

        self::assertIsString($source);
        self::assertStringContainsString('EvidenceSourceReplacementInvalidator', $source);
        self::assertStringContainsString('$previousSourceVersion', $source);
        self::assertStringContainsString('invalidateReplacedDocumentSource(', $source);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
