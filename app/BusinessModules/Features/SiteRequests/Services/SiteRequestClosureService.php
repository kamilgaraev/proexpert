<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

use function trans_message;

final class SiteRequestClosureService
{
    public function __construct(private readonly AuthorizationService $authorizationService) {}

    public function isAvailable(SiteRequest $request): bool
    {
        if ($request->request_type !== SiteRequestTypeEnum::MATERIAL_REQUEST
            || ! in_array($request->status, [
                SiteRequestStatusEnum::APPROVED,
                SiteRequestStatusEnum::IN_PROGRESS,
                SiteRequestStatusEnum::ON_HOLD,
                SiteRequestStatusEnum::FULFILLED,
            ], true)
            || $request->payment_document_id !== null
            || ($request->metadata['material_reserved'] ?? false)
            || ($request->metadata['fulfillment_decision'] ?? null) !== null) {
            return false;
        }

        $request->loadMissing(['purchaseRequests', 'materialDeliveries']);

        return $request->purchaseRequests->isEmpty()
            && ! $request->hasPaymentDocument()
            && $request->materialDeliveries->isEmpty();
    }

    public function assertAllowed(SiteRequest $request, User $actor, string $newStatus, ?string $notes): void
    {
        if ((int) $actor->current_organization_id !== (int) $request->organization_id
            || ! $this->authorizationService->can($actor, 'site_requests.change_status', [
                'organization_id' => (int) $request->organization_id,
                'project_id' => (int) $request->project_id,
            ])) {
            throw new AuthorizationException(trans_message('errors.unauthorized'));
        }

        if ($newStatus !== SiteRequestStatusEnum::COMPLETED->value || ! $this->isAvailable($request)) {
            throw new DomainException(trans_message('site_requests.errors.closure_without_procurement_unavailable'));
        }

        if (mb_strlen(trim((string) $notes)) < 3 || mb_strlen((string) $notes) > 1000) {
            throw new DomainException(trans_message('site_requests.errors.closure_reason_required'));
        }
    }
}
