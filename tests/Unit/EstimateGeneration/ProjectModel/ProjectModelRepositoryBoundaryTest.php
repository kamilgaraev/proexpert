<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelFactIdentityCollision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class ProjectModelRepositoryBoundaryTest extends TestCase
{
    #[Test]
    public function legacy_payload_and_semantic_hash_keep_their_exact_serialized_contract(): void
    {
        $repository = new EloquentProjectModelRepository($this->createMock(DatabaseManager::class));
        $fact = new Fact('fact:1', 1, 2, 3, 'sha256:'.str_repeat('a', 64), 'room:1', 'area', '7.94', 'm2', 1.0, 'document', 'confirmed', ['evidence:1']);
        $payload = $this->invoke($repository, 'factPayload', $fact);
        self::assertSame('{"source":"reconciled_geometry","value":"7.94","unit":"m2"}', json_encode($payload));
        self::assertSame([], $fact->semanticAttributes);
        self::assertStringNotContainsString('semantic', json_encode($fact, JSON_THROW_ON_ERROR));
        $row = $this->row($fact, $payload);
        $golden = '{"entity_stable_key":"room:1","type":"area","value":{"value":"7.94"},"unit":"m2","confidence":1.0,"origin":"document"}';
        self::assertSame(hash('sha256', $golden), $this->invoke($repository, 'factSemanticHash', $row));
    }

    #[Test]
    public function generic_metadata_round_trips_through_the_scoped_repository_reader_without_confirming_unknown_values(): void
    {
        foreach (['102.0000', null] as $value) {
            $fact = $this->parameter($value);
            $repository = new EloquentProjectModelRepository($this->createMock(DatabaseManager::class));
            $payload = $this->invoke($repository, 'factPayload', $fact);
            self::assertArrayHasKey('semantic_attributes', $payload);
            self::assertArrayNotHasKey('semanticAttributes', $payload);
            $row = $this->row($fact, $payload);
            $factQuery = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['join', 'where', 'first'])->getMock();
            $factQuery->method('join')->willReturnSelf();
            $where = [];
            $factQuery->method('where')->willReturnCallback(function (...$arguments) use (&$where, $factQuery) {
                $where[] = array_slice($arguments, 0, 2);

                return $factQuery;
            });
            $factQuery->method('first')->willReturn($row);
            $evidenceQuery = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()
                ->onlyMethods(['join', 'where', 'whereNull', 'whereColumn', 'orderBy', 'pluck'])->getMock();
            foreach (['join', 'where', 'whereNull', 'whereColumn', 'orderBy'] as $method) {
                $evidenceQuery->method($method)->willReturnSelf();
            }
            $evidenceQuery->method('pluck')->willReturn(new Collection([1]));
            $database = $this->getMockBuilder(DatabaseManager::class)->disableOriginalConstructor()->onlyMethods(['__call'])->getMock();
            $database->method('__call')->willReturnCallback(static function (string $method, array $arguments) use ($factQuery, $evidenceQuery): Builder {
                self::assertSame('table', $method);

                return match ($arguments[0]) {
                    'estimate_generation_project_model_assertions as fact' => $factQuery,
                    'estimate_generation_project_model_fact_evidence as binding' => $evidenceQuery,
                };
            });
            $actual = (new EloquentProjectModelRepository($database))->fact(1, 2, 3, $fact->id);
            self::assertNotNull($actual);
            self::assertSame($value, $actual->value);
            self::assertSame($fact->status, $actual->status);
            self::assertSame($fact->semanticAttributes, $actual->semanticAttributes);
            foreach ([['fact.organization_id', 1], ['fact.project_id', 2], ['fact.session_id', 3], ['fact.stable_key', $fact->id]] as $scope) {
                self::assertContains($scope, $where);
            }
        }
    }

    #[Test]
    public function metadata_is_canonical_in_fingerprints_and_exact_replay_detects_drift(): void
    {
        $repository = new EloquentProjectModelRepository($this->createMock(DatabaseManager::class));
        $fact = $this->parameter('102');
        $payload = $this->invoke($repository, 'factPayload', $fact);
        $row = $this->row($fact, $payload);
        $hash = $this->invoke($repository, 'factSemanticHash', $row);
        $token = $this->tokenWithRow($row);
        $reordered = $payload;
        $reordered['semantic_attributes']['engineering_parameter'] = array_reverse($reordered['semantic_attributes']['engineering_parameter'], true);
        $reordered['semantic_attributes']['engineering_parameter']['binding'] = array_reverse($reordered['semantic_attributes']['engineering_parameter']['binding'], true);
        self::assertSame($hash, $this->invoke($repository, 'factSemanticHash', $this->row($fact, $reordered)));
        self::assertSame($token, $this->tokenWithRow($this->row($fact, $reordered)));
        $this->invoke($repository, 'assertExactFactReplay', $fact, $row, 7, $payload);
        $drift = $payload;
        $drift['semantic_attributes']['engineering_parameter']['definition_source']['evidence_fingerprint'] = str_repeat('f', 64);
        $changed = $this->row($fact, $drift);
        self::assertNotSame($hash, $this->invoke($repository, 'factSemanticHash', $changed));
        self::assertNotSame($token, $this->tokenWithRow($changed));
        $this->expectException(ProjectModelFactIdentityCollision::class);
        $this->invoke($repository, 'assertExactFactReplay', $fact, $changed, 7, $payload);
    }

    #[Test]
    public function two_named_parameters_of_the_same_entity_coexist_in_the_atomic_source_model(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $first = $this->parameter('102', status: 'unresolved');
        $second = $this->parameter('200', 'Другой размер', 'unresolved');
        $entity = new Entity('equipment:1', 1, 2, 3, $first->sourceVersion, 'equipment', 'equipment:1');
        $evidence = new Evidence('evidence:1', 1, 2, 3, $first->sourceVersion, 'document:1', 'document', 1);
        $repository->saveSourceModel([$entity], [$first, $second], [$evidence]);
        $facts = $repository->currentFacts(1, 2, 3, $entity->id);
        self::assertCount(2, $facts);
        self::assertEqualsCanonicalizing(['102', '200'], array_column($facts, 'value'));
        self::assertCount(2, array_unique(array_column($facts, 'type')));
        self::assertSame([$entity->id], array_values(array_unique(array_column($facts, 'entityId'))));
    }

    private function parameter(?string $value, string $name = 'Размер B', string $status = 'candidate'): Fact
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $attributes = FactVocabulary::engineeringParameterAttributes(1, 2, 3,
            ['entity_id' => 'equipment:1', 'entity_source_version' => $source, 'work_key' => 'generic.install', 'work_binding_hash' => str_repeat('c', 64)],
            $name, 'observed_value', ['evidence_id' => 'evidence:1', 'source_version' => $source, 'evidence_fingerprint' => str_repeat('b', 64)]);

        return new Fact('fact:parameter:'.substr(hash('sha256', $name), 0, 32), 1, 2, 3, $source, 'equipment:1', FactVocabulary::engineeringParameterType($attributes),
            $value, $value === null ? null : 'mm', 0.0, $value === null ? 'unresolved' : 'user_input',
            $value === null ? 'unresolved' : $status, ['evidence:1'], semanticAttributes: $attributes);
    }

    private function row(Fact $fact, array $payload): object
    {
        return (object) ['id' => 8, 'entity_id' => 7, 'stable_key' => $fact->id, 'organization_id' => $fact->organizationId,
            'project_id' => $fact->projectId, 'session_id' => $fact->sessionId, 'source_version' => $fact->sourceVersion,
            'entity_stable_key' => $fact->entityId, 'assertion_type' => $fact->type, 'fact_value' => json_encode(['value' => $fact->value]),
            'fact_unit' => $fact->unit, 'confidence' => $fact->confidence, 'fact_origin' => $fact->origin, 'fact_status' => $fact->status,
            'fact_version' => $fact->version, 'supersedes_assertion_id' => null, 'payload' => json_encode($payload),
            'entity_database_id' => 7, 'projection_id' => 10, 'projection_version' => $fact->version];
    }

    private function tokenWithRow(object $row): string
    {
        $database = $this->getMockBuilder(DatabaseManager::class)->disableOriginalConstructor()->onlyMethods(['__call'])->getMock();
        $database->method('__call')->willReturnCallback(function (string $method, array $arguments) use ($row): Builder {
            self::assertSame('table', $method);
            $builder = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()
                ->onlyMethods(['join', 'where', 'whereIn', 'whereNull', 'orderBy', 'orderByDesc', 'groupBy', 'selectRaw', 'get'])->getMock();
            foreach (['join', 'where', 'whereIn', 'whereNull', 'orderBy', 'orderByDesc', 'groupBy', 'selectRaw'] as $chain) {
                $builder->method($chain)->willReturnSelf();
            }
            $builder->method('get')->willReturn(new Collection($arguments[0] === 'estimate_generation_project_model_fact_projections as projection' ? [$row] : []));

            return $builder;
        });

        return $this->invoke(new EloquentProjectModelRepository($database), 'understandingSnapshotToken', 1, 2, 3);
    }

    private function invoke(EloquentProjectModelRepository $repository, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($repository, $method))->invoke($repository, ...$arguments);
    }

    #[Test]
    public function repository_has_one_atomic_write_and_versioned_read_boundary(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('room:1', 1, 2, 3, $source, 'room', 'room:1', ['name' => 'room']);
        $fact = new Fact('fact:1', 1, 2, 3, $source, 'room:1', 'area', '7.94', 'm2', 1, 'document', 'confirmed', ['evidence:1']);

        $this->expectException(InvalidArgumentException::class);
        $repository->saveSourceModel([$entity], [$fact], []);
    }

    #[Test]
    public function repository_interface_does_not_expose_fragmented_entity_fact_or_decision_writers(): void
    {
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendEntities'));
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendFacts'));
        self::assertFalse(method_exists(ProjectModelRepository::class, 'appendDecisions'));
    }
}
