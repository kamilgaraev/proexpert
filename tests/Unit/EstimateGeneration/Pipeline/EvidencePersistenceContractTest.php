<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceAttribute;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceConfidenceBand;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceCurrency;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceMeasurementMethod;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceUnit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvidencePersistenceContractTest extends TestCase
{
    #[Test]
    public function postgres_schema_and_repository_encode_graph_invariants(): void
    {
        $migration = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000300_create_estimate_generation_evidence_table.php'));
        $checkpointMigration = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php'));
        $repository = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/Evidence/EloquentEvidenceRepository.php'));

        self::assertIsString($migration);
        self::assertStringContainsString("jsonb('locator')", $migration);
        self::assertStringContainsString('eg_evidence_confidence_ck', $migration);
        self::assertStringContainsString('eg_evidence_edge_self_ck', $migration);
        self::assertStringContainsString('eg_evidence_edge_parent_scope_fk', $migration);
        self::assertStringContainsString('eg_evidence_edge_child_scope_fk', $migration);
        self::assertStringContainsString('eg_evidence_session_scope_fk', $migration);
        self::assertIsString($checkpointMigration);
        self::assertStringContainsString('eg_sessions_scope_uq', $checkpointMigration);
        self::assertStringContainsString('eg_evidence_immutable_trg', $migration);
        self::assertStringContainsString('eg_evidence_edge_transition_trg', $migration);
        self::assertStringContainsString('eg_evidence_edge_append_trg', $migration);
        self::assertStringContainsString('eg_evidence_semantic_trg', $migration);
        self::assertStringContainsString("TG_OP = 'UPDATE'", $migration);
        self::assertStringContainsString('estimate_generation.evidence_edge_delete_forbidden', $migration);
        self::assertStringContainsString("Schema::create('estimate_generation_evidence_edges'", $migration);
        self::assertStringContainsString("unsignedBigInteger('organization_id')", $migration);
        self::assertStringContainsString('eg_evidence_source_active_idx', $migration);
        self::assertIsString($repository);
        self::assertStringContainsString('pg_advisory_xact_lock', $repository);
        self::assertStringContainsString('insertOrIgnore', $repository);
        self::assertStringContainsString('CREATE TEMP TABLE', $repository);
        self::assertStringContainsString('WITH RECURSIVE graph', $repository);
        self::assertStringContainsString('ON COMMIT DROP', $repository);
        self::assertStringNotContainsString('->cursor(', $repository);
        self::assertStringNotContainsString("table('estimate_generation_evidence')->delete", $repository);
        foreach ([EvidenceAttribute::cases(), EvidenceProducer::cases(), EvidenceUnit::cases(), EvidenceMeasurementMethod::cases(), EvidenceConfidenceBand::cases(), EvidenceCurrency::cases()] as $cases) {
            foreach ($cases as $case) {
                self::assertStringContainsString("'{$case->value}'", $migration);
            }
        }
    }

    #[Test]
    public function document_source_replacement_uses_atomic_coordinator(): void
    {
        $source = file_get_contents($this->path('app/BusinessModules/Addons/EstimateGeneration/Application/Documents/CreateDocumentProcessingUnits.php'));

        self::assertIsString($source);
        self::assertStringContainsString('DocumentSourceReplacementCoordinator', $source);
        self::assertStringContainsString('$previousSourceVersion', $source);
        self::assertStringContainsString('replacement->commit(', $source);
        self::assertStringNotContainsString('DB::transaction(', $source);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
