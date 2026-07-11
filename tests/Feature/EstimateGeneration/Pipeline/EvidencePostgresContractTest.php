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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres-contract')]
final class EvidencePostgresContractTest extends TestCase
{
    private string $runSuffix;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
            $this->markTestSkipped('Requires an explicit isolated PostgreSQL contract environment.');
        }
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->runSuffix = bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function duplicate_fingerprint_is_idempotent_and_scope_constraints_reject_foreign_edges(): void
    {
        $repository = app(EloquentEvidenceRepository::class);
        $recorder = new EvidenceRecorder($repository);
        $data = $this->data();

        $first = $recorder->record($data);
        $second = $recorder->record($data);
        $child = $recorder->record($this->data('document:contract-child', EvidenceType::Extracted), [
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
        $child = $recorder->record($this->data('document:cascade-child', EvidenceType::Extracted), [
            new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
        ]);

        $count = (new EvidenceInvalidator($repository))->invalidateSource(
            $parent->organizationId,
            $parent->projectId,
            $parent->sessionId,
            EvidenceSourceType::Document,
            $parent->sourceRef,
            $parent->sourceVersion,
            'contract',
        );
        self::assertSame(2, $count);
        self::assertSame(2, DB::table('estimate_generation_evidence')->whereIn('id', [$parent->id, $child->id])->count());

        $edgeId = (int) DB::table('estimate_generation_evidence_edges')->where('parent_id', $parent->id)->value('id');
        $this->assertMutationRejected('edge_update', function () use ($edgeId): void {
            DB::table('estimate_generation_evidence_edges')->where('id', $edgeId)->update(['relation' => 'supports']);
        }, 'evidence_edge_update_forbidden');
        $this->assertMutationRejected('edge_delete', function () use ($edgeId): void {
            DB::table('estimate_generation_evidence_edges')->where('id', $edgeId)->delete();
        }, 'evidence_edge_delete_forbidden');

        DB::table('estimate_generation_evidence')->where('id', $parent->id)->delete();
        self::assertSame(0, DB::table('estimate_generation_evidence_edges')->where('parent_id', $parent->id)->count());
    }

    #[Test]
    public function deleting_disposable_session_cascades_nodes_and_edges(): void
    {
        $repository = app(EloquentEvidenceRepository::class);
        $recorder = new EvidenceRecorder($repository);
        $parent = $recorder->record($this->data('document:session-cascade'));
        $child = $recorder->record($this->data('document:session-cascade-child', EvidenceType::Extracted), [
            new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
        ]);

        DB::table('estimate_generation_sessions')->where('id', $parent->sessionId)->delete();

        self::assertSame(0, DB::table('estimate_generation_evidence')->whereIn('id', [$parent->id, $child->id])->count());
        self::assertSame(0, DB::table('estimate_generation_evidence_edges')->where('parent_id', $parent->id)->count());
    }

    private function data(string $sourceRef = 'document:contract', EvidenceType $type = EvidenceType::SourceFact): EvidenceData
    {
        return new EvidenceData(
            organizationId: (int) getenv('EG_TEST_ORGANIZATION_ID'),
            projectId: (int) getenv('EG_TEST_PROJECT_ID'),
            sessionId: (int) getenv('EG_TEST_SESSION_ID'),
            type: $type,
            sourceType: EvidenceSourceType::Document,
            sourceRef: $sourceRef.':'.$this->runSuffix,
            sourceVersion: 'contract:'.$this->runSuffix,
            locator: ['document_id' => 1, 'page' => 1],
            value: $type === EvidenceType::SourceFact
                ? ['fact_key' => 'contract', 'fact_value' => 'fixture']
                : ['field_key' => 'contract', 'field_value' => 'fixture'],
            confidence: 1,
            producerName: 'contract',
            producerVersion: $this->runSuffix,
        );
    }

    private function assertMutationRejected(string $savepoint, callable $mutation, string $code): void
    {
        DB::statement('SAVEPOINT '.$savepoint);
        try {
            $mutation();
            self::fail('Direct provenance edge mutation was accepted.');
        } catch (QueryException $error) {
            self::assertStringContainsString($code, $error->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            DB::statement('RELEASE SAVEPOINT '.$savepoint);
        }
    }
}
