<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

final readonly class PublicCoreRuntimeReadiness
{
    public function __construct(private RegisteredPublicFixtureRegistry $registry)
    {
    }

    public function resolve(): array
    {
        return [
            'schema_version' => 'public-core-runtime-api/1',
            'mode' => 'public_core_test',
            'data_scope' => 'registered_public_fixture',
            'status' => 'unavailable',
            'reason_code' => 'runtime_not_activated',
            'source_contract_version' => 'public-core-authority/0.1-candidate',
            'actual_model' => null,
            'model_enabled' => false,
            'capabilities' => ['text' => false, 'tools' => false, 'vision' => false],
            'free_input_enabled' => false,
            'uploads_enabled' => false,
            'actions_enabled' => false,
            'private_ready' => false,
            'fixtures' => $this->registry->catalog(),
        ];
    }
}
