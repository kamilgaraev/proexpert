<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class EloquentReportSavedViewStore implements ReportSavedViewStore
{
    public function list(int $organizationId, int $ownerId, ReportSavedViewWindow $window): ReportSavedViewPage
    {
        $q = ReportSavedViewRecord::query()->where('organization_id', $organizationId)->where(fn ($q) => $q->where('owner_id', $ownerId)->orWhere('visibility', 'organization'))->when($window->reportCode !== null, fn ($q) => $q->where('report_code', $window->reportCode))->orderBy('created_at')->orderBy('id');
        if ($window->cursor !== null) {
            [$at,$id] = explode('|', $window->cursor, 2);
            $q->where(fn ($q) => $q->where('created_at', '>', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '>', $id)));
        } $rows = $q->limit($window->limit + 1)->get();
        $has = $rows->count() > $window->limit;
        $items = $rows->take($window->limit)->map(fn ($r) => $this->dto($r))->all();
        $last = $items[array_key_last($items)] ?? null;

        return new ReportSavedViewPage($items, $has && $last instanceof ReportSavedView ? $last->createdAt->format(DATE_ATOM).'|'.$last->id : null, $window->limit, $has);
    }

    public function getVisible(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        return $this->dto($this->visible($organizationId, $ownerId, $id));
    }

    public function create(int $organizationId, int $ownerId, CreateReportSavedViewData $data, string $contractVersion): ReportSavedView
    {
        return DB::transaction(function () use ($organizationId, $ownerId, $data, $contractVersion) {
            if ($data->isDefault) {
                ReportSavedViewRecord::query()
                    ->where('organization_id', $organizationId)
                    ->where('owner_id', $ownerId)
                    ->where('report_code', $data->reportCode)
                    ->lockForUpdate()
                    ->update(['is_default' => false]);
            } $r = ReportSavedViewRecord::query()->create(['id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'owner_id' => $ownerId, 'report_code' => $data->reportCode, 'contract_version' => $contractVersion, 'name' => $data->name, 'visibility' => $data->visibility, 'filters_json' => $data->filters->values, 'comparison_json' => $data->comparison, 'sort_json' => ['field' => $data->sort->field, 'direction' => $data->sort->direction->value], 'columns_json' => $data->columns, 'status' => 'active', 'is_default' => $data->isDefault]);

            return $this->dto($r);
        });
    }

    public function updateLocked(int $organizationId, int $ownerId, string $id, UpdateReportSavedViewData $data): ReportSavedView
    {
        return DB::transaction(function () use ($organizationId, $ownerId, $id, $data) {
            $r = $this->owned($organizationId, $ownerId, $id, true);
            $changes = $data->changes;
            if (isset($changes['filters']) && $changes['filters'] instanceof ReportFilterSet) {
                $changes['filters_json'] = $changes['filters']->values;
            } unset($changes['filters']);
            if (isset($changes['sort']) && $changes['sort'] instanceof ReportWindowSort) {
                $changes['sort_json'] = ['field' => $changes['sort']->field, 'direction' => $changes['sort']->direction->value];
                unset($changes['sort']);
            } if (isset($changes['columns'])) {
                $changes['columns_json'] = $changes['columns'];
                unset($changes['columns']);
            } $r->fill($changes);
            $r->save();

            return $this->dto($r->fresh());
        });
    }

    public function setDefaultLocked(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        return DB::transaction(function () use ($organizationId, $ownerId, $id) {
            $r = $this->owned($organizationId, $ownerId, $id, true);
            ReportSavedViewRecord::query()->where('organization_id', $organizationId)->where('owner_id', $ownerId)->where('report_code', $r->report_code)->lockForUpdate()->update(['is_default' => false]);
            $r->is_default = true;
            $r->save();

            return $this->dto($r);
        });
    }

    public function softDeleteLocked(int $organizationId, int $ownerId, string $id): void
    {
        DB::transaction(function () use ($organizationId, $ownerId, $id) {
            $this->owned($organizationId, $ownerId, $id, true)->delete();
        });
    }

    public function markNeedsMigrationLocked(int $organizationId, string $id): ReportSavedView
    {
        return DB::transaction(function () use ($organizationId, $id): ReportSavedView {
            $record = ReportSavedViewRecord::query()
                ->where('organization_id', $organizationId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (! $record instanceof ReportSavedViewRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            if ($record->status !== 'needs_migration') {
                $record->status = 'needs_migration';
                $record->save();
            }

            return $this->dto($record);
        });
    }

    private function visible(int $organizationId, int $ownerId, string $id, bool $locked = false): ReportSavedViewRecord
    {
        $q = ReportSavedViewRecord::query()->where('organization_id', $organizationId)->where('id', $id)->where(fn ($q) => $q->where('owner_id', $ownerId)->orWhere('visibility', 'organization'));
        if ($locked) {
            $q->lockForUpdate();
        }$r = $q->first();
        if (! $r instanceof ReportSavedViewRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $r;
    }

    private function owned(int $organizationId, int $ownerId, string $id, bool $locked = false): ReportSavedViewRecord
    {
        $query = ReportSavedViewRecord::query()
            ->where('organization_id', $organizationId)
            ->where('owner_id', $ownerId)
            ->where('id', $id);
        if ($locked) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if (! $record instanceof ReportSavedViewRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $record;
    }

    private function dto(ReportSavedViewRecord $r): ReportSavedView
    {
        $created = $r->created_at;
        $updated = $r->updated_at;
        if (! $created instanceof DateTimeInterface || ! $updated instanceof DateTimeInterface) {
            throw new LogicException('report_saved_view_timestamp_invalid');
        }$sort = is_array($r->sort_json) ? $r->sort_json : [];

        return new ReportSavedView($r->id, $r->report_code, $r->contract_version, $r->name, $r->visibility, new ReportFilterSet(is_array($r->filters_json) ? $r->filters_json : []), is_array($r->comparison_json) ? $r->comparison_json : [], new ReportWindowSort((string) ($sort['field'] ?? ''), ReportSortDirection::from((string) ($sort['direction'] ?? ''))), is_array($r->columns_json) ? $r->columns_json : [], $r->status, (bool) $r->is_default, DateTimeImmutable::createFromInterface($created), DateTimeImmutable::createFromInterface($updated));
    }
}
