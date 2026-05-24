<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RagIndexStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'ready' => (bool) ($payload['ready'] ?? false),
            'source_count' => (int) ($payload['source_count'] ?? 0),
            'chunk_count' => (int) ($payload['chunk_count'] ?? 0),
            'latest_run' => self::runPayload($payload['latest_run'] ?? null),
            'last_successful_run' => self::runPayload($payload['last_successful_run'] ?? null),
            'last_failed_run' => self::runPayload($payload['last_failed_run'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function runPayload(mixed $run): ?array
    {
        if (! $run instanceof RagIndexRun) {
            return null;
        }

        return [
            'id' => $run->id,
            'organization_id' => $run->organization_id,
            'project_id' => $run->project_id,
            'source_type' => $run->source_type,
            'status' => $run->status,
            'mode' => $run->mode,
            'queued_at' => $run->queued_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'duration_ms' => $run->duration_ms,
            'indexed_chunks' => $run->indexed_chunks,
            'source_count' => $run->source_count,
            'chunk_count' => $run->chunk_count,
            'last_error' => $run->last_error,
        ];
    }
}
