<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

final class WorkEntityScopePolicy
{
    public const BINDING_CONTRACT = 'work-entity-binding:v2';

    private const TYPES = [
        'roof.rafters' => 'roof', 'roof.membrane' => 'roof', 'roof.battens' => 'roof',
        'roof.fascia' => 'roof', 'roof.bargeboard' => 'roof', 'site.apron' => 'site',
        'ceiling.brandering' => 'room', 'ceiling.insulation' => 'room', 'ceiling.cornice' => 'room',
        'ceiling.hatch' => 'room', 'sanitary.grab_rails' => 'room',
    ];

    public static function types(array $item): array
    {
        $work = $item['metadata']['composition_work_key'] ?? $item['work_key'] ?? $item['metadata']['quantity_key'] ?? null;
        if (is_string($work) && isset(self::TYPES[$work])) {
            return [self::TYPES[$work]];
        }

        return ($item['metadata']['generation_source'] ?? null) === 'accepted_supplementary_work' ? [] : ['room'];
    }
}
