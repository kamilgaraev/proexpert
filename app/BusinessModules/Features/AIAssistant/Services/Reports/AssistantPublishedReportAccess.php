<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantPublishedReportAccess
{
    public function __construct(private AuthorizationService $authorization, private OrganizationEntitlementService $entitlements) {}

    public function actor(int $actorId, int $organizationId, bool $interactive = false): User
    {
        $actor = User::query()->where('is_active', true)
            ->whereHas('organizations', static fn ($query) => $query->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->where('organization_user.is_active', true))
            ->find($actorId);
        if (! $actor instanceof User || ($interactive && (int) $actor->current_organization_id !== $organizationId)) {
            throw new AuthorizationException;
        }
        $authorization = $this->authorization->forCurrentChecks(true);
        foreach (['ai_assistant.chat', 'ai_assistant.reports.generate'] as $permission) {
            if (! $authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
                throw new AuthorizationException;
            }
        }
        if (! $this->entitlements->hasModuleAccess($organizationId, 'ai-assistant')) {
            throw new AuthorizationException;
        }

        return $actor;
    }
}
