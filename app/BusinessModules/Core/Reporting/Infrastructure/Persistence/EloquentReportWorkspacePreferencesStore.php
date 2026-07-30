<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspaceDisplayPreferences;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportWorkspacePreferencesRecord;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentReportWorkspacePreferencesStore implements ReportWorkspacePreferencesStore
{
    public function get(int $organizationId, int $ownerId): ?ReportWorkspacePreferences
    {
        $record = ReportWorkspacePreferencesRecord::query()
            ->where('organization_id', $organizationId)
            ->where('owner_id', $ownerId)
            ->first();

        return $record instanceof ReportWorkspacePreferencesRecord ? $this->dto($record) : null;
    }

    public function updateLocked(int $organizationId, int $ownerId, Closure $change): ReportWorkspacePreferences
    {
        return DB::transaction(function () use ($organizationId, $ownerId, $change): ReportWorkspacePreferences {
            $timestamp = now();
            ReportWorkspacePreferencesRecord::query()->insertOrIgnore([
                'organization_id' => $organizationId,
                'owner_id' => $ownerId,
                'recent_report_codes' => json_encode([], JSON_THROW_ON_ERROR),
                'favourite_report_codes' => json_encode([], JSON_THROW_ON_ERROR),
                'display_preferences' => json_encode($this->displayPayload(ReportWorkspaceDisplayPreferences::defaults()), JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $record = ReportWorkspacePreferencesRecord::query()
                ->where('organization_id', $organizationId)
                ->where('owner_id', $ownerId)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportWorkspacePreferencesRecord) {
                throw new LogicException('report_workspace_preferences_lock_missing');
            }

            $next = $change($this->dto($record));
            if (! $next instanceof ReportWorkspacePreferences) {
                throw new LogicException('report_workspace_preferences_change_invalid');
            }

            $updated = ReportWorkspacePreferencesRecord::query()
                ->whereKey($record->getKey())
                ->where('organization_id', $organizationId)
                ->where('owner_id', $ownerId)
                ->update([
                    'recent_report_codes' => json_encode($next->recentReportCodes, JSON_THROW_ON_ERROR),
                    'favourite_report_codes' => json_encode($next->favouriteReportCodes, JSON_THROW_ON_ERROR),
                    'display_preferences' => json_encode($this->displayPayload($next->display), JSON_THROW_ON_ERROR),
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new LogicException('report_workspace_preferences_update_failed');
            }

            $stored = ReportWorkspacePreferencesRecord::query()
                ->whereKey($record->getKey())
                ->where('organization_id', $organizationId)
                ->where('owner_id', $ownerId)
                ->first();
            if (! $stored instanceof ReportWorkspacePreferencesRecord) {
                throw new LogicException('report_workspace_preferences_reload_missing');
            }

            return $this->dto($stored);
        });
    }

    private function dto(ReportWorkspacePreferencesRecord $record): ReportWorkspacePreferences
    {
        $display = is_array($record->display_preferences) ? $record->display_preferences : [];
        $updatedAt = $record->updated_at;
        if (! $updatedAt instanceof DateTimeInterface) {
            throw new LogicException('report_workspace_preferences_timestamp_invalid');
        }

        return new ReportWorkspacePreferences(
            is_array($record->recent_report_codes) ? $record->recent_report_codes : [],
            is_array($record->favourite_report_codes) ? $record->favourite_report_codes : [],
            new ReportWorkspaceDisplayPreferences(
                is_array($display['catalog_group_order'] ?? null) ? $display['catalog_group_order'] : [],
                is_array($display['collapsed_catalog_groups'] ?? null) ? $display['collapsed_catalog_groups'] : [],
                is_string($display['landing_section'] ?? null) ? $display['landing_section'] : '',
            ),
            DateTimeImmutable::createFromInterface($updatedAt),
        );
    }

    private function displayPayload(ReportWorkspaceDisplayPreferences $display): array
    {
        return [
            'catalog_group_order' => $display->catalogGroupOrder,
            'collapsed_catalog_groups' => $display->collapsedCatalogGroups,
            'landing_section' => $display->landingSection,
        ];
    }
}
