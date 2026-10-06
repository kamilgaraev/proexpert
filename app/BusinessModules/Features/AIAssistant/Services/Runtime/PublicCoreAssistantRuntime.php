<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\Models\User;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use LogicException;

class PublicCoreAssistantRuntime
{
    private const REGISTRY_DIGEST = 'f6bfc3c523c792ab9a80dbe2c3a950aebec1dfad65456243bbaadfcdb50a85fe';

    private readonly RegisteredPublicFixtureRegistry $registry;

    public function __construct()
    {
        $this->registry = RegisteredPublicFixtureRegistry::compiled();
    }

    public function readiness(User $viewer, int $organizationId): array
    {
        $state = PublicCoreRuntimeResource::unavailable();
        if (!$this->registryAccepted()) {
            return PublicCoreRuntimeResource::unavailable('source_unavailable');
        }
        $fixtures = [];
        foreach ($this->registry->catalog() as $row) {
            if ($row['input_id'] !== $row['scenario_step']) {
                return PublicCoreRuntimeResource::unavailable('source_unavailable');
            }
            $id = $row['fixture_id'];
            $fixtures[$id] ??= ['fixture_id' => $id, 'fixture_version' => $row['fixture_version'],
                'label' => $row['display_text'], 'inputs' => []];
            $fixtures[$id]['inputs'][] = ['input_id' => $row['input_id'], 'label' => $row['display_text']];
        }
        $state['fixtures'] = array_values($fixtures);

        return $state;
    }

    public function submit(User $viewer, int $organizationId, array $command): array
    {
        if (!$this->registryAccepted() || !is_string($command['fixture_id'] ?? null)
            || !is_string($command['fixture_version'] ?? null) || !is_string($command['input_id'] ?? null)
            || $this->registry->resolve($command['fixture_id'], $command['fixture_version'], $command['input_id']) === null) {
            return PublicCoreRuntimeResource::blocked('source_unavailable');
        }

        return PublicCoreRuntimeResource::blocked();
    }

    public function poll(User $viewer, int $organizationId, string $requestRef): array
    {
        return PublicCoreRuntimeResource::blocked();
    }

    public function dispatchOwnedRequest(string $requestRef): void
    {
        throw new LogicException('runtime_not_activated');
    }

    private function registryAccepted(): bool
    {
        return hash_equals(self::REGISTRY_DIGEST, $this->registry->manifestDigest());
    }
}
