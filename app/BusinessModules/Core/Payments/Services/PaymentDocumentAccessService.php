<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class PaymentDocumentAccessService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projects,
        private readonly ContractAccessService $contracts,
    ) {
    }

    public function query(int $organizationId, User $actor, string $permission): Builder
    {
        return $this->scope(PaymentDocument::forOrganization($organizationId), $organizationId, $actor, $permission);
    }

    public function scope(Builder $query, int $organizationId, User $actor, string $permission): Builder
    {
        $query->where($query->getModel()->qualifyColumn('organization_id'), $organizationId);

        return $this->scopeProjects($query, $organizationId, $actor, $permission);
    }

    public function scopeProjects(Builder $query, int $organizationId, User $actor, string $permission): Builder
    {
        if ($organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId) {
            return $query->whereRaw('1 = 0');
        }

        $query->useWritePdo()->whereExists($this->membership($actor, $organizationId));
        $projectQuery = $this->projects->queryAccessibleProjects($actor, $organizationId, true)
            ->useWritePdo()->select('projects.id');
        $organizationPermission = $this->hasPermission($actor, $permission, ['organization_id' => $organizationId]);
        if (! $organizationPermission) {
            $permitted = [];
            foreach ((clone $projectQuery)->pluck('projects.id') as $projectId) {
                AuthorizationContext::getProjectContext((int) $projectId, $organizationId);
                if ($this->hasPermission($actor, $permission, [
                    'organization_id' => $organizationId, 'project_id' => (int) $projectId, 'strict_project_scope' => true,
                ])) {
                    $permitted[] = (int) $projectId;
                }
            }
            $projectQuery->whereIn('projects.id', $permitted);
        }

        $projectColumn = $query->getModel()->qualifyColumn('project_id');

        return $query->where(static function (Builder $scope) use ($projectColumn, $projectQuery, $organizationPermission): void {
            $scope->whereIn($projectColumn, $projectQuery);
            if ($organizationPermission) {
                $scope->orWhereNull($projectColumn);
            }
        });
    }

    public function find(int $organizationId, User $actor, int|string $id, string $permission, bool $lock = false): PaymentDocument
    {
        $query = $this->query($organizationId, $actor, $permission);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    public function bulk(int $organizationId, User $actor, array $ids, string $permission, bool $lock = false): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $query = $this->scope($this->query($organizationId, $actor, $permission), $organizationId, $actor, 'payments.invoice.edit')
            ->whereIn('payment_documents.id', $ids)->orderBy('payment_documents.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $documents = $query->get();
        if ($documents->count() !== count($ids)) {
            $this->notFound();
        }
        if ($lock) {
            $checked = [];
            foreach ($documents as $document) {
                $key = $document->project_id === null ? 'projectless' : (string) $document->project_id;
                if (! isset($checked[$key])) {
                    $this->assertDocument($document, $actor, $permission);
                    $this->assertDocument($document, $actor, 'payments.invoice.edit');
                    $checked[$key] = true;
                }
            }
        }

        return $documents;
    }

    public function assertDocument(PaymentDocument $document, User $actor, string $permission): void
    {
        $this->assertProject((int) $document->organization_id, $actor,
            $document->project_id === null ? null : (int) $document->project_id, $permission);
    }

    public function canOrganization(int $organizationId, User $actor, string $permission): bool
    {
        return $organizationId > 0 && (int) $actor->current_organization_id === $organizationId
            && $this->membership($actor, $organizationId)->exists()
            && $this->hasPermission($actor, $permission, ['organization_id' => $organizationId]);
    }

    public function assertProject(int $organizationId, User $actor, ?int $projectId, string $permission): void
    {
        if ($organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId
            || ! $this->membership($actor, $organizationId)->exists()) {
            $this->notFound();
        }
        if ($projectId !== null && ! $this->projects->queryAccessibleProjects($actor, $organizationId, true)
            ->useWritePdo()->whereKey($projectId)->exists()) {
            $this->notFound();
        }
        if ($this->hasPermission($actor, $permission, ['organization_id' => $organizationId])) {
            return;
        }
        if ($projectId !== null) {
            AuthorizationContext::getProjectContext($projectId, $organizationId);
            if ($this->hasPermission($actor, $permission, [
                'organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true,
            ])) {
                return;
            }
        }

        $this->notFound();
    }

    public function contract(int $organizationId, User $actor, int $contractId, string $permission, ?int $projectId = null): Contract
    {
        $contract = $this->contracts->findAccessibleOrFail($contractId, $organizationId, $projectId);
        if ($projectId !== null) {
            $this->assertProject($organizationId, $actor, $projectId, $permission);
        } elseif ($contract->is_multi_project) {
            $projectIds = $contract->projects()->useWritePdo()->pluck('projects.id');
            if ($projectIds->isEmpty()) {
                $this->assertProject($organizationId, $actor, null, $permission);
            }
            foreach ($projectIds as $relatedProjectId) {
                $this->assertProject($organizationId, $actor, (int) $relatedProjectId, $permission);
            }
        } else {
            $this->assertProject($organizationId, $actor,
                $contract->project_id === null ? null : (int) $contract->project_id, $permission);
        }

        return $contract;
    }

    public function actor(int $userId, ?User $actor = null): User
    {
        if ($actor instanceof User) {
            if ((int) $actor->id !== $userId) {
                $this->notFound();
            }
            return $actor;
        }

        return User::query()->useWritePdo()->findOrFail($userId);
    }

    public static function bulkPermission(string $action): ?string
    {
        return match ($action) {
            'submit' => 'payments.invoice.issue',
            'approve' => 'payments.transaction.approve',
            'pay' => 'payments.transaction.register',
            'cancel' => 'payments.invoice.cancel',
            'schedule' => 'payments.schedule.create',
            default => null,
        };
    }

    private function hasPermission(User $actor, string $permission, array $context): bool
    {
        return $this->authorization->canCurrent($actor, $permission, $context)
            || ($permission === 'payments.invoice.view'
                && $this->authorization->canCurrent($actor, 'payments.invoice.view_all', $context));
    }

    private function membership(User $actor, int $organizationId): QueryBuilder
    {
        return $actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
            ->where('organizations.is_active', true)->wherePivot('is_active', true)->selectRaw('1')->toBase();
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
    }
}
