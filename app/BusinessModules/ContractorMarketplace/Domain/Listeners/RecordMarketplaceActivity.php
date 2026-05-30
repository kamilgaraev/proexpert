<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Listeners;

use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferAccepted;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferCancelled;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferDeclined;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferEvent;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferReviewed;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferSent;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceHiringOfferViewed;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfileEvent;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePaused;
use App\BusinessModules\ContractorMarketplace\Domain\Events\MarketplaceProfilePublished;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Models\User;
use App\Services\Activity\ActivityEventRecorder;
use Illuminate\Support\Facades\Auth;

use function trans_message;

final class RecordMarketplaceActivity
{
    public function __construct(
        private readonly ActivityEventRecorder $recorder,
    ) {
    }

    public function handleProfilePublished(MarketplaceProfilePublished $event): void
    {
        $this->recordProfileEvent(
            event: $event,
            eventType: 'contractor_marketplace.profile.published',
            action: ActivityActionEnum::Approved,
            severity: ActivitySeverityEnum::Notice,
        );
    }

    public function handleProfilePaused(MarketplaceProfilePaused $event): void
    {
        $this->recordProfileEvent(
            event: $event,
            eventType: 'contractor_marketplace.profile.paused',
            action: ActivityActionEnum::Updated,
            severity: ActivitySeverityEnum::Info,
        );
    }

    public function handleOfferSent(MarketplaceHiringOfferSent $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.sent',
            action: ActivityActionEnum::Created,
            organizationId: (int) $event->offer->hiring_organization_id,
            interface: 'admin',
        );
    }

    public function handleOfferViewed(MarketplaceHiringOfferViewed $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.viewed',
            action: ActivityActionEnum::Viewed,
            organizationId: (int) $event->offer->contractor_organization_id,
            interface: 'lk',
        );
    }

    public function handleOfferAccepted(MarketplaceHiringOfferAccepted $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.accepted',
            action: ActivityActionEnum::Approved,
            organizationId: (int) $event->offer->contractor_organization_id,
            interface: 'lk',
            severity: ActivitySeverityEnum::Notice,
        );
    }

    public function handleOfferDeclined(MarketplaceHiringOfferDeclined $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.declined',
            action: ActivityActionEnum::Rejected,
            organizationId: (int) $event->offer->contractor_organization_id,
            interface: 'lk',
            severity: ActivitySeverityEnum::Warning,
        );
    }

    public function handleOfferCancelled(MarketplaceHiringOfferCancelled $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.cancelled',
            action: ActivityActionEnum::Cancelled,
            organizationId: (int) $event->offer->hiring_organization_id,
            interface: 'admin',
            severity: ActivitySeverityEnum::Warning,
        );
    }

    public function handleOfferReviewed(MarketplaceHiringOfferReviewed $event): void
    {
        $this->recordOfferEvent(
            event: $event,
            eventType: 'contractor_marketplace.offer.reviewed',
            action: ActivityActionEnum::Updated,
            organizationId: (int) $event->offer->hiring_organization_id,
            interface: 'admin',
            severity: ActivitySeverityEnum::Notice,
        );
    }

    private function recordProfileEvent(
        MarketplaceProfileEvent $event,
        string $eventType,
        ActivityActionEnum $action,
        ActivitySeverityEnum $severity,
    ): void {
        $profile = $event->profile->loadMissing('organization');
        $actor = $this->resolveActor($event->actor);

        $this->recorder->record(ActivityEventData::make(
            organizationId: (int) $profile->organization_id,
            module: 'contractor-marketplace',
            eventType: $eventType,
            action: $action,
            actorUserId: $actor?->id,
            actorName: $actor?->name,
            actorEmail: $actor?->email,
            interface: 'lk',
            result: ActivityResultEnum::Success,
            severity: $severity,
            subjectType: 'marketplace_contractor_profile',
            subjectId: $profile->id,
            subjectLabel: $this->profileLabel($profile),
            changes: [
                'fields' => [
                    [
                        'field' => 'status',
                        'label' => trans_message('activity.context_labels.status'),
                        'after' => $profile->status?->value,
                    ],
                    [
                        'field' => 'is_visible_in_marketplace',
                        'label' => trans_message('activity.context_labels.marketplace_visibility'),
                        'after' => $profile->is_visible_in_marketplace,
                    ],
                ],
            ],
            context: [
                'profile_id' => $profile->id,
                'organization_id' => $profile->organization_id,
                'organization_name' => $profile->organization?->name,
                'target_name' => $this->profileLabel($profile),
                'status' => $profile->status?->value,
                'availability_status' => $profile->availability_status,
            ],
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
            correlationId: $this->correlationId(),
        ));
    }

    private function recordOfferEvent(
        MarketplaceHiringOfferEvent $event,
        string $eventType,
        ActivityActionEnum $action,
        int $organizationId,
        string $interface,
        ActivitySeverityEnum $severity = ActivitySeverityEnum::Info,
    ): void {
        $offer = $event->offer->loadMissing([
            'project',
            'hiringOrganization',
            'contractorOrganization',
            'contractorProfile',
        ]);
        $actor = $this->resolveActor($event->actor);

        $this->recorder->record(ActivityEventData::make(
            organizationId: $organizationId,
            module: 'contractor-marketplace',
            eventType: $eventType,
            action: $action,
            actorUserId: $actor?->id,
            actorName: $actor?->name,
            actorEmail: $actor?->email,
            interface: $interface,
            result: ActivityResultEnum::Success,
            severity: $severity,
            subjectType: 'marketplace_hiring_offer',
            subjectId: $offer->id,
            subjectLabel: $offer->title,
            projectId: $offer->project_id,
            changes: [
                'fields' => [
                    [
                        'field' => 'status',
                        'label' => trans_message('activity.context_labels.status'),
                        'after' => $offer->status?->value,
                    ],
                ],
            ],
            context: $this->offerContext($offer),
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
            correlationId: $this->correlationId(),
        ));
    }

    private function offerContext(MarketplaceHiringOffer $offer): array
    {
        return [
            'offer_id' => $offer->id,
            'project_id' => $offer->project_id,
            'project_name' => $offer->project?->name,
            'hiring_organization_id' => $offer->hiring_organization_id,
            'hiring_organization_name' => $offer->hiringOrganization?->name,
            'contractor_organization_id' => $offer->contractor_organization_id,
            'contractor_organization_name' => $offer->contractorOrganization?->name,
            'contractor_profile_id' => $offer->contractor_profile_id,
            'target_name' => $offer->contractorOrganization?->name ?? $offer->contractorProfile?->display_name,
            'role' => $offer->role,
            'status' => $offer->status?->value,
            'decline_reason' => $offer->decline_reason,
            'reviews_count' => $offer->reviews->count(),
        ];
    }

    private function profileLabel(MarketplaceContractorProfile $profile): string
    {
        return $profile->display_name
            ?: $profile->organization?->name
            ?: (string) $profile->id;
    }

    private function resolveActor(?User $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        $authUser = Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function correlationId(): ?string
    {
        $header = request()?->headers->get('X-Request-Id');

        return is_string($header) && $header !== '' ? $header : null;
    }
}
