<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Collection;

final class PaymentNotificationRecipients
{
    public function forApproval(PaymentDocument $document, PaymentApproval $approval): Collection
    {
        if ($document->status !== PaymentDocumentStatus::PENDING_APPROVAL
            || (int) $approval->organization_id !== (int) $document->organization_id
            || $approval->status !== 'pending') {
            return collect();
        }
        $document->loadMissing('approvals');
        $currentLevel = $document->approvals->where('organization_id', $document->organization_id)
            ->where('status', 'pending')->min('approval_level');
        if ((int) $approval->approval_level !== (int) $currentLevel) {
            return collect();
        }
        if ($approval->approver_user_id) {
            $user = User::find($approval->approver_user_id);
            return $user && ($this->allows($user, $document, 'payments.transaction.approve')
                || $this->allows($user, $document, 'payments.transaction.reject')) ? collect([$user]) : collect();
        }
        if ($approval->approval_permission) {
            return $this->usersWithAnyPermission((int) $document->organization_id, [$approval->approval_permission], $document);
        }

        return collect();
    }

    public function allows(User $user, PaymentDocument $document, string $permission): bool
    {
        $organizationId = (int) $document->organization_id;
        if (! $user->organizations()->whereKey($organizationId)->wherePivot('is_active', true)->exists()) {
            return false;
        }
        if ($document->project_id !== null && ! app(UserProjectAccessService::class)
            ->queryAccessibleProjects($user, $organizationId, true)->whereKey($document->project_id)->exists()) {
            return false;
        }

        return $user->can($permission, ['organization_id' => $organizationId])
            || ($document->project_id !== null && $user->can($permission, [
                'organization_id' => $organizationId, 'project_id' => (int) $document->project_id, 'strict_project_scope' => true,
            ]));
    }

    public function usersWithAnyPermission(int $organizationId, array $permissions, ?PaymentDocument $document = null): Collection
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        $contextIds = [$context->id];
        if ($document?->project_id !== null) {
            $contextIds[] = AuthorizationContext::getProjectContext((int) $document->project_id, $organizationId)->id;
        }

        return User::query()->whereHas('roleAssignments', static function ($query) use ($contextIds): void {
            $query->whereIn('context_id', $contextIds);
        })->get()->filter(function (User $user) use ($permissions, $organizationId, $document): bool {
            foreach ($permissions as $permission) {
                if ($document !== null ? $this->allows($user, $document, $permission)
                    : $user->can($permission, ['organization_id' => $organizationId])) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
