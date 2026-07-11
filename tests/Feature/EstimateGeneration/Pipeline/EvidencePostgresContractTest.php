<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceParent;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRelation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres-contract')]
final class EvidencePostgresContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
            $this->markTestSkipped('Requires an explicit isolated PostgreSQL contract environment.');
        }
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
    }

    #[Test]
    public function unique_fingerprint_is_race_safe_and_scope_constraints_reject_foreign_edges(): void
    {
        $repository = app(EloquentEvidenceRepository::class);
        $recorder = new EvidenceRecorder($repository);
        $data = $this->data();

        $first = $recorder->record($data);
        $second = $recorder->record($data);
        $child = $recorder->record($this->data('document:contract-child'), [
            new EvidenceParent($first->id, EvidenceRelation::DerivedFrom),
        ]);

        self::assertSame($first->id, $second->id);
        $this->expectException(\Throwable::class);
        DB::table('estimate_generation_evidence_edges')->insert([
            'organization_id' => $data->organizationId + 1,
            'project_id' => $data->projectId,
            'session_id' => $data->sessionId,
            'parent_id' => $first->id,
            'child_id' => $child->id,
            'relation' => 'derived_from',
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function deleting_a_node_cascades_edges_while_invalidation_preserves_nodes(): void
    {
        $repository = app(EloquentEvidenceRepository::class);
        $recorder = new EvidenceRecorder($repository);
        $parent = $recorder->record($this->data('document:cascade'));
        $child = $recorder->record($this->data('document:cascade-child'), [
            new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
        ]);

        $count = (new EvidenceInvalidator($repository))->invalidateSource(
            $parent->organizationId,
            $parent->projectId,
            $parent->sessionId,
            EvidenceSourceType::Document,
            'document:cascade',
            'contract:1',
            'contract',
        );
        self::assertSame(2, $count);
        self::assertSame(2, DB::table('estimate_generation_evidence')->whereIn('id', [$parent->id, $child->id])->count());

        DB::table('estimate_generation_evidence')->where('id', $parent->id)->delete();
        self::assertSame(0, DB::table('estimate_generation_evidence_edges')->where('parent_id', $parent->id)->count());
    }

    private function data(string $sourceRef = 'document:contract'): EvidenceData
    {
        return new EvidenceData(
            organizationId: (int) getenv('EG_TEST_ORGANIZATION_ID'),
            projectId: (int) getenv('EG_TEST_PROJECT_ID'),
            sessionId: (int) getenv('EG_TEST_SESSION_ID'),
            type: EvidenceType::SourceFact,
            sourceType: EvidenceSourceType::Document,
            sourceRef: $sourceRef,
            sourceVersion: 'contract:1',
            locator: ['page' => 1],
            value: ['kind' => 'contract'],
            confidence: 1,
            producerName: 'contract',
            producerVersion: '1',
        );
    }
}
