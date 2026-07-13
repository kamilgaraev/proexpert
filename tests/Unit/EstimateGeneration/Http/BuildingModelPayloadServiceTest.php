<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\BuildingModelPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\BuildingModelReadDataSource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildingModelPayloadServiceTest extends TestCase
{
    #[Test]
    public function it_paginates_exact_decimal_quantities_and_preserves_closed_source_semantics(): void
    {
        $service = new BuildingModelPayloadService(new FakeBuildingModelReadDataSource(
            $this->model(),
            [
                11 => $this->evidence(11, 'document_unit', 'measured', ['quantity' => 12.5, 'unit' => 'm2', 'method' => 'geometry'], '0.970000'),
                12 => $this->evidence(12, 'user_input', 'measured', ['quantity' => 12.5, 'unit' => 'm2', 'method' => 'user_confirmed'], '1.000000'),
            ],
        ));

        $payload = $service->handle($this->generationSession(), 1, 1);

        self::assertSame('12.500000', $payload['quantities']['data'][0]['amount']);
        self::assertSame('m2', $payload['quantities']['data'][0]['unit']);
        self::assertSame('user_confirmed', $payload['quantities']['data'][0]['source']);
        self::assertSame(1, $payload['quantities']['meta']['current_page']);
        self::assertSame(2, $payload['quantities']['meta']['total']);
        self::assertSame(2, $payload['quantities']['meta']['last_page']);
        self::assertSame('confirmed', $payload['quantities']['data'][0]['status']);
        self::assertSame('0.970000', $payload['quantities']['data'][0]['confidence']);
    }

    #[Test]
    public function it_returns_only_safe_evidence_fields_without_locator_or_private_source_reference(): void
    {
        $service = new BuildingModelPayloadService(new FakeBuildingModelReadDataSource(
            $this->model(),
            [11 => $this->evidence(11, 'document_unit', 'measured', ['quantity' => 12.5, 'unit' => 'm2', 'method' => 'geometry'], '0.970000')],
            [91 => 'План первого этажа.pdf'],
        ));

        $payload = $service->evidence($this->generationSession(), 11);

        self::assertSame(11, $payload['id']);
        self::assertSame(['id' => 91, 'filename' => 'План первого этажа.pdf', 'page_number' => 3], $payload['document']);
        self::assertSame(['name' => 'quantity', 'value' => '12.5', 'unit' => 'm2', 'method' => 'geometry'], $payload['source_value']);
        self::assertSame([
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'producer_name' => 'drawing_analyzer',
            'producer_version' => 'model:v1',
        ], $payload['transformation']);
        self::assertNull($payload['preview']);
        self::assertArrayNotHasKey('locator', $payload);
        self::assertArrayNotHasKey('source_ref', $payload);
        self::assertStringNotContainsString('secret-object-key', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function generationSession(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill(['id' => 7, 'organization_id' => 2, 'project_id' => 3, 'state_version' => 9]);
        $session->exists = true;

        return $session;
    }

    /** @return array<string, mixed> */
    private function model(): array
    {
        return (new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
            new FloorData('floor-1', 0.0, 2.8, [
                new RoomData('room-1', 'Кухня', [[0.0, 0.0], [5.0, 0.0], [5.0, 2.5], [0.0, 2.5]], [11, 12], 0.97, 'confirmed'),
            ], [], [], [], [11, 12], 0.97, 'confirmed'),
        ], [], 'building-model:v1'))->toArray();
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function evidence(int $id, string $sourceType, string $type, array $value, string $confidence): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'source_type' => $sourceType,
            'source_ref' => 'document:91',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'locator' => [
                'document_id' => 91,
                'page' => 3,
                'bbox' => [1, 2, 3, 4],
                'source_key' => 'secret-object-key',
            ],
            'value' => $value,
            'confidence' => $confidence,
            'producer_name' => 'drawing_analyzer',
            'producer_version' => 'model:v1',
            'invalidated_at' => null,
        ];
    }
}

final class FakeBuildingModelReadDataSource implements BuildingModelReadDataSource
{
    /** @param array<string, mixed> $model @param array<int, array<string, mixed>> $evidence @param array<int, string> $documents */
    public function __construct(
        private array $model,
        private array $evidence,
        private array $documents = [],
    ) {}

    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array
    {
        return ['content_version' => 'sha256:'.str_repeat('b', 64), 'model' => $this->model];
    }

    public function evidenceForIds(int $organizationId, int $projectId, int $sessionId, array $ids): array
    {
        return array_intersect_key($this->evidence, array_flip($ids));
    }

    public function evidence(int $organizationId, int $projectId, int $sessionId, int $evidenceId): ?array
    {
        return $this->evidence[$evidenceId] ?? null;
    }

    public function documentNames(int $organizationId, int $projectId, int $sessionId, array $documentIds): array
    {
        return array_intersect_key($this->documents, array_flip($documentIds));
    }
}
