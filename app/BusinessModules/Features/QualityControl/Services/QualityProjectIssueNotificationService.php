<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Database\Eloquent\Builder;

final readonly class QualityProjectIssueNotificationService
{
    public function __construct(
        private NotificationService $notifications,
        private AuthorizationService $authorization,
        private AccessController $access,
    ) {}

    public function statusChanged(QualityDefect $issue, QualityDefectStatusHistory $history): void
    {
        if ($issue->kind !== 'project') return;

        $recipientIds = match ($issue->status) {
            QualityDefectStatusEnum::ASSIGNED, QualityDefectStatusEnum::REJECTED => [$issue->assigned_to],
            QualityDefectStatusEnum::READY_FOR_REVIEW => [$issue->created_by],
            QualityDefectStatusEnum::RESOLVED => [$issue->assigned_to, $issue->created_by],
            default => [],
        };
        $recipientIds = array_values(array_unique(array_filter($recipientIds, static fn ($id): bool => $id !== null && (int) $id !== (int) $history->changed_by)));
        if ($recipientIds === []) return;

        $organizationId = (int) $issue->organization_id;
        $projectId = (int) $issue->project_id;
        $pir = $this->access->hasModuleAccess($organizationId, 'design-management');
        $quality = $this->access->hasModuleAccess($organizationId, 'quality-control');
        if (! $pir && ! $quality) return;

        $recipients = User::query()->whereIn('id', $recipientIds)
            ->whereHas('organizations', static fn (Builder $query) => $query->where('organizations.id', $organizationId)->where('organization_user.is_active', true))
            ->whereExists(static fn ($query) => $query->selectRaw('1')->from('project_user')->whereColumn('project_user.user_id', 'users.id')->where('project_id', $projectId)->where('is_active', true))
            ->get();
        $scope = ['organization_id' => $organizationId, 'project_id' => $projectId];

        foreach ($recipients as $recipient) {
            $permission = $pir && $this->authorization->can($recipient, 'design-management.view', $scope)
                ? 'design-management.view'
                : ($quality && $this->authorization->can($recipient, 'quality-control.view', $scope) ? 'quality-control.view' : null);
            if ($permission === null) continue;

            $packageId = (int) ($issue->metadata['design_issue_context']['package_id'] ?? 0);
            $route = $permission === 'quality-control.view'
                ? '/quality-control/defects/'.$issue->id
                : ($packageId > 0 ? '/pir/packages/'.$packageId : '/pir');
            $this->notifications->send(
                $recipient,
                'design_issue.'.$issue->status->value,
                [
                    'title' => trans_message('project_issue_notifications.'.$issue->status->value),
                    'message' => $issue->defect_number.': '.$issue->title,
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'entity_type' => 'quality_defect',
                    'entity_id' => (int) $issue->id,
                    'transition_id' => (int) $history->id,
                    'target_route' => $route,
                ],
                notificationType: 'system',
                channels: ['in_app', 'websocket'],
                organizationId: $organizationId,
                requiredPermissions: $permission,
                interfaces: ['admin'],
            );
        }
    }
}
