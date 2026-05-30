<?php

namespace App\Services\Contractor;

use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceNetworkService;
use App\Models\ContractorInvitation;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\SubscriptionLimitsService;
use App\Services\Logging\LoggingService;
use App\Exceptions\BusinessLogicException;
use App\Repositories\Interfaces\ContractorRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Notifications\ContractorInvitationNotification;
use Carbon\Carbon;

class ContractorInvitationService
{
    protected SubscriptionLimitsService $subscriptionLimitsService;
    protected ContractorRepositoryInterface $contractorRepository;
    protected LoggingService $logging;
    protected MarketplaceNetworkService $marketplaceNetworkService;

    public function __construct(
        SubscriptionLimitsService $subscriptionLimitsService,
        ContractorRepositoryInterface $contractorRepository,
        LoggingService $logging,
        MarketplaceNetworkService $marketplaceNetworkService
    ) {
        $this->subscriptionLimitsService = $subscriptionLimitsService;
        $this->contractorRepository = $contractorRepository;
        $this->logging = $logging;
        $this->marketplaceNetworkService = $marketplaceNetworkService;
    }

    public function createInvitation(
        int $organizationId,
        int $invitedOrganizationId,
        User $invitedBy,
        ?string $message = null,
        array $metadata = []
    ): ContractorInvitation {
        $this->validateInvitationRequest($organizationId, $invitedOrganizationId, $invitedBy);

        if (!$this->subscriptionLimitsService->canCreateContractorInvitationForOrganization($invitedBy, $organizationId)) {
            throw new BusinessLogicException(trans_message('contract.invitation_limit_reached'));
        }

        $existingInvitation = $this->getExistingActiveInvitation($organizationId, $invitedOrganizationId);
        if ($existingInvitation) {
            throw new BusinessLogicException(trans_message('contract.invitation_active_exists'));
        }

        DB::beginTransaction();
        try {
            $invitation = ContractorInvitation::create([
                'organization_id' => $organizationId,
                'invited_organization_id' => $invitedOrganizationId,
                'invited_by_user_id' => $invitedBy->id,
                'status' => ContractorInvitation::STATUS_PENDING,
                'invitation_message' => $message,
                'metadata' => $metadata,
            ]);

            $invitation->load(['organization', 'invitedOrganization', 'invitedBy']);

            $this->sendInvitationNotifications($invitation);

            Log::info('Contractor invitation created', [
                'invitation_id' => $invitation->id,
                'from_org' => $organizationId,
                'to_org' => $invitedOrganizationId,
                'invited_by' => $invitedBy->id,
            ]);

            DB::commit();
            return $invitation;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create contractor invitation', [
                'error' => $e->getMessage(),
                'from_org' => $organizationId,
                'to_org' => $invitedOrganizationId,
            ]);
            throw new BusinessLogicException(trans_message('contract.invitation_create_error'));
        }
    }

    public function acceptInvitation(string $token, User $acceptedBy): Contractor
    {
        try {
            return DB::transaction(function () use ($token, $acceptedBy): Contractor {
                $invitation = ContractorInvitation::query()
                    ->where('token', $token)
                    ->lockForUpdate()
                    ->first();

                if (!$invitation) {
                    throw new BusinessLogicException(trans_message('contract.invitation_not_found'));
                }

                if (
                    (int) $acceptedBy->current_organization_id !== (int) $invitation->invited_organization_id
                    || !$acceptedBy->belongsToOrganization($invitation->invited_organization_id)
                ) {
                    throw new BusinessLogicException(trans_message('contract.invitation_accept_forbidden'));
                }

                $existingContractor = $this->findExistingContractor(
                    $invitation->organization_id,
                    $invitation->invited_organization_id
                );

                if ($invitation->status === ContractorInvitation::STATUS_ACCEPTED) {
                    if ($existingContractor) {
                        return $existingContractor;
                    }

                    throw new BusinessLogicException(trans_message('contract.invitation_already_processed'));
                }

                if (!$invitation->canBeAccepted()) {
                    throw new BusinessLogicException(trans_message('contract.invitation_unavailable'));
                }

                if ($existingContractor) {
                    throw new BusinessLogicException(trans_message('contract.invitation_organization_already_contractor'));
                }

                $invitation->accept($acceptedBy);

                $contractor = $this->createContractorFromInvitation($invitation);

                $this->createReverseContractorConnection($invitation, $contractor);
                $this->marketplaceNetworkService->bootstrapDraftProfileFromInvitation($invitation);

                Log::info('Contractor invitation accepted', [
                    'invitation_id' => $invitation->id,
                    'contractor_id' => $contractor->id,
                    'accepted_by' => $acceptedBy->id,
                ]);

                return $contractor;
            });
        } catch (\Exception $e) {
            Log::error('Failed to accept contractor invitation', [
                'error' => $e->getMessage(),
                'token' => $token,
            ]);

            if ($e instanceof BusinessLogicException) {
                throw $e;
            }

            throw new BusinessLogicException(trans_message('contract.invitation_accept_error'));
        }
    }

    public function declineInvitation(string $token, User $declinedBy, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($token, $declinedBy, $reason): bool {
            $invitation = ContractorInvitation::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (!$invitation) {
                throw new BusinessLogicException(trans_message('contract.invitation_not_found'));
            }

            if (!$invitation->canBeAccepted()) {
                throw new BusinessLogicException(trans_message('contract.invitation_already_processed'));
            }

            if (
                (int) $declinedBy->current_organization_id !== (int) $invitation->invited_organization_id
                || !$declinedBy->belongsToOrganization($invitation->invited_organization_id)
            ) {
                throw new BusinessLogicException(trans_message('contract.invitation_decline_forbidden'));
            }

            $result = $invitation->decline($declinedBy, $reason);

            Log::info('Contractor invitation declined', [
                'invitation_id' => $invitation->id,
                'declined_by' => $declinedBy->id,
            ]);

            return $result;
        });
    }

    public function cancelInvitation(int $invitationId, int $organizationId, User $cancelledBy, ?string $reason = null): ContractorInvitation
    {
        try {
            return DB::transaction(function () use ($invitationId, $organizationId, $cancelledBy, $reason): ContractorInvitation {
                $invitation = ContractorInvitation::query()
                    ->where('id', $invitationId)
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->first();

                if (!$invitation || !$invitation->isPending()) {
                    throw new BusinessLogicException(trans_message('contract.invitation_not_found'));
                }

                if (!$cancelledBy->belongsToOrganization($organizationId)) {
                    throw new BusinessLogicException(trans_message('contract.invitation_cancel_forbidden'));
                }

                $invitation->cancel($cancelledBy, $reason);

                Log::info('Contractor invitation cancelled', [
                    'invitation_id' => $invitationId,
                    'cancelled_by' => $cancelledBy->id,
                    'organization_id' => $organizationId,
                ]);

                return $invitation;
            });
        } catch (\Exception $e) {
            if ($e instanceof BusinessLogicException) {
                throw $e;
            }

            Log::error('Failed to cancel contractor invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $invitationId,
                'organization_id' => $organizationId,
            ]);

            throw new BusinessLogicException(trans_message('contract.invitation_cancel_error'));
        }
    }

    public function getInvitationsForOrganization(
        int $organizationId,
        string $type = 'sent',
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = ContractorInvitation::query();

        if ($type === 'sent') {
            $query->forOrganization($organizationId);
        } else {
            $query->toOrganization($organizationId);
        }

        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->toDateTimeString());
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->toDateTimeString());
        }

        $relations = $type === 'sent'
            ? ['invitedOrganization', 'invitedBy', 'acceptedBy', 'declinedBy', 'cancelledBy']
            : ['organization', 'invitedBy', 'acceptedBy', 'declinedBy', 'cancelledBy'];

        return $query->with($relations)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function expireOldInvitations(): int
    {
        $expiredCount = ContractorInvitation::where('expires_at', '<=', now())
            ->where('status', ContractorInvitation::STATUS_PENDING)
            ->update(['status' => ContractorInvitation::STATUS_EXPIRED]);

        Log::info('Expired contractor invitations', ['count' => $expiredCount]);

        return $expiredCount;
    }

    public function getInvitationStats(int $organizationId): array
    {
        return [
            'sent' => [
                'total' => ContractorInvitation::forOrganization($organizationId)->count(),
                'pending' => ContractorInvitation::forOrganization($organizationId)->pending()->count(),
                'accepted' => ContractorInvitation::forOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_ACCEPTED)->count(),
                'declined' => ContractorInvitation::forOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_DECLINED)->count(),
                'expired' => ContractorInvitation::forOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_EXPIRED)->count(),
                'cancelled' => ContractorInvitation::forOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_CANCELLED)->count(),
            ],
            'received' => [
                'total' => ContractorInvitation::toOrganization($organizationId)->count(),
                'pending' => ContractorInvitation::toOrganization($organizationId)->pending()->count(),
                'accepted' => ContractorInvitation::toOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_ACCEPTED)->count(),
                'declined' => ContractorInvitation::toOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_DECLINED)->count(),
                'expired' => ContractorInvitation::toOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_EXPIRED)->count(),
                'cancelled' => ContractorInvitation::toOrganization($organizationId)->byStatus(ContractorInvitation::STATUS_CANCELLED)->count(),
            ],
        ];
    }

    protected function validateInvitationRequest(int $organizationId, int $invitedOrganizationId, User $invitedBy): void
    {
        if ($organizationId === $invitedOrganizationId) {
            throw new BusinessLogicException(trans_message('contract.invitation_self_not_allowed'));
        }

        if (!$invitedBy->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException(trans_message('contract.invitation_create_forbidden'));
        }

        $invitedOrg = Organization::find($invitedOrganizationId);
        if (!$invitedOrg || !$invitedOrg->is_active) {
            throw new BusinessLogicException(trans_message('contract.invitation_target_unavailable'));
        }

        if ($this->findExistingContractor($organizationId, $invitedOrganizationId)) {
            throw new BusinessLogicException(trans_message('contract.invitation_organization_already_contractor'));
        }

        if ($this->getExistingReverseActiveInvitation($organizationId, $invitedOrganizationId)) {
            throw new BusinessLogicException(trans_message('contract.invitation_reverse_active_exists'));
        }
    }

    protected function getExistingActiveInvitation(int $organizationId, int $invitedOrganizationId): ?ContractorInvitation
    {
        return ContractorInvitation::where('organization_id', $organizationId)
            ->where('invited_organization_id', $invitedOrganizationId)
            ->active()
            ->first();
    }

    protected function getExistingReverseActiveInvitation(int $organizationId, int $invitedOrganizationId): ?ContractorInvitation
    {
        return ContractorInvitation::where('organization_id', $invitedOrganizationId)
            ->where('invited_organization_id', $organizationId)
            ->active()
            ->first();
    }

    protected function findExistingContractor(int $organizationId, int $sourceOrganizationId): ?Contractor
    {
        return Contractor::where('organization_id', $organizationId)
            ->where('source_organization_id', $sourceOrganizationId)
            ->first();
    }

    protected function createContractorFromInvitation(ContractorInvitation $invitation): Contractor
    {
        $sourceOrg = $invitation->invitedOrganization;
        
        $contractorData = [
            'organization_id' => $invitation->organization_id,
            'source_organization_id' => $invitation->invited_organization_id,
            'name' => $sourceOrg->name,
            'contact_person' => $sourceOrg->owners()->first()?->name,
            'phone' => $sourceOrg->phone,
            'email' => $sourceOrg->email,
            'legal_address' => $sourceOrg->address,
            'inn' => $sourceOrg->tax_number,
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
            'contractor_invitation_id' => $invitation->id,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address'],
                'sync_interval_hours' => 24,
                'auto_sync_enabled' => true,
            ],
        ];

        return $this->contractorRepository->create($contractorData);
    }

    protected function createReverseContractorConnection(ContractorInvitation $invitation, Contractor $contractor): void
    {
        if ($this->findExistingContractor($invitation->invited_organization_id, $invitation->organization_id)) {
            return;
        }

        $reverseContractorData = [
            'organization_id' => $invitation->invited_organization_id,
            'source_organization_id' => $invitation->organization_id,
            'name' => $invitation->organization->name,
            'contact_person' => $invitation->invitedBy->name,
            'phone' => $invitation->organization->phone,
            'email' => $invitation->organization->email,
            'legal_address' => $invitation->organization->address,
            'inn' => $invitation->organization->tax_number,
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
            'contractor_invitation_id' => $invitation->id,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address'],
                'sync_interval_hours' => 24,
                'auto_sync_enabled' => true,
            ],
        ];

        $this->contractorRepository->create($reverseContractorData);
    }

    protected function sendInvitationNotifications(ContractorInvitation $invitation): void
    {
        try {
            $invitedOrganization = $invitation->invitedOrganization;
            $organizationOwners = $invitedOrganization->owners()->get();

            if ($organizationOwners->isNotEmpty()) {
                Notification::send($organizationOwners, new ContractorInvitationNotification($invitation));
                
                // BUSINESS: Уведомления о приглашении подрядчика отправлены
                $this->logging->business('contractor.invitation.notifications.sent', [
                    'invitation_id' => $invitation->id,
                    'invited_organization_id' => $invitation->invited_organization_id,
                    'inviting_organization_id' => $invitation->organization_id,
                    'recipients_count' => $organizationOwners->count(),
                    'notification_channels' => ['mail', 'database']
                ]);

                // AUDIT: Отправка приглашения подрядчику
                $this->logging->audit('contractor.invitation.sent', [
                    'invitation_id' => $invitation->id,
                    'invited_organization_id' => $invitation->invited_organization_id,
                    'transaction_type' => 'contractor_invitation_sent',
                    'performed_by' => Auth::id() ?? 'system',
                    'recipients_count' => $organizationOwners->count()
                ]);
                
            } else {
                // TECHNICAL: Нет владельцев организации для отправки приглашения
                $this->logging->technical('contractor.invitation.no_recipients', [
                    'invitation_id' => $invitation->id,
                    'invited_organization_id' => $invitation->invited_organization_id,
                    'organization_owners_count' => 0,
                    'notification_issue' => true
                ], 'warning');

                // BUSINESS: Приглашение не отправлено из-за отсутствия получателей
                $this->logging->business('contractor.invitation.failed.no_recipients', [
                    'invitation_id' => $invitation->id,
                    'invited_organization_id' => $invitation->invited_organization_id,
                    'failure_reason' => 'no_organization_owners'
                ], 'warning');
            }
        } catch (\Exception $e) {
            // TECHNICAL: Ошибка при отправке приглашения подрядчику
            $this->logging->technical('contractor.invitation.notification.exception', [
                'invitation_id' => $invitation->id,
                'invited_organization_id' => $invitation->invited_organization_id,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'notification_failure' => true
            ], 'error');

            // BUSINESS: Неудачная отправка приглашения - влияет на бизнес-процесс
            $this->logging->business('contractor.invitation.failed.exception', [
                'invitation_id' => $invitation->id,
                'invited_organization_id' => $invitation->invited_organization_id,
                'failure_reason' => 'system_exception',
                'error_message' => $e->getMessage()
            ], 'error');
        }
    }
}
