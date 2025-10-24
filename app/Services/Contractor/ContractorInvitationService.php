<?php

namespace App\Services\Contractor;

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
use Illuminate\Support\Facades\Cache;
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

    public function __construct(
        SubscriptionLimitsService $subscriptionLimitsService,
        ContractorRepositoryInterface $contractorRepository,
        LoggingService $logging
    ) {
        $this->subscriptionLimitsService = $subscriptionLimitsService;
        $this->contractorRepository = $contractorRepository;
        $this->logging = $logging;
    }

    public function createInvitation(
        int $organizationId,
        int $invitedOrganizationId,
        User $invitedBy,
        ?string $message = null,
        array $metadata = []
    ): ContractorInvitation {
        $this->validateInvitationRequest($organizationId, $invitedOrganizationId, $invitedBy);

        if (!$this->subscriptionLimitsService->canCreateContractorInvitation($invitedBy)) {
            throw new BusinessLogicException('Достигнут лимит приглашений подрядчиков по вашему тарифному плану');
        }

        $existingInvitation = $this->getExistingActiveInvitation($organizationId, $invitedOrganizationId);
        if ($existingInvitation) {
            throw new BusinessLogicException('Активное приглашение для данной организации уже существует');
        }

        DB::beginTransaction();
        try {
            $invitation = ContractorInvitation::create([
                'organization_id' => $organizationId,
                'invited_organization_id' => $invitedOrganizationId,
                'invited_by_user_id' => $invitedBy->id,
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
            throw new BusinessLogicException('Ошибка при создании приглашения: ' . $e->getMessage());
        }
    }

    public function acceptInvitation(string $token, User $acceptedBy): Contractor
    {
        $invitation = ContractorInvitation::where('token', $token)->first();

        if (!$invitation) {
            throw new BusinessLogicException('Приглашение не найдено');
        }

        if (!$invitation->canBeAccepted()) {
            throw new BusinessLogicException('Приглашение недействительно или истекло');
        }

        if (!$acceptedBy->belongsToOrganization($invitation->invited_organization_id)) {
            throw new BusinessLogicException('У вас нет прав принять это приглашение');
        }

        $existingContractor = $this->findExistingContractor(
            $invitation->organization_id,
            $invitation->invited_organization_id
        );

        if ($existingContractor) {
            throw new BusinessLogicException('Подрядчик уже существует в данной организации');
        }

        DB::beginTransaction();
        try {
            $invitation->accept($acceptedBy);

            $contractor = $this->createContractorFromInvitation($invitation);

            $this->createReverseContractorConnection($invitation, $contractor);

            Log::info('Contractor invitation accepted', [
                'invitation_id' => $invitation->id,
                'contractor_id' => $contractor->id,
                'accepted_by' => $acceptedBy->id,
            ]);

            DB::commit();
            return $contractor;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept contractor invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $invitation->id,
            ]);
            throw new BusinessLogicException('Ошибка при принятии приглашения: ' . $e->getMessage());
        }
    }

    public function declineInvitation(string $token, User $declinedBy): bool
    {
        $invitation = ContractorInvitation::where('token', $token)->first();

        if (!$invitation) {
            throw new BusinessLogicException('Приглашение не найдено');
        }

        if (!$invitation->canBeAccepted()) {
            throw new BusinessLogicException('Приглашение уже обработано или истекло');
        }

        if (!$declinedBy->belongsToOrganization($invitation->invited_organization_id)) {
            throw new BusinessLogicException('У вас нет прав отклонить это приглашение');
        }

        $result = $invitation->decline();

        Log::info('Contractor invitation declined', [
            'invitation_id' => $invitation->id,
            'declined_by' => $declinedBy->id,
        ]);

        return $result;
    }

    public function getInvitationsForOrganization(
        int $organizationId,
        string $type = 'sent',
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $cacheKey = $this->getInvitationsCacheKey($organizationId, $type, $perPage, $filters);
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $type, $perPage, $filters) {
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
                ? ['invitedOrganization', 'invitedBy']
                : ['organization', 'invitedBy'];

            return $query->with($relations)
                        ->orderBy('created_at', 'desc')
                        ->paginate($perPage);
        });
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
        $cacheKey = "contractor_invitations:stats:{$organizationId}";
        
        return Cache::remember($cacheKey, 900, function () use ($organizationId) {
            return [
                'sent' => [
                    'total' => ContractorInvitation::forOrganization($organizationId)->count(),
                    'pending' => ContractorInvitation::forOrganization($organizationId)->pending()->count(),
                    'accepted' => ContractorInvitation::forOrganization($organizationId)->byStatus('accepted')->count(),
                    'declined' => ContractorInvitation::forOrganization($organizationId)->byStatus('declined')->count(),
                ],
                'received' => [
                    'total' => ContractorInvitation::toOrganization($organizationId)->count(),
                    'pending' => ContractorInvitation::toOrganization($organizationId)->pending()->count(),
                    'accepted' => ContractorInvitation::toOrganization($organizationId)->byStatus('accepted')->count(),
                    'declined' => ContractorInvitation::toOrganization($organizationId)->byStatus('declined')->count(),
                ],
            ];
        });
    }

    protected function validateInvitationRequest(int $organizationId, int $invitedOrganizationId, User $invitedBy): void
    {
        if ($organizationId === $invitedOrganizationId) {
            throw new BusinessLogicException('Нельзя пригласить собственную организацию');
        }

        if (!$invitedBy->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException('У вас нет прав создавать приглашения от имени данной организации');
        }

        $invitedOrg = Organization::find($invitedOrganizationId);
        if (!$invitedOrg || !$invitedOrg->is_active) {
            throw new BusinessLogicException('Приглашаемая организация не найдена или неактивна');
        }
    }

    protected function getExistingActiveInvitation(int $organizationId, int $invitedOrganizationId): ?ContractorInvitation
    {
        return ContractorInvitation::where('organization_id', $organizationId)
            ->where('invited_organization_id', $invitedOrganizationId)
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

    protected function getInvitationsCacheKey(int $organizationId, string $type, int $perPage, array $filters): string
    {
        $filterHash = md5(serialize($filters));
        return "contractor_invitations:{$organizationId}:{$type}:{$perPage}:{$filterHash}";
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