<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use Tests\TestCase;

class RagSourceRegistryTest extends TestCase
{
    public function test_returns_enabled_collectors_by_source_type(): void
    {
        $registry = new RagSourceRegistry([
            new FakeRagSourceCollector('project', true),
            new FakeRagSourceCollector('schedule', false),
        ]);

        $this->assertSame(['project'], array_keys($registry->enabledCollectors()));
        $this->assertSame(['project'], $registry->enabledSourceTypes());
        $this->assertSame([['type' => 'project', 'enabled' => true]], $registry->sourceCatalog());
        $this->assertSame('project', $registry->collector('project')?->sourceType());
        $this->assertNull($registry->collector('missing'));
    }

    public function test_application_registry_contains_all_rag_sources(): void
    {
        $registry = $this->app->make(RagSourceRegistry::class);

        foreach ([
            'project',
            'schedule',
            'contract',
            'estimate',
            'estimate_reference',
            'procurement',
            'warehouse',
            'site_request',
            'work_completion',
            'construction_journal',
            'performance_act',
            'payment',
            'quality_executive_docs',
            'project_pulse',
            'safety',
            'machinery',
            'production_labor',
            'change_management',
            'handover_acceptance',
        ] as $sourceType) {
            $this->assertContains($sourceType, $registry->enabledSourceTypes());
        }

        $this->assertSame(
            $registry->enabledSourceTypes(),
            array_map(static fn (array $source): string => $source['type'], $registry->sourceCatalog())
        );
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
