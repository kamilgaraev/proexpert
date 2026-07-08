<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainAction;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainSummary;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainActionResolver;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class SiteRequestActionSummaryService
{
    public function __construct(
        private readonly ProcurementChainService $chainService,
        private readonly ProcurementChainActionResolver $actionResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(SiteRequest $siteRequest, ?User $actor): array
    {
        $organizationId = (int) $siteRequest->organization_id;
        $chain = $this->chainService->forSiteRequest($siteRequest, $actor);

        return [
            'primary_action' => $this->primaryAction($siteRequest, $chain, $actor, $organizationId)?->toArray(),
            'secondary_actions' => $this->secondaryActions($siteRequest, $actor, $organizationId)
                ->map->toArray()
                ->values()
                ->all(),
            'menu_actions' => $this->menuActions($siteRequest, $actor, $organizationId)
                ->map->toArray()
                ->values()
                ->all(),
            'blockers' => $chain->blockers->map->toArray()->values()->all(),
        ];
    }

    private function primaryAction(
        SiteRequest $siteRequest,
        ProcurementChainSummary $chain,
        ?User $actor,
        int $organizationId
    ): ?ProcurementChainAction {
        if (
            $siteRequest->request_type === SiteRequestTypeEnum::MATERIAL_REQUEST
            &&
            $chain->nextAction instanceof ProcurementChainAction
            && $chain->nextAction->key !== 'approve_site_request'
        ) {
            return $chain->nextAction;
        }

        return match ($siteRequest->status) {
            SiteRequestStatusEnum::DRAFT => $this->action(
                'submit_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/submit",
                $actor,
                $organizationId,
                'POST',
                10
            ),
            SiteRequestStatusEnum::PENDING,
            SiteRequestStatusEnum::IN_REVIEW => $this->action(
                'approve_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/status",
                $actor,
                $organizationId,
                'POST',
                10
            ),
            SiteRequestStatusEnum::APPROVED => $this->action(
                'start_work',
                "/api/v1/admin/site-requests/{$siteRequest->id}/status",
                $actor,
                $organizationId,
                'POST',
                10
            ),
            SiteRequestStatusEnum::IN_PROGRESS => $this->action(
                'fulfill_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/status",
                $actor,
                $organizationId,
                'POST',
                10
            ),
            SiteRequestStatusEnum::FULFILLED => $this->action(
                'complete_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/status",
                $actor,
                $organizationId,
                'POST',
                10
            ),
            default => null,
        };
    }

    /**
     * @return Collection<int, ProcurementChainAction>
     */
    private function secondaryActions(SiteRequest $siteRequest, ?User $actor, int $organizationId): Collection
    {
        if (!in_array($siteRequest->status, [SiteRequestStatusEnum::PENDING, SiteRequestStatusEnum::IN_REVIEW], true)) {
            return collect();
        }

        return collect([
            $this->action(
                'reject_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/status",
                $actor,
                $organizationId,
                'POST',
                20
            ),
        ]);
    }

    /**
     * @return Collection<int, ProcurementChainAction>
     */
    private function menuActions(SiteRequest $siteRequest, ?User $actor, int $organizationId): Collection
    {
        $actions = collect();

        if (!$siteRequest->status->isFinal()) {
            $actions->push($this->action(
                'assign_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}/assign",
                $actor,
                $organizationId,
                'POST',
                30
            ));
        }

        if ($siteRequest->canBeEdited()) {
            $actions->push($this->action(
                'edit_site_request',
                "/site-requests/{$siteRequest->id}/edit",
                $actor,
                $organizationId,
                'GET',
                40
            ));
        }

        if ($siteRequest->status === SiteRequestStatusEnum::DRAFT) {
            $actions->push($this->action(
                'delete_site_request',
                "/api/v1/admin/site-requests/{$siteRequest->id}",
                $actor,
                $organizationId,
                'DELETE',
                50
            ));
        }

        return $actions;
    }

    private function action(
        string $key,
        string $href,
        ?User $actor,
        int $organizationId,
        string $method,
        int $priority
    ): ProcurementChainAction {
        return $this->actionResolver->action(
            key: $key,
            href: $href,
            actor: $actor,
            organizationId: $organizationId,
            method: $method,
            scope: 'site_request',
            priority: $priority
        );
    }
}
