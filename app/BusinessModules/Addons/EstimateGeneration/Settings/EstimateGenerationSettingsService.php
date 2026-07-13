<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EstimateGenerationSettingsService
{
    /** @return array{snapshot_id: int, version: int, idempotent_replay: bool} */
    public function change(int $actorId, EstimateGenerationSettingsData $data): array
    {
        $this->assertAllowed($actorId);
        $commandFingerprint = $data->commandFingerprint();

        return DB::transaction(function () use ($actorId, $data, $commandFingerprint): array {
            $scopeOrganizationId = $data->organizationId;
            DB::table('estimate_generation_setting_operations')->insertOrIgnore([
                'scope' => $data->scope,
                'organization_id' => $scopeOrganizationId,
                'idempotency_key' => $data->idempotencyKey,
                'command_fingerprint' => $commandFingerprint,
                'status' => 'pending',
                'result' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'completed_at' => null,
            ]);

            $operation = DB::table('estimate_generation_setting_operations')
                ->where('scope', $data->scope)
                ->where(function ($query) use ($scopeOrganizationId): void {
                    $scopeOrganizationId === null
                        ? $query->whereNull('organization_id')
                        : $query->where('organization_id', $scopeOrganizationId);
                })
                ->where('idempotency_key', $data->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! is_object($operation) || ! hash_equals((string) $operation->command_fingerprint, $commandFingerprint)) {
                throw new DomainException('estimate_generation_settings_idempotency_conflict');
            }
            if ((string) $operation->status === 'completed') {
                return $this->validatedReplay($operation->result);
            }

            $current = DB::table('estimate_generation_setting_snapshots')
                ->where('scope', $data->scope)
                ->where(function ($query) use ($scopeOrganizationId): void {
                    $scopeOrganizationId === null
                        ? $query->whereNull('organization_id')
                        : $query->where('organization_id', $scopeOrganizationId);
                })
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            $currentVersion = is_object($current) ? (int) $current->version : 0;
            if ($data->expectedVersion !== $currentVersion) {
                throw new DomainException('estimate_generation_settings_version_conflict');
            }

            $snapshot = $data->snapshot();
            $snapshotId = (int) DB::table('estimate_generation_setting_snapshots')->insertGetId([
                'scope' => $data->scope,
                'organization_id' => $scopeOrganizationId,
                'version' => $currentVersion + 1,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'daily_budget' => $data->budgets['daily'],
                'monthly_budget' => $data->budgets['monthly'],
                'currency' => $data->budgets['currency'],
                'created_by_system_admin_id' => $actorId,
                'created_at' => now(),
            ]);

            $oldSnapshot = $this->decodeSnapshot(is_object($current) ? $current->snapshot : null);
            $this->recordAudit($snapshotId, $actorId, $data, $oldSnapshot, $snapshot, $commandFingerprint);

            $result = ['snapshot_id' => $snapshotId, 'version' => $currentVersion + 1];
            $updated = DB::table('estimate_generation_setting_operations')
                ->where('id', $operation->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'completed',
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'completed_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('estimate_generation_settings_operation_lost');
            }

            return [...$result, 'idempotent_replay' => false];
        });
    }

    /** @return array{snapshot_id: int, version: int, snapshot: array<string, mixed>} */
    public function snapshotForNewWork(?int $organizationId): array
    {
        $snapshot = null;
        if ($organizationId !== null) {
            $snapshot = DB::table('estimate_generation_setting_snapshots')
                ->where('scope', 'organization')
                ->where('organization_id', $organizationId)
                ->orderByDesc('version')
                ->first();
        }
        $snapshot ??= DB::table('estimate_generation_setting_snapshots')
            ->where('scope', 'global')
            ->whereNull('organization_id')
            ->orderByDesc('version')
            ->first();
        if (! is_object($snapshot)) {
            throw new DomainException('estimate_generation_settings_snapshot_missing');
        }

        return [
            'snapshot_id' => (int) $snapshot->id,
            'version' => (int) $snapshot->version,
            'snapshot' => $this->decodeSnapshot($snapshot->snapshot),
        ];
    }

    /** @return array{snapshot_id: int, version: int, snapshot: array<string, mixed>}|null */
    public function currentSnapshot(string $scope, ?int $organizationId): ?array
    {
        if (! in_array($scope, ['global', 'organization'], true)
            || ($scope === 'global' && $organizationId !== null)
            || ($scope === 'organization' && ($organizationId === null || $organizationId <= 0))) {
            throw new DomainException('estimate_generation_settings_scope_invalid');
        }

        $snapshot = DB::table('estimate_generation_setting_snapshots')
            ->where('scope', $scope)
            ->where(function ($query) use ($organizationId): void {
                $organizationId === null
                    ? $query->whereNull('organization_id')
                    : $query->where('organization_id', $organizationId);
            })
            ->orderByDesc('version')
            ->first();
        if (! is_object($snapshot)) {
            return null;
        }

        return [
            'snapshot_id' => (int) $snapshot->id,
            'version' => (int) $snapshot->version,
            'snapshot' => $this->decodeSnapshot($snapshot->snapshot),
        ];
    }

    private function assertAllowed(int $actorId): void
    {
        $actor = SystemAdmin::query()->find($actorId);
        if (! $actor instanceof SystemAdmin
            || ! $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_SETTINGS)
            || ! $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_BUDGETS)) {
            throw new DomainException('estimate_generation_settings_forbidden');
        }
    }

    /**
     * @param  array<string, mixed>  $oldSnapshot
     * @param  array<string, mixed>  $newSnapshot
     */
    private function recordAudit(
        int $snapshotId,
        int $actorId,
        EstimateGenerationSettingsData $data,
        array $oldSnapshot,
        array $newSnapshot,
        string $commandFingerprint,
    ): void {
        foreach ($newSnapshot as $key => $newValue) {
            if ($key === 'schema_version') {
                continue;
            }
            $oldValue = $oldSnapshot[$key] ?? null;
            if ($oldValue === $newValue) {
                continue;
            }
            DB::table('estimate_generation_setting_audits')->insert([
                'setting_snapshot_id' => $snapshotId,
                'scope' => $data->scope,
                'organization_id' => $data->organizationId,
                'actor_system_admin_id' => $actorId,
                'key' => $key,
                'old_value' => json_encode($oldValue, JSON_THROW_ON_ERROR),
                'new_value' => json_encode($newValue, JSON_THROW_ON_ERROR),
                'command_fingerprint' => $commandFingerprint,
                'created_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeSnapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || strlen($value) > 65_536) {
            return [];
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{snapshot_id: int, version: int, idempotent_replay: bool} */
    private function validatedReplay(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded)
            || array_keys($decoded) !== ['snapshot_id', 'version']
            || ! is_int($decoded['snapshot_id'])
            || ! is_int($decoded['version'])
            || $decoded['snapshot_id'] <= 0
            || $decoded['version'] <= 0) {
            throw new DomainException('estimate_generation_settings_replay_invalid');
        }

        return [...$decoded, 'idempotent_replay' => true];
    }
}
