<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

final class RagSourceRegistry
{
    /**
     * @var array<string, RagSourceCollectorInterface>
     */
    private array $collectors = [];

    /**
     * @param  iterable<RagSourceCollectorInterface>  $collectors
     */
    public function __construct(iterable $collectors)
    {
        foreach ($collectors as $collector) {
            $this->collectors[$collector->sourceType()] = $collector;
        }
    }

    /**
     * @return array<string, RagSourceCollectorInterface>
     */
    public function enabledCollectors(): array
    {
        return array_filter(
            $this->collectors,
            static fn (RagSourceCollectorInterface $collector): bool => $collector->enabled()
        );
    }

    public function collector(string $sourceType): ?RagSourceCollectorInterface
    {
        return $this->collectors[$sourceType] ?? null;
    }
}
