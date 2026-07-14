<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use DomainException;
use Illuminate\Database\Connection;

final readonly class EloquentEffectiveSettingsOperationStore implements EffectiveSettingsOperationStore
{
    public function __construct(private Connection $database) {}

    public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
    {
        $pinned = $this->database->selectOne(
            'SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)',
            [$correlationId, $organizationId, $sessionId],
        );
        if (! is_object($pinned)) {
            throw new DomainException('estimate_generation_operation_settings_pin_failed');
        }

        return new EffectiveSettingsPair(
            $this->load((int) $pinned->global_snapshot_id, $organizationId),
            $this->load((int) $pinned->effective_snapshot_id, $organizationId),
        );
    }

    private function load(int $snapshotId, int $workOrganizationId): EffectiveEstimateGenerationSettings
    {
        $row = $this->database->table('estimate_generation_setting_snapshots as snapshots')
            ->join('estimate_generation_setting_snapshot_hashes as hashes', static function ($join): void {
                $join->on('hashes.setting_snapshot_id', '=', 'snapshots.id')
                    ->where('hashes.algorithm', '=', 'jcs-sha256-v1');
            })
            ->where('snapshots.id', $snapshotId)
            ->select(['snapshots.id', 'snapshots.scope', 'snapshots.organization_id', 'snapshots.version', 'snapshots.snapshot', 'hashes.snapshot_hash'])
            ->first();
        if (! is_object($row)) {
            throw new DomainException('estimate_generation_operation_settings_snapshot_missing');
        }
        $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true, 64, JSON_THROW_ON_ERROR) : $row->snapshot;

        return EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => (int) $row->id,
            'scope' => (string) $row->scope,
            'organization_id' => $row->organization_id === null ? null : (int) $row->organization_id,
            'version' => (int) $row->version,
            'snapshot_hash' => (string) $row->snapshot_hash,
            'snapshot' => $snapshot,
        ], $workOrganizationId);
    }
}
