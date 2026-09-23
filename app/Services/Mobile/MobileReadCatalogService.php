<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\ReportFile;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Organization\OrganizationContext;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

final class MobileReadCatalogService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly FileService $files,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function assertCanView(User $user, int $organizationId, string $module, array $permissions): void
    {
        $this->assertModuleAccess($organizationId, $module);

        foreach ($permissions as $permission) {
            if ($this->authorization->can($user, $permission, ['organization_id' => $organizationId])) {
                return;
            }
        }

        throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
    }

    public function assertModuleAccess(int $organizationId, string $module): void
    {
        if (!$this->access->hasModuleAccess($organizationId, $module)) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }
    }

    public function hasProjectPermission(User $user, int $organizationId, int $projectId, string $permission): bool
    {
        return $this->authorization->can($user, $permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ]);
    }

    public function allowedProjectIds(User $user, int $organizationId, string $permission): array
    {
        return array_values(array_filter(
            $this->accessibleProjectIds($user, $organizationId),
            fn (int $projectId): bool => $this->hasProjectPermission($user, $organizationId, $projectId, $permission),
        ));
    }

    public function crmListScope(User $user, int $organizationId, string $permission): array
    {
        $this->assertModuleAccess($organizationId, 'crm');
        $organizationAllowed = $this->hasPermission($user, $organizationId, $permission);
        $projectIds = $this->allowedProjectIds($user, $organizationId, $permission);
        if (!$organizationAllowed && $projectIds === []) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        return [
            'allowed_project_ids' => $projectIds,
            'include_projectless' => $organizationAllowed,
        ];
    }

    public function assertCrmRecordView(
        User $user,
        int $organizationId,
        string $permission,
        CrmDeal|CrmActivity $record,
    ): void {
        $this->assertModuleAccess($organizationId, 'crm');
        $deal = $record instanceof CrmDeal ? $record : $record->deal;
        if ($record instanceof CrmActivity && $record->deal_id !== null && $deal === null) {
            throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
        }
        if ($deal !== null && (int) $deal->organization_id !== $organizationId) {
            throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
        }
        $projectId = $deal?->project_id;
        if ($projectId !== null) {
            $this->assertProjectAccess($user, $organizationId, (int) $projectId);
            if ($this->hasProjectPermission($user, $organizationId, (int) $projectId, $permission)) {
                return;
            }
        } elseif ($this->hasPermission($user, $organizationId, $permission)) {
            return;
        }

        throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
    }

    public function assertProjectAccess(User $user, int $organizationId, int $projectId): void
    {
        $this->projectAccess->assert($user, $organizationId, $projectId, trans_message('mobile_companions.errors.item_not_found'));
    }

    public function accessibleProjectIds(User $user, int $organizationId): array
    {
        return $this->projectAccess->ids($user, $organizationId);
    }

    public function hasPermission(User $user, int $organizationId, string $permission): bool
    {
        return $this->authorization->can($user, $permission, ['organization_id' => $organizationId]);
    }

    /** @param array<string, mixed> $filters */
    public function reportFilesQuery(User $user, int $organizationId, array $filters = []): Builder
    {
        $query = ReportFile::query()->where(function (Builder $scope) use ($organizationId, $user): void {
            $scope->where('organization_id', $organizationId)
                ->orWhere(fn (Builder $global) => $global->whereNull('organization_id')->where('user_id', $user->id));
        });
        if (!empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $query->where(fn (Builder $scope) => $scope->where('filename', 'like', $search)->orWhere('name', 'like', $search));
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    public function findReportFile(User $user, int $organizationId, string $id): ReportFile
    {
        return $this->reportFilesQuery($user, $organizationId)->whereKey($id)->first()
            ?? throw new ModelNotFoundException();
    }

    /** @return array<string, mixed> */
    public function reportFilePayload(ReportFile $file, int $organizationId): array
    {
        $payload = $file->toArray();
        $payload['path'] = OrganizationStoragePath::displayPath($file->organization_id ?? $organizationId, (string) $file->path);
        $payload['download_url'] = null;
        try {
            $organization = OrganizationContext::getOrganization() ?? Organization::query()->find($file->organization_id ?? $organizationId);
            if ($organization !== null) {
                $disk = $this->files->disk($organization);
                if ($disk->exists((string) $file->path)) {
                    $payload['download_url'] = $disk->temporaryUrl((string) $file->path, now()->addHours(1));
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('mobile_catalog.report_download_url_failed', [
                'file_id' => $file->id,
                'organization_id' => $organizationId,
                'error' => $exception->getMessage(),
            ]);
        }

        return $payload;
    }
}
