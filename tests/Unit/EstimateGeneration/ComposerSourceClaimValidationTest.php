<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService as Claims;
use PHPUnit\Framework\TestCase;

final class ComposerSourceClaimValidationTest extends TestCase
{
    public function test_old_snapshot_cannot_restore_a_missing_or_replaced_human_source_review(): void
    {
        [$entity, $original] = $this->requirementSource();
        $publicationId = Claims::sourceReviewPublicationId([10, 20, 40], str_repeat('a', 64), 'f0c59e29-76cd-4d4f-9f66-1e40afac9d65');
        $source = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact($publicationId, 10, 20, 40,
            $original->sourceVersion, $entity->id, $original->type, $original->value, null, 0.0, 'document', 'confirmed', ['evidence:1'], 2, $original->id);
        $review = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:old-review', 10, 20, 40,
            'sha256:'.str_repeat('b', 64), 'entity:review', 'evaluation_answer', ['kind' => 'document_source_review',
                'publication' => ['fact_id' => $publicationId]], null, 0.0, 'user_input', 'confirmed', ['evidence:2']);
        $replacement = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:new-review', 10, 20, 40,
            $review->sourceVersion, $review->entityId, $review->type, $review->value, null, 0.0, 'user_input', 'confirmed', ['evidence:3'], 2, $review->id);
        foreach ([[], [$replacement]] as $current) {
            $models = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
            $models->expects(self::once())->method('currentFacts')->with(10, 20, 40, $review->entityId)->willReturn($current);
            $evidence = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
            $evidence->expects(self::never())->method('node');
            $units = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class);
            $authorization = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class);
            $authorization->expects(self::never())->method('authorize');
            $claims = new Claims($evidence, $units, reviewAuthorization: $authorization, models: static fn () => $models);
            $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$entity], [$source, $review], [], []);
            self::assertNull($claims->captureSourceReview([10, 20, 40], $snapshot, [], $source));
        }
        self::assertNotSame($publicationId, Claims::sourceReviewPublicationId([10, 20, 40], str_repeat('a', 64), 'f0c59e29-76cd-4d4f-9f66-1e40afac9d66'));
    }

    public function test_source_review_captures_a_candidate_without_relaxing_requirement_admission(): void
    {
        $fixture = $this->requirementCapture();
        try {
            $source = $fixture->snapshot->facts[0];
            $candidate = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact($source->id, 10, 20, 40,
                $source->sourceVersion, $source->entityId, $source->type, $source->value, null, 0.0, 'document', 'candidate', $source->evidenceIds);
            $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $fixture->snapshot->entities, [$candidate], $fixture->snapshot->evidence, []);
            self::assertNull(Claims::requirementProposal($snapshot->entities[0], $candidate));
            $reviews = $fixture->claims->sourceReviewSources([10, 20, 40], $snapshot, $fixture->documents);
            self::assertCount(1, $reviews);
            $ref = array_key_first($reviews);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $ref);
            self::assertSame($source->id, $reviews[$ref]['source_fact_id']);
            self::assertSame($fixture->outputVersion, $reviews[$ref]['output_identity']['version']);
            self::assertSame($reviews, $fixture->claims->sourceReviewSources([10, 20, 40], $snapshot, $fixture->documents));
            self::assertSame([], $fixture->claims->sourceReviewSources([10, 20, 41], $snapshot, $fixture->documents));
            self::assertSame([], $fixture->claims->sourceReviewSources([10, 20, 40], $snapshot, []));
            $fixture->state->rows['estimate_generation_document_pages'][0]['output_version'] = str_repeat('b', 64);
            self::assertSame([], $fixture->claims->sourceReviewSources([10, 20, 40], $snapshot, $fixture->documents));
        } finally {
            ($fixture->restore)();
        }
    }

    public function test_source_review_publication_preserves_raw_analyzer_and_requires_a_distinct_human_association(): void
    {
        [$entity, $source, $descriptor] = $this->requirementSource();
        $original = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact($source->id, 10, 20, 40,
            $source->sourceVersion, $source->entityId, $source->type, $source->value, null, 0.0, 'document', 'candidate', $source->evidenceIds);
        $publicationId = Claims::sourceReviewPublicationId([10, 20, 40], str_repeat('a', 64), 'f0c59e29-76cd-4d4f-9f66-1e40afac9d65');
        self::assertLessThanOrEqual(191, strlen($publicationId));
        self::assertMatchesRegularExpression('/\Afact:source-review:[a-f0-9]{64}\z/D', $publicationId);
        $models = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $models->expects(self::once())->method('saveSourceModel')->willReturnCallback(static function (array $entities, array $facts) use ($entity, $original, $publicationId): void {
            self::assertSame([$entity], $entities);
            self::assertCount(1, $facts);
            $published = $facts[0];
            self::assertSame($publicationId, $published->id);
            self::assertSame($original->id, $published->supersedesFactId);
            self::assertSame('document', $published->origin);
            self::assertSame('confirmed', $published->status);
            self::assertSame($original->value, $published->value);
            self::assertSame($original->evidenceIds, $published->evidenceIds);
            self::assertSame([], $published->nativeNumericProofs);
            self::assertSame('candidate', $original->status);
        });
        $evidence = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
        $review = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:human-review', 10, 20, 40,
            'sha256:'.str_repeat('b', 64), 'entity:review', 'evaluation_answer', ['kind' => 'document_source_review',
                'review_kind' => 'source_requirement', 'decision' => 'accept', 'source' => ['source_fact_id' => $source->id,
                    'source_fact_version' => $source->version, 'source_entity_id' => $entity->id, 'descriptor' => $descriptor,
                    'source_value_hash' => hash('sha256', json_encode($source->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))], 'publication' => ['fact_id' => $publicationId, 'version' => 2]],
            null, 0.0, 'user_input', 'confirmed', ['evidence:2']);
        (new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter($models, $evidence))
            ->writeReviewedSourceRequirement($entity, $original, [], $review);
        $units = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class);
        $claims = new Claims($evidence, $units);
        $published = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact($publicationId, 10, 20, 40,
            $original->sourceVersion, $original->entityId, $original->type, $original->value, null, 0.0, 'document', 'confirmed', $original->evidenceIds,
            2, $original->id);
        $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$entity], [$published], [], []);
        self::assertNull($claims->captureSourceReview([10, 20, 40], $snapshot, [], $published));
        self::assertSame($descriptor, Claims::requirementProposal($entity, $source));
    }

    public function test_requirement_sources_accept_distinct_processing_output_and_pin_its_identity(): void
    {
        $fixture = $this->requirementCapture();
        try {
            $cache = [];
            $sources = $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $cache);
            self::assertCount(1, $sources);
            $source = array_values($sources)[0];
            self::assertNotSame($fixture->documents[0]['source_version'], $fixture->outputVersion);
            self::assertSame(2, $source['output_identity']['processing_unit_id']);
            self::assertSame(3, $source['output_identity']['page_id']);
            self::assertSame($fixture->outputVersion, $source['output_identity']['version']);
            self::assertSame(hash('sha256', json_encode('Фрагмент требования',
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), $source['source_value_hash']);
            self::assertSame($fixture->documents[0], $source['document_pin']);

            $fixture->state->rows['estimate_generation_processing_units'][0]['output_version'] = str_repeat('b', 64);
            $fixture->state->rows['estimate_generation_document_pages'][0]['output_version'] = str_repeat('b', 64);
            $fresh = [];
            $changed = $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $fresh);
            self::assertCount(1, $changed);
            self::assertNotSame(array_keys($sources), array_keys($changed));
            $fixture->state->rows['estimate_generation_document_pages'][0]['output_version'] = str_repeat('c', 64);
            $fresh = [];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $fresh));
            $fixture->state->rows['estimate_generation_document_pages'][0]['output_version'] = str_repeat('b', 64);
            $fixture->state->rows['estimate_generation_documents'][0]['checksum_sha256'] = str_repeat('b', 64);
            $fresh = [];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $fresh));
            $fixture->state->rows['estimate_generation_documents'][0]['checksum_sha256'] = str_repeat('a', 64);
            $fixture->state->rows['estimate_generation_documents'][0]['source_version'] = 'sha256:'.str_repeat('b', 64);
            $fresh = [];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $fresh));
        } finally {
            ($fixture->restore)();
        }
    }

    public function test_requirement_sources_cache_negative_capture_only_within_the_same_snapshot_scope_and_pins(): void
    {
        $fixture = $this->requirementCapture(true);
        try {
            $cache = [];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $cache));
            $reads = $fixture->state->reads;
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->otherWork, $fixture->documents, $cache));
            self::assertSame($reads, $fixture->state->reads);
            self::assertSame(1, $fixture->state->nodes);
            self::assertSame(0, $fixture->state->artifacts);
            $fresh = [];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $fresh));
            self::assertSame(2, $fixture->state->nodes);
        } finally {
            ($fixture->restore)();
        }
    }

    public function test_requirement_sources_capture_once_for_distinct_work_refs_and_refresh_on_snapshot_or_pin_changes(): void
    {
        $fixture = $this->requirementCapture();
        try {
            $cache = [];
            $first = $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work, $fixture->documents, $cache);
            self::assertCount(1, $first);
            $reads = $fixture->state->reads;
            $second = $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->otherWork, $fixture->documents, $cache);
            self::assertSame(array_values($first), array_values($second));
            self::assertNotSame(array_keys($first), array_keys($second));
            self::assertSame($reads, $fixture->state->reads);
            self::assertSame(1, $fixture->state->nodes);
            self::assertSame(1, $fixture->state->artifacts);
            self::assertContains($fixture->snapshot, array_column($cache, 'snapshot'));
            self::assertSame([1], array_map('count', array_column($cache, 'candidates')));
            self::assertSame([1], array_map('count', array_column($cache, 'captures')));
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 41], $fixture->snapshot, $fixture->work, $fixture->documents, $cache));
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot,
                [...$fixture->work, 'binding_hash' => str_repeat('0', 64)], $fixture->documents, $cache));
            self::assertSame($reads, $fixture->state->reads);

            $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $fixture->snapshot->entities, $fixture->snapshot->facts, $fixture->snapshot->evidence, []);
            self::assertCount(1, $fixture->claims->requirementSources([10, 20, 40], $snapshot, $fixture->work, $fixture->documents, $cache));
            self::assertSame(2, $fixture->state->nodes);
            self::assertSame(2, $fixture->state->artifacts);
            $documents = [[...$fixture->documents[0], 'checksum_sha256' => str_repeat('b', 64)]];
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $snapshot, $fixture->work, $documents, $cache));
            self::assertNotSame($reads, $fixture->state->reads);
            $reads = $fixture->state->reads;
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $snapshot, $fixture->otherWork, $documents, $cache));
            self::assertSame($reads, $fixture->state->reads);
            $fresh = [];
            $afterAnswer = $fixture->claims->requirementSources([10, 20, 40], $snapshot,
                [...$fixture->work, 'state_version' => 9, 'snapshot_token' => 'new', 'answer' => ['fact_id' => 'fact:answer'],
                    'decision_id' => 'decision:answer'], $fixture->documents, $fresh);
            self::assertSame($first, $afterAnswer);
            self::assertSame(3, $fixture->state->nodes);
            self::assertSame(3, $fixture->state->artifacts);
            $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:1', 10, 20, 40,
                $fixture->documents[0]['source_version'], 'document:17', 'document', 1,
                sourceReference: 'document:17', fingerprint: str_repeat('b', 64));
            $changed = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $snapshot->entities, $snapshot->facts, [$proof], []);
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $changed, $fixture->work, $fixture->documents, $cache));
            self::assertSame(4, $fixture->state->nodes);
            self::assertSame(3, $fixture->state->artifacts);
        } finally {
            ($fixture->restore)();
        }
    }

    public function test_requirement_source_capture_preserves_full_set_duplicate_and_cardinality_guards(): void
    {
        $fixture = $this->requirementCapture();
        try {
            $cache = [];
            $duplicate = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $fixture->snapshot->entities, [...$fixture->snapshot->facts, ...$fixture->snapshot->facts], $fixture->snapshot->evidence, []);
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $duplicate, $fixture->work, $fixture->documents, $cache));
            $facts = [];
            $source = $fixture->snapshot->facts[0];
            for ($i = 0; $i <= Claims::MAX_CLAIMS; $i++) {
                $facts[] = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:source'.$i, 10, 20, 40,
                    $source->sourceVersion, $source->entityId, $source->type, $source->value, null, 0.0, 'document', 'confirmed', $source->evidenceIds);
            }
            $tooMany = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                $fixture->snapshot->entities, $facts, $fixture->snapshot->evidence, []);
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $tooMany, $fixture->work, $fixture->documents, $cache));
            $reads = $fixture->state->reads;
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $tooMany, $fixture->otherWork, $fixture->documents, $cache));
            self::assertSame($reads, $fixture->state->reads);
            self::assertSame(2, $fixture->state->nodes);
            self::assertSame(2, $fixture->state->artifacts);
            self::assertSame([], $fixture->claims->requirementSources([10, 20, 40], $fixture->snapshot, $fixture->work,
                array_fill(0, 9, $fixture->documents[0]), $cache));
            self::assertSame($reads, $fixture->state->reads);
        } finally {
            ($fixture->restore)();
        }
    }

    private function requirementCapture(bool $invalidated = false): object
    {
        [$source, $fact] = $this->requirementSource();
        $target = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('entity:target', 10, 20, 40,
            $source->sourceVersion, 'equipment', 'entity:target');
        $data = new \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData(10, 20, 40,
            \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType::SourceFact,
            \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType::Document, 'document:17', $source->sourceVersion,
            ['document_id' => 17, 'page' => 1], ['fact_key' => 'quantity', 'fact_value' => '1.2'], 0.0, 'drawing_analyzer', 'pipeline:v1');
        $node = new \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode(1, $data, $data->fingerprint(),
            $invalidated ? new \DateTimeImmutable('2026-10-24T00:00:00Z') : null);
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:1', 10, 20, 40,
            $source->sourceVersion, 'document:17', 'document', 1, sourceReference: 'document:17', fingerprint: $node->fingerprint);
        $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$source, $target], [$fact], [$proof], []);
        $body = '{"analysis":"current"}';
        $outputVersion = hash('sha256', json_encode(['vision_analysis' => ['claims' => ['current']]], JSON_THROW_ON_ERROR));
        $scope = ['organization_id' => 10, 'project_id' => 20, 'session_id' => 40];
        $state = (object) ['reads' => ['estimate_generation_processing_units' => 0, 'estimate_generation_documents' => 0,
            'estimate_generation_document_pages' => 0], 'nodes' => 0, 'artifacts' => 0, 'rows' => [
            'estimate_generation_processing_units' => [[...$scope, 'id' => 2, 'document_id' => 17,
                'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $source->sourceVersion, 'status' => 'completed',
                'output_version' => $outputVersion, 'output_count' => 1, 'attempt_count' => 1,
                'locator' => json_encode(['artifact_sha256' => 'sha256:'.hash('sha256', $body), 'artifact_bytes' => strlen($body)], JSON_THROW_ON_ERROR)]],
            'estimate_generation_documents' => [[...$scope, 'id' => 17, 'source_version' => $source->sourceVersion,
                'checksum_sha256' => str_repeat('a', 64), 'status' => 'ready', 'ignored_at' => null,
                'storage_path' => 'scoped/document.pdf', 'mime_type' => 'application/pdf', 'filename' => 'source.pdf']],
            'estimate_generation_document_pages' => [[...$scope, 'id' => 3, 'document_id' => 17, 'processing_unit_id' => 2,
                'source_version' => $source->sourceVersion, 'output_version' => $outputVersion, 'page_number' => 1,
                'status' => 'ready', 'excluded_at' => null]],
        ]];
        $connection = $this->getMockBuilder(\Illuminate\Database\Connection::class)
            ->setConstructorArgs([static fn () => throw new \LogicException('No database connection is allowed.')])
            ->onlyMethods(['select'])->getMock();
        $connection->method('select')->willReturnCallback(static function (string $query) use ($state): array {
            foreach ($state->rows as $table => $rows) {
                if (str_contains($query, '"'.$table.'"')) {
                    $state->reads[$table]++;

                    return array_map(static fn (array $row): object => (object) $row, $rows);
                }
            }
            throw new \LogicException('Unexpected query: '.$query);
        });
        $previous = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $resolver = new \Illuminate\Database\ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
        $evidence = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
        $evidence->method('node')->willReturnCallback(static function () use ($state, $node) {
            $state->nodes++;

            return $node;
        });
        $reader = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader::class);
        $reader->method('read')->willReturnCallback(static function () use ($state, $body): string {
            $state->artifacts++;

            return $body;
        });
        $units = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class);
        $work = static function (string $key) use ($target): array {
            $binding = ['entity_id' => $target->id, 'work_key' => $key, 'work_section' => 'heating'];

            return ['binding' => $binding, 'binding_hash' => hash('sha256',
                \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($binding))];
        };

        return (object) ['claims' => new Claims($evidence, $units, contentReader: $reader), 'snapshot' => $snapshot,
            'work' => $work('custom_install'), 'otherWork' => $work('custom_check'), 'state' => $state, 'outputVersion' => $outputVersion,
            'documents' => [['document_id' => 17, 'source_version' => $source->sourceVersion, 'checksum_sha256' => str_repeat('a', 64), 'status' => 'ready']],
            'restore' => static function () use ($previous): void {
                if ($previous === null) {
                    \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
                } else {
                    \Illuminate\Database\Eloquent\Model::setConnectionResolver($previous);
                }
            }];
    }

    public function test_requirement_proposal_uses_existing_generic_source_without_numeric_or_equipment_authority(): void
    {
        [$entity, $fact, $descriptor] = $this->requirementSource();
        self::assertSame($descriptor, Claims::requirementProposal($entity, $fact));
        $source = ['descriptor' => $descriptor, 'evidence' => [['evidence_id' => 'evidence:1']]];
        $details = Claims::requirementDetails($source);
        self::assertSame('1.2', $details['threshold']);
        self::assertSame('m', $details['unit']);
        self::assertNull($details['equipment_identity']);
        self::assertNull($details['conditions']);
        self::assertSame('unresolved', $details['source_correspondence']);
        self::assertSame('unresolved', $details['source_authority']);
        self::assertSame('unresolved', $details['engineering_applicability']);
        self::assertFalse($details['numeric_inputs_verified']);
        self::assertArrayNotHasKey('confirmation_level', $details);
        self::assertArrayNotHasKey('descriptor', $details);
        self::assertArrayNotHasKey('field_locators', $details);
        self::assertArrayNotHasKey('observed', $details);
        foreach ([['threshold' => ['data' => 1.2]], ['threshold' => ['data' => '0']], ['threshold' => ['data' => '1e2']],
            ['comparator' => ['data' => 'lte']], ['unit' => ['data' => 'm2']], ['dimension' => 'area']] as $changes) {
            [$invalidEntity, $invalidFact] = $this->requirementSource($changes);
            self::assertNull(Claims::requirementProposal($invalidEntity, $invalidFact));
        }
    }

    public function test_requirement_ref_ignores_answer_and_cas_but_changes_with_source_work_and_scope(): void
    {
        [, , $descriptor] = $this->requirementSource();
        $association = ['descriptor' => $descriptor, 'source_fact_id' => 'fact:source'];
        $work = ['schema_version' => 1, 'binding' => ['work_key' => 'custom_install', 'work_section' => 'heating'],
            'binding_hash' => str_repeat('a', 64), 'dependencies' => [], 'policy' => [], 'question' => []];
        $ref = Claims::requirementReference([10, 20, 40], $work, $association);
        self::assertSame($ref, Claims::requirementReference([10, 20, 40], [...$work, 'state_version' => 9,
            'snapshot_token' => 'new', 'answer' => ['fact_id' => 'fact:new'], 'decision_id' => 'decision:new'], $association));
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 41], $work, $association));
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 40], [...$work,
            'binding' => [...$work['binding'], 'work_key' => 'replacement']], $association));
        [, , $changed] = $this->requirementSource(['threshold' => ['data' => '1.3']]);
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 40], $work, [...$association, 'descriptor' => $changed]));
    }

    public function test_requirement_sources_do_not_read_artifacts_for_unattached_or_foreign_context(): void
    {
        [$source, $fact] = $this->requirementSource();
        $target = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('entity:target', 10, 20, 40,
            $source->sourceVersion, 'equipment', 'entity:target');
        $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$source, $target], [$fact], [], []);
        $evidence = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
        $evidence->expects(self::never())->method('node');
        $reader = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader::class);
        $reader->expects(self::never())->method('read');
        $units = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class);
        $claims = new Claims($evidence, $units, contentReader: $reader);
        $binding = ['entity_id' => $target->id, 'work_key' => 'custom_install', 'work_section' => 'heating'];
        $work = ['binding' => $binding, 'binding_hash' => hash('sha256',
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($binding))];
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, $work, []));
        self::assertSame([], $claims->requirementSources([10, 20, 41], $snapshot, $work, []));
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, [...$work, 'binding_hash' => str_repeat('0', 64)], []));
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, $work,
            [['document_id' => 18, 'source_version' => $source->sourceVersion, 'checksum_sha256' => str_repeat('a', 64), 'status' => 'ready']]));
    }

    public function test_requirement_descriptor_rejects_missing_field_provenance_future_shape_and_hash_drift(): void
    {
        [$entity, $fact, $descriptor] = $this->requirementSource();
        $missing = $descriptor;
        unset($missing['field_locators']['threshold']);
        $missing['proposal_hash'] = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::canonicalJson(
            array_intersect_key($missing, array_flip(['schema_version', 'payload', 'field_locators', 'source_pins']))));
        foreach ([$missing, [...$descriptor, 'schema_version' => 2], [...$descriptor, 'verified' => true],
            [...$descriptor, 'proposal_hash' => str_repeat('0', 64)],
            [...$descriptor, 'source_pins' => [...$descriptor['source_pins'], 'document_id' => '17']]] as $invalid) {
            $invalidEntity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity($entity->id, 10, 20, 40,
                $entity->sourceVersion, 'wall', $entity->stableKey, [...$entity->attributes, 'properties' => ['document_semantic_proposal' => $invalid]]);
            self::assertNull(Claims::requirementProposal($invalidEntity, $fact));
        }
    }

    private function requirementSource(array $changes = []): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $polygon = [[0.1, 0.2], [0.8, 0.2], [0.8, 0.7], [0.1, 0.7]];
        $field = static fn (?string $data): array => ['data' => $data, 'evidenceRef' => $data === null ? null : 'source',
            'sourcePolygonOrNativeRef' => $data === null ? null : $polygon];
        $payload = ['schemaVersion' => 1, 'kind' => 'source_requirement', 'parameter' => $field('Высота ограждения'),
            'dimension' => 'length', 'comparator' => $field('gte'), 'threshold' => $field('1.2'), 'unit' => $field('m'),
            'appliesTo' => ['subject' => $field('Указанное в источнике ограждение'), 'equipmentIdentity' => null],
            'documentEdition' => $field(null), 'clause' => $field(null), 'conditions' => null];
        $payload = array_replace_recursive($payload, $changes);
        $locators = [];
        foreach (['parameter', 'comparator', 'threshold', 'unit', 'appliesTo.subject'] as $path) {
            $locators[$path] = ['organization_id' => 10, 'project_id' => 20, 'session_id' => 40,
                'document_id' => 17, 'page_id' => 3, 'processing_unit_id' => 2, 'source_version' => $version,
                'fact_source' => ['kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1', 'value' => $polygon]];
        }
        ksort($locators, SORT_STRING);
        $base = ['schema_version' => 1, 'payload' => $payload, 'field_locators' => $locators,
            'source_pins' => ['organization_id' => 10, 'project_id' => 20, 'session_id' => 40, 'document_id' => 17,
                'page_number' => 1, 'processing_unit_id' => 2, 'source_version' => $version]];
        $descriptor = [...$base, 'status' => 'proposed', 'proposal_hash' => hash('sha256',
            \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::canonicalJson($base))];
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('entity:source', 10, 20, 40,
            $version, 'wall', 'entity:source', ['semantic_type' => 'wall', 'identity' => ['source_entity_key' => 'wall:source'],
                'properties' => ['document_semantic_proposal' => $descriptor]]);
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:source', 10, 20, 40, $version,
            $entity->id, 'technology_requirements', 'Фрагмент требования', null, 0.0, 'document', 'confirmed', ['evidence:1']);

        return [$entity, $fact, $descriptor];
    }

    public function test_closed_candidate_does_not_accept_model_verdicts_numeric_values_or_self_evidence(): void
    {
        $candidate = $this->candidate();
        self::assertTrue(Claims::isCandidate($candidate));
        foreach ([
            [...$candidate, 'passed' => true], [...$candidate, 'unit' => 'шт'],
            [...$candidate, 'value' => ['type' => 'number', 'data' => '3']],
            [...$candidate, 'value' => ['type' => 'string', 'data' => str_repeat('я', 501)]],
            [...$candidate, 'fact_type' => 'equipment_count'],
            [...$candidate, 'document_fact_ids' => ['fact:answer']],
            [...$candidate, 'document_fact_ids' => ['fact:document', 'fact:second']],
        ] as $invalid) {
            self::assertFalse(Claims::isCandidate($invalid));
        }
    }

    public function test_receipt_duplicates_collapse_but_conflicting_interpretations_are_unresolved(): void
    {
        $candidate = $this->candidate();
        self::assertSame([['run_id' => 1, 'candidate' => $candidate]], Claims::foldReceipts([
            ['run_id' => 2, 'candidates' => [$candidate]], ['run_id' => 1, 'candidates' => [$candidate]],
        ]));
        self::assertSame([], Claims::foldReceipts([
            ['run_id' => 1, 'candidates' => [$candidate]],
            ['run_id' => 2, 'candidates' => [[...$candidate, 'entity_id' => 'entity:other']]],
        ]));
        self::assertSame([], Claims::foldReceipts([['run_id' => 1, 'candidates' => [$candidate, $candidate]]]));
    }

    public function test_receipt_projection_and_logical_claim_bounds_fail_closed(): void
    {
        $receipts = array_fill(0, Claims::MAX_PROJECTIONS, ['run_id' => 1, 'candidates' => [$this->candidate()]]);
        self::assertCount(1, Claims::foldReceipts($receipts));
        $receipts[] = $receipts[0];
        self::assertSame([], Claims::foldReceipts($receipts));
        $receipts = [];
        for ($i = 0; $i < 9; $i++) {
            $receipts[] = ['run_id' => $i + 1, 'candidates' => [[...$this->candidate(), 'source_answer_fact_id' => 'fact:answer'.$i]]];
        }
        self::assertSame([], Claims::foldReceipts($receipts));
    }

    public function test_exact_document_correspondence_does_not_turn_a_different_word_or_paraphrase_into_support(): void
    {
        $original = 'Монтаж стендов';
        $witness = ['value_hash' => hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        $candidate = [...$this->candidate(), 'value' => ['type' => 'string', 'data' => $original]];
        self::assertTrue(Claims::literalValueCorresponds($candidate, $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Монтаж стен']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Установка стендов']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds($candidate, $original, ['value_hash' => str_repeat('0', 64)]));
    }

    private function candidate(): array
    {
        return ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'entity:equipment',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Насос по спецификации.'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
    }
}
