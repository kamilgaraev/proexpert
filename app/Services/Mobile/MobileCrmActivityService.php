<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;

final class MobileCrmActivityService
{
    public function __construct(
        private readonly CrmRegistryService $registry,
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly MobileProjectAccessResolver $projects,
    ) {}

    public function create(User $actor, int $organizationId, array $input): CrmActivity
    {
        if (!$this->access->hasModuleAccess($organizationId, 'crm')) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        $targetType = (string) $input['target_type'];
        $targetId = (string) $input['target_id'];
        $target = match ($targetType) {
            'company' => $this->registry->findCompany($organizationId, $targetId),
            'contact' => $this->registry->findContact($organizationId, $targetId),
            'lead' => $this->registry->findLead($organizationId, $targetId),
            'deal' => $this->registry->findDeal($organizationId, $targetId),
        };

        $context = ['organization_id' => $organizationId];
        if ($targetType === 'deal' && $target->project_id !== null) {
            $projectId = (int) $target->project_id;
            $this->projects->assert($actor, $organizationId, $projectId, trans_message('mobile_companions.errors.item_not_found'));
            $context = [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ];
        }
        if (!$this->authorization->can($actor, 'crm.activities.create', $context)) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        $note = $input['kind'] === 'note';
        return $this->registry->createActivity($organizationId, [
            $targetType.'_id' => $targetId,
            'owner_user_id' => (int) $actor->id,
            'type' => $note ? 'note' : 'call',
            'status' => $note ? 'done' : 'planned',
            'subject' => $input['subject'],
            'body' => $input['body'] ?? null,
            'due_at' => $note ? null : $input['due_at'],
            'completed_at' => $note ? now() : null,
        ], (int) $actor->id);
    }
}
