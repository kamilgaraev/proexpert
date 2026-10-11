<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use Illuminate\Support\Str;

/** The confirmation signs a computed revision; numerical inputs remain the separate source and binding facts. */
final class SelectedScenarioConfirmationAnchor
{
    public static function identity(int $organizationId, int $projectId, int $sessionId): array
    {
        $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', [$organizationId, $projectId, $sessionId]));

        return ['source_version' => $version, 'entity_id' => 'entity:'.hash('sha256', $version.'|selected-scenario')];
    }

    public static function matches(Fact $fact): bool
    {
        return self::matchesIdentity($fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion,
            $fact->entityId, $fact->type, $fact->origin, $fact->status, $fact->value);
    }

    public static function matchesIdentity(int $organizationId, int $projectId, int $sessionId, string $sourceVersion,
        string $entityId, string $type, string $origin, string $status, mixed $value): bool
    {
        $anchor = self::identity($organizationId, $projectId, $sessionId);
        if ($sourceVersion !== $anchor['source_version'] || $entityId !== $anchor['entity_id'] || $type !== 'evaluation_scenario'
            || $origin !== 'user_input' || $status !== 'confirmed' || ! is_array($value)
            || ($value['answer_kind'] ?? null) !== 'selected_scenario' || ! Str::isUuid($value['revision_id'] ?? '')
            || ! in_array($value['scenario_id'] ?? null, ['minimum', 'base', 'maximum'], true)
            || ! is_bool($value['assumptions_accepted'] ?? null)) {
            return false;
        }
        foreach (['content_hash', 'input_hash', 'scenario_hash'] as $key) {
            if (! is_string($value[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $value[$key]) !== 1) {
                return false;
            }
        }

        return true;
    }
}
