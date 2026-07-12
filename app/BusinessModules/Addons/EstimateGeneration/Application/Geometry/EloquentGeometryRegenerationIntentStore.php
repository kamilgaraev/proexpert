<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class EloquentGeometryRegenerationIntentStore implements GeometryRegenerationIntentStore
{
    public function __construct(private ConnectionInterface $database, private EstimateGenerationRetryDispatcher $dispatcher) {}

    public function append(GeometryRegenerationIntent $intent): int
    {
        $this->database->table('estimate_generation_geometry_regeneration_outbox')->insertOrIgnore([
            'organization_id' => $intent->organizationId, 'project_id' => $intent->projectId, 'session_id' => $intent->sessionId,
            'state_version' => $intent->stateVersion, 'previous_input_version' => $intent->previousInputVersion,
            'input_version' => $intent->inputVersion, 'model_version' => $intent->modelVersion,
            'generation_attempt_id' => $intent->generationAttemptId, 'idempotency_key' => $intent->idempotencyKey,
            'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $this->database->table('estimate_generation_geometry_regeneration_outbox')
            ->where('idempotency_key', $intent->idempotencyKey)->value('id');
    }

    public function deliver(int $intentId): void
    {
        $row = $this->database->table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)->first();
        if ($row === null || $row->status === 'delivered') {
            return;
        }
        $claimed = $this->database->table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)
            ->whereIn('status', ['pending', 'failed'])->where('available_at', '<=', now())
            ->update(['status' => 'delivering', 'attempt_count' => (int) $row->attempt_count + 1,
                'available_at' => now()->addMinutes(5), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        try {
            $this->dispatcher->dispatchGeneration((int) $row->session_id, (int) $row->state_version, (string) $row->generation_attempt_id);
            $this->database->table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)
                ->where('status', 'delivering')->update(['status' => 'delivered', 'delivered_at' => now(),
                    'last_error_code' => null, 'updated_at' => now()]);
        } catch (Throwable) {
            $this->database->table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)
                ->where('status', 'delivering')->update(['status' => 'failed',
                    'last_error_code' => 'dispatch_failed', 'available_at' => now()->addMinutes(5), 'updated_at' => now()]);
        }
    }

    public function recover(int $limit = 100): int
    {
        $this->database->table('estimate_generation_geometry_regeneration_outbox')->where('status', 'delivering')
            ->where('available_at', '<=', now())->update(['status' => 'failed', 'last_error_code' => 'delivery_lease_expired', 'updated_at' => now()]);
        $ids = $this->database->table('estimate_generation_geometry_regeneration_outbox')->whereIn('status', ['pending', 'failed'])
            ->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $this->deliver((int) $id);
        }

        return $ids->count();
    }
}
