<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use PHPUnit\Framework\TestCase;

class RagSourceRegistryTest extends TestCase
{
    public function test_returns_enabled_collectors_by_source_type(): void
    {
        $registry = new RagSourceRegistry([
            new FakeRagSourceCollector('project', true),
            new FakeRagSourceCollector('schedule', false),
        ]);

        $this->assertSame(['project'], array_keys($registry->enabledCollectors()));
        $this->assertSame('project', $registry->collector('project')?->sourceType());
        $this->assertNull($registry->collector('missing'));
    }
}

final class FakeRagSourceCollector implements RagSourceCollectorInterface
{
    public function __construct(
        private readonly string $sourceType,
        private readonly bool $enabled
    ) {
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        return [];
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return [];
    }
}
