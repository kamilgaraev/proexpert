<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoomIdentityFactEligibilityTest extends TestCase
{
    #[Test]
    public function the_name_requires_its_exact_active_document_value_and_scope(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('entity:room', 1, 2, 3, $version, 'room', 'entity:room');
        $fact = new Fact('fact:room', 1, 2, 3, $version, $entity->id, 'room_name', 'Кухня', null, 0.0, 'document', 'candidate', ['evidence:7']);
        $data = new EvidenceData(1, 2, 3, EvidenceType::SourceFact, EvidenceSourceType::Document, 'document:5', $version,
            ['document_id' => 5, 'page' => 1], ['fact_key' => 'room_name', 'fact_value' => hash('sha256', 'Кухня')],
            0.0, EvidenceProducer::DrawingAnalyzer->value, 'sha256:'.str_repeat('b', 64));
        $node = new EvidenceNode(7, $data, $data->fingerprint());
        $eligibility = new RoomIdentityFactEligibility;
        self::assertSame($node, $eligibility->proof($fact, $entity, [7 => $node]));
        self::assertNull($eligibility->proof($fact, $entity, [7 => $node->invalidate(new DateTimeImmutable, 'source_changed')]));
        self::assertNull($eligibility->proof($fact, $entity, []));
        foreach ([['organization' => 4], ['name' => 'Спальня'], ['source' => 'document:6'], ['version' => 'sha256:'.str_repeat('c', 64)]] as $change) {
            $altered = new EvidenceData($change['organization'] ?? 1, 2, 3, EvidenceType::SourceFact, EvidenceSourceType::Document,
                $change['source'] ?? 'document:5', $change['version'] ?? $version, ['document_id' => 5, 'page' => 1],
                ['fact_key' => 'room_name', 'fact_value' => hash('sha256', $change['name'] ?? 'Кухня')],
                0.0, EvidenceProducer::DrawingAnalyzer->value, 'sha256:'.str_repeat('b', 64));
            self::assertNull($eligibility->proof($fact, $entity, [7 => new EvidenceNode(7, $altered, $altered->fingerprint())]));
        }
        $numeric = new Fact('fact:area', 1, 2, 3, $version, $entity->id, 'area', '20', 'm2', 0.0, 'document', 'candidate', ['evidence:7']);
        self::assertNull($eligibility->proof($numeric, $entity, [7 => $node]));
    }
}
