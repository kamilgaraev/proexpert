<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use Illuminate\Database\ConnectionInterface;

final class GeometryDependencyInvalidator
{
    public function __construct(private ConnectionInterface $database) {}

    /** @return array{evidence:int,checkpoints:int,processing_units:int,packages:int,package_items:int} */
    public function invalidate(int $sessionId, string $inputVersion, int $invalidationVersion): array
    {
        $roots = $this->database->table('estimate_generation_evidence')->where('session_id', $sessionId)
            ->whereNull('invalidated_at')->where('source_type', 'pipeline')->where('source_version', $inputVersion)->pluck('id');
        $ids = $roots;
        if ($roots->isNotEmpty()) {
            $descendants = $this->database->select('WITH RECURSIVE descendants(id) AS (
                SELECT child_id FROM estimate_generation_evidence_edges WHERE session_id = ? AND parent_id IN ('.implode(',', array_fill(0, $roots->count(), '?')).')
                UNION SELECT edge.child_id FROM estimate_generation_evidence_edges edge JOIN descendants tree ON tree.id = edge.parent_id WHERE edge.session_id = ?
            ) SELECT id FROM descendants', [$sessionId, ...$roots->all(), $sessionId]);
            $ids = $roots->merge(array_map(static fn (object $row): int => (int) $row->id, $descendants))->unique();
        }
        $now = now();
        $evidence = $this->database->table('estimate_generation_evidence')->where('session_id', $sessionId)
            ->whereNull('invalidated_at')->whereIn('id', $ids->all())->update([
                'invalidated_at' => $now, 'invalidation_reason' => 'geometry_confirmed',
                'invalidation_version' => $invalidationVersion, 'updated_at' => $now,
            ]);
        $checkpoints = $this->database->table('estimate_generation_pipeline_checkpoints')->where('session_id', $sessionId)
            ->whereNull('invalidated_at')->where('input_version', $inputVersion)->update([
                'status' => 'invalidated', 'invalidated_at' => $now, 'invalidation_reason' => 'dependency_changed', 'updated_at' => $now,
            ]);
        $units = $this->database->table('estimate_generation_processing_units')->where('session_id', $sessionId)
            ->where('source_version', $inputVersion)->whereNotIn('status', ['superseded'])->update([
                'status' => 'superseded', 'claim_token' => null, 'lease_expires_at' => null,
                'output_version' => null, 'updated_at' => $now,
            ]);
        $packages = $this->database->table('estimate_generation_packages')->where('session_id', $sessionId)
            ->where('input_version', $inputVersion)->get();
        $packageCount = 0;
        $itemCount = 0;
        foreach ($packages as $package) {
            $metadata = $this->decode($package->metadata ?? null);
            if (($metadata['superseded_at'] ?? null) !== null) {
                continue;
            }
            $metadata['superseded_at'] = $now->toIso8601String();
            $metadata['invalidation_reason'] = 'geometry_confirmed';
            $metadata['invalidation_version'] = $invalidationVersion;
            $packageCount += $this->database->table('estimate_generation_packages')->where('id', $package->id)
                ->update(['status' => 'superseded', 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
            $items = $this->database->table('estimate_generation_package_items')->where('package_id', $package->id)->get();
            foreach ($items as $item) {
                $itemMetadata = $this->decode($item->metadata ?? null);
                $itemMetadata['superseded_at'] = $now->toIso8601String();
                $itemMetadata['invalidation_reason'] = 'geometry_confirmed';
                $itemMetadata['invalidation_version'] = $invalidationVersion;
                $itemCount += $this->database->table('estimate_generation_package_items')->where('id', $item->id)
                    ->update(['metadata' => json_encode($itemMetadata, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
            }
        }

        return ['evidence' => $evidence, 'checkpoints' => $checkpoints, 'processing_units' => $units,
            'packages' => $packageCount, 'package_items' => $itemCount];
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (array) json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
