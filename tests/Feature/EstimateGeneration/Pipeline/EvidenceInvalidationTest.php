<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceEdge;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceParent;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRelation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BatchRecordingEvidenceRepository implements EvidenceRepository
{
    /** @var list<int> */
    public array $batchSizes = [];

    public function __construct(private readonly InMemoryEvidenceRepository $inner = new InMemoryEvidenceRepository) {}

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->inner->transaction($organizationId, $sessionId, $callback);
    }

    public function insertOrGet(EvidenceData $data): EvidenceNode
    {
        return $this->inner->insertOrGet($data);
    }

    public function node(int $organizationId, int $projectId, int $sessionId, int $id): ?EvidenceNode
    {
        return $this->inner->node($organizationId, $projectId, $sessionId, $id);
    }

    public function activeNodesForUpdate(int $organizationId, int $projectId, int $sessionId, array $ids): array
    {
        return $this->inner->activeNodesForUpdate($organizationId, $projectId, $sessionId, $ids);
    }

    public function addEdge(EvidenceEdge $edge): void
    {
        $this->inner->addEdge($edge);
    }

    public function pathExists(int $organizationId, int $projectId, int $sessionId, int $fromId, int $toId): bool
    {
        return $this->inner->pathExists($organizationId, $projectId, $sessionId, $fromId, $toId);
    }

    public function descendantBatches(int $organizationId, int $projectId, int $sessionId, array $types, string $ref, string $version, int $chunkSize): iterable
    {
        return $this->inner->descendantBatches($organizationId, $projectId, $sessionId, $types, $ref, $version, $chunkSize);
    }

    public function invalidate(int $organizationId, int $projectId, int $sessionId, array $ids, string $reason): int
    {
        $this->batchSizes[] = count($ids);

        return $this->inner->invalidate($organizationId, $projectId, $sessionId, $ids, $reason);
    }
}

final class EvidenceInvalidationTest extends TestCase
{
    private const OLD_VERSION = 'test:aaaaaa';

    private const NEW_VERSION = 'test:bbbbbb';

    private const DERIVED_VERSION = 'test:cccccc';

    #[Test]
    public function invalidates_old_source_and_all_descendants_once_without_deleting_history(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $invalidator = new EvidenceInvalidator($repository, chunkSize: 17);
        $old = $recorder->record($this->data(self::OLD_VERSION, 'document:44'));
        $new = $recorder->record($this->data(self::NEW_VERSION, 'document:44'));
        $left = $recorder->record($this->data(self::DERIVED_VERSION, 'left', EvidenceType::Extracted), [new EvidenceParent($old->id, EvidenceRelation::DerivedFrom)]);
        $right = $recorder->record($this->data(self::DERIVED_VERSION, 'right', EvidenceType::Extracted), [new EvidenceParent($old->id, EvidenceRelation::DerivedFrom)]);
        $diamond = $recorder->record($this->data(self::DERIVED_VERSION, 'diamond', EvidenceType::Measured), [
            new EvidenceParent($left->id, EvidenceRelation::DerivedFrom),
            new EvidenceParent($right->id, EvidenceRelation::Supports),
        ]);
        $previous = $diamond;
        for ($index = 0; $index < 1000; $index++) {
            $previous = $recorder->record($this->data(self::DERIVED_VERSION, 'chain:'.$index, EvidenceType::Inferred), [
                new EvidenceParent($previous->id, EvidenceRelation::DerivedFrom),
            ]);
        }

        $count = $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced');

        self::assertSame(1004, $count);
        self::assertSame(0, $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced'));
        self::assertCount(1005, $repository->nodes());
        self::assertNull($repository->node(1, 10, 100, $new->id)?->invalidatedAt);
        self::assertNotNull($repository->node(1, 10, 100, $previous->id)?->invalidatedAt);
    }

    #[Test]
    public function invalidation_is_tenant_session_and_source_version_scoped(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $invalidator = new EvidenceInvalidator($repository);
        $target = $recorder->record($this->data(self::OLD_VERSION, 'document:44'));
        $otherVersion = $recorder->record($this->data(self::NEW_VERSION, 'document:44'));
        $otherTenant = $recorder->record($this->data(self::OLD_VERSION, 'document:44', organizationId: 2));

        self::assertSame(1, $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced'));
        self::assertNotNull($repository->node(1, 10, 100, $target->id)?->invalidatedAt);
        self::assertNull($repository->node(1, 10, 100, $otherVersion->id)?->invalidatedAt);
        self::assertNull($repository->node(2, 10, 100, $otherTenant->id)?->invalidatedAt);
    }

    #[Test]
    public function traversal_reaches_active_descendants_through_an_already_invalidated_node(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $root = $recorder->record($this->data(self::OLD_VERSION, 'document:44'));
        $middle = $recorder->record($this->data(self::DERIVED_VERSION, 'middle', EvidenceType::Extracted), [
            new EvidenceParent($root->id, EvidenceRelation::DerivedFrom),
        ]);
        $leaf = $recorder->record($this->data(self::DERIVED_VERSION, 'leaf', EvidenceType::Measured), [
            new EvidenceParent($middle->id, EvidenceRelation::DerivedFrom),
        ]);
        $repository->invalidate(1, 10, 100, [$middle->id], 'partial_previous_attempt');

        self::assertSame(2, (new EvidenceInvalidator($repository))->invalidateSource(
            1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced',
        ));
        self::assertNotNull($repository->node(1, 10, 100, $leaf->id)?->invalidatedAt);
    }

    #[Test]
    public function traversal_resumes_from_an_invalidated_root_and_updates_in_bounded_batches(): void
    {
        $repository = new BatchRecordingEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $root = $recorder->record($this->data(self::OLD_VERSION, 'document:44'));
        $previous = $root;
        for ($index = 0; $index < 80; $index++) {
            $previous = $recorder->record($this->data(self::DERIVED_VERSION, 'bounded:'.$index, EvidenceType::Extracted), [
                new EvidenceParent($previous->id, EvidenceRelation::DerivedFrom),
            ]);
        }
        $repository->invalidate(1, 10, 100, [$root->id], 'partial_previous_attempt');
        $repository->batchSizes = [];

        self::assertSame(80, (new EvidenceInvalidator($repository, 17))->invalidateSource(
            1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced',
        ));
        self::assertGreaterThan(1, count($repository->batchSizes));
        self::assertLessThanOrEqual(17, max($repository->batchSizes));
        self::assertNotNull($repository->node(1, 10, 100, $previous->id)?->invalidatedAt);
        self::assertSame(0, (new EvidenceInvalidator($repository, 17))->invalidateSource(
            1, 10, 100, EvidenceSourceType::Document, 'document:44', self::OLD_VERSION, 'source_replaced',
        ));
    }

    private function data(
        string $sourceVersion,
        string $sourceRef,
        EvidenceType $type = EvidenceType::SourceFact,
        int $organizationId = 1,
    ): EvidenceData {
        return new EvidenceData(
            organizationId: $organizationId,
            projectId: 10,
            sessionId: 100,
            type: $type,
            sourceType: EvidenceSourceType::Document,
            sourceRef: str_starts_with($sourceRef, 'document:')
                ? $sourceRef
                : 'document:'.max(1, (int) sprintf('%u', crc32($sourceRef))),
            sourceVersion: $sourceVersion,
            locator: $type === EvidenceType::Inferred
                ? ['inference_key' => 'inference:'.max(1, (int) sprintf('%u', crc32($sourceRef)))]
                : ['document_id' => 44, 'page' => 1],
            value: match ($type) {
                EvidenceType::SourceFact => ['fact_key' => 'element_type_code', 'fact_value' => 'element_type:wall'],
                EvidenceType::Extracted => ['field_key' => 'element_type_code', 'field_value' => 'element_type:wall'],
                EvidenceType::Measured => ['quantity' => 12.0, 'unit' => 'm'],
                EvidenceType::Inferred => ['result_code' => 'element_type:wall'],
                default => throw new \LogicException('Unsupported fixture type.'),
            },
            confidence: 0.9,
            producerName: 'test',
            producerVersion: 'test:dddddd',
        );
    }
}
