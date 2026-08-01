<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionChainProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelCorrectionChainProjectorTest extends TestCase
{
    #[Test]
    public function it_projects_only_the_latest_active_append_only_correction_and_revert_restores_the_base_value(): void
    {
        $projector = new ProjectModelCorrectionChainProjector;
        $apply = $this->row('apply', ['value' => 12.5, 'unit' => 'm2'], ['value' => 19.5, 'unit' => 'm2'], 'a');
        $revert = $this->row('revert', ['value' => 19.5, 'unit' => 'm2'], ['value' => 12.5, 'unit' => 'm2'], 'b');

        $afterApply = $projector->project([$apply]);
        $afterRevert = $projector->project([$apply, $revert]);

        self::assertSame(19.5, $afterApply[0]['value']['value']);
        self::assertSame([], $afterRevert);
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $next @return array<string, mixed> */
    private function row(string $operation, array $previous, array $next, string $suffix): array
    {
        return [
            'correction_stable_key' => 'correction:'.str_repeat($suffix, 64),
            'correction_payload' => [
                'canonical_value' => $next,
                'audit' => [
                    'schema_version' => 'project-model-correction:v1',
                    'operation' => $operation,
                    'previous_canonical_value' => $previous,
                    'new_canonical_value' => $next,
                ],
            ],
            'assertion_stable_key' => 'assertion:room-1:area',
            'assertion_type' => 'area',
            'assertion_payload' => ['value' => 12.5, 'unit' => 'm2', 'source' => 'cad'],
            'entity_stable_key' => 'room-1',
        ];
    }
}
