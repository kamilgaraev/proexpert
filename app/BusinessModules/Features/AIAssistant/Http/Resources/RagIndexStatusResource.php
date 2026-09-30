<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Resources;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagDispatchIntent;
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
            'status_available' => (bool) ($payload['status_available'] ?? true),
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'ready' => (bool) ($payload['ready'] ?? false),
            'can_reindex' => (bool) ($payload['can_reindex'] ?? false),
            'can_manage_document_settings' => (bool) ($payload['can_manage_document_settings'] ?? false),
            'document_coverage' => is_array($payload['document_coverage'] ?? null) ? $payload['document_coverage'] : null,
            'archive_scan' => is_array($payload['archive_scan'] ?? null) ? $payload['archive_scan'] : null,
            'source_count' => ($payload['status_available'] ?? true) ? (int) ($payload['source_count'] ?? 0) : null,
            'chunk_count' => ($payload['status_available'] ?? true) ? (int) ($payload['chunk_count'] ?? 0) : null,
            'expected_source_count' => is_numeric($payload['expected_source_count'] ?? null)
                ? (int) $payload['expected_source_count']
                : null,
            'eligible_count_known' => (bool) ($payload['eligible_count_known'] ?? false),
            'indexed_source_count' => is_numeric($payload['indexed_source_count'] ?? null) ? (int) $payload['indexed_source_count'] : null,
            'stored_source_count' => $payload['stored_source_count'] ?? null,
            'stale_source_count' => $payload['stale_source_count'] ?? null,
            'coverage_snapshot_at' => $payload['snapshot_at'] ?? null,
            'pending_source_count' => $payload['pending_source_count'] ?? null,
            'coverage_complete' => (bool) ($payload['coverage_complete'] ?? false),
            'lag_seconds' => $payload['lag_seconds'] ?? null,
            'lag_exceeded' => (bool) ($payload['lag_exceeded'] ?? false),
            'processing' => (bool) ($payload['processing'] ?? false),
            'stale_after_seconds' => is_numeric($payload['stale_after_seconds'] ?? null)
                ? (int) $payload['stale_after_seconds']
                : null,
            'lag_goal_seconds' => is_numeric($payload['lag_goal_seconds'] ?? null)
                ? (int) $payload['lag_goal_seconds']
                : 300,
            'latest_run' => self::runPayload($payload['latest_run'] ?? null),
            'last_successful_run' => self::runPayload($payload['last_successful_run'] ?? null),
            'last_failed_run' => self::runPayload($payload['last_failed_run'] ?? null),
            'source_catalog' => self::sourceCatalogPayload($payload['source_catalog'] ?? []),
        ];
    }

    /**
     * @return array<int, array{type: string, enabled: bool, display_label?: string}>
     */
    private static function sourceCatalogPayload(mixed $catalog): array
    {
        if (! is_array($catalog)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $source): ?array {
                if (! is_array($source) || ! is_string($source['type'] ?? null)) {
                    return null;
                }

                $payload = [
                    'type' => $source['type'],
                    'enabled' => (bool) ($source['enabled'] ?? true),
                    'expected_count' => $source['expected_count'] ?? null,
                    'indexed_count' => $source['indexed_count'] ?? null,
                    'stored_count' => $source['stored_count'] ?? null,
                    'stale_count' => $source['stale_count'] ?? null,
                    'pending_count' => $source['pending_count'] ?? null,
                    'error' => $source['error'] ?? null,
                ];

                if (is_string($source['display_label'] ?? null) && trim($source['display_label']) !== '') {
                    $payload['display_label'] = trim($source['display_label']);
                }

                return $payload;
            },
            $catalog
        )));
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
            'last_error' => RagDispatchIntent::publicError($run->last_error),
            'expected_sources' => $run->expected_sources,
            'processed_sources' => $run->processed_sources,
            'heartbeat_at' => $run->heartbeat_at?->toISOString(),
            'lease_expires_at' => $run->lease_expires_at?->toISOString(),
            'scan_completed_at' => $run->scan_completed_at?->toISOString(),
        ];
    }
}
