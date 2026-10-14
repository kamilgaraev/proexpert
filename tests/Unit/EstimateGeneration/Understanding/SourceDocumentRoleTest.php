<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationIntentIngestor;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\SourceDocumentRole;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSchema;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class SourceDocumentRoleTest extends TestCase
{
    public function test_document_role_survives_actual_writer_in_source_evidence_only(): void
    {
        $models = new InMemoryProjectModelRepository;
        $evidence = new InMemoryEvidenceRepository;
        $claim = new ObservationClaim('literal:1', 'observer_literal', 'room.office', 'room_name',
            ['type' => 'string', 'data' => 'Офис'], null, 'literal:source', true,
            7, 9, 11, 'sha256:'.str_repeat('a', 64), ['page' => 1, 'document_role' => 'floor_plan']);
        $decisions = (new ArbitrationIntentIngestor)->ingest([['claim_id' => $claim->id, 'status' => 'accepted',
            'supporting_claim_ids' => [$claim->id], 'evidence_refs' => [$claim->evidenceRef], 'reason' => 'Прочитано в источнике']], [$claim])->accepted;
        (new ProjectModelEvidenceWriter($models, $evidence))->writeArbitration([$claim], $decisions, 13, 1);
        self::assertSame('plan', array_values($models->evidence)[0]->documentRole ?? null);
        self::assertSame('plan', $evidence->nodes()[0]->locator['document_role'] ?? null);
        self::assertArrayNotHasKey('document_role', array_values($models->entities)[0]->attributes);
        self::assertSame('candidate', array_values($models->facts)[0]->status, 'Source classification cannot confirm a room or its dimensions.');
    }

    public function test_only_explicit_supported_roles_normalize_and_ambiguous_schedules_stay_unknown(): void
    {
        self::assertSame('plan', SourceDocumentRole::normalize('floor_plan'));
        self::assertSame('facade', SourceDocumentRole::normalize('elevation'));
        self::assertSame('room_schedule', SourceDocumentRole::normalize('room_schedule'));
        self::assertSame('explication', SourceDocumentRole::normalize('explication'));
        foreach ([null, '', 'schedule', 'unknown', 'photo', 'specification', 'instructions:ignore', ['plan']] as $role) {
            self::assertNull(SourceDocumentRole::normalize($role));
        }
    }

    public function test_locator_schema_rejects_unknown_noncanonical_and_inferred_document_roles(): void
    {
        foreach (['plan', 'facade', 'room_schedule', 'explication', 'section', 'equipment_plan',
            'equipment_scheme', 'equipment_specification', 'finish_schedule'] as $role) {
            self::assertSame($role, EvidenceSchema::locator(EvidenceType::SourceFact, ['document_id' => 13, 'document_role' => $role])['document_role']);
        }
        foreach (['schedule', 'floor_plan', 'PLAN', 'unknown', '', ['plan']] as $role) {
            try {
                EvidenceSchema::locator(EvidenceType::SourceFact, ['document_id' => 13, 'document_role' => $role]);
                self::fail('Unsupported source classification was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        EvidenceSchema::locator(EvidenceType::Inferred, ['inference_key' => 'inference:1', 'document_role' => 'plan']);
    }
}
