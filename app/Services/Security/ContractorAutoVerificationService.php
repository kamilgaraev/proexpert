<?php

namespace App\Services\Security;

use App\Models\Organization;
use App\Models\Contractor;
use App\Models\OrganizationAccessRestriction;
use App\Services\OrganizationVerificationService;
use Illuminate\Support\Facades\Log;

class ContractorAutoVerificationService
{
    public function __construct(
        private OrganizationVerificationService $verificationService
    ) {}

    public function verifyAndSetAccess(Organization $organization): array
    {
        Log::info('[ContractorAutoVerification] Starting verification', [
            'organization_id' => $organization->id,
            'tax_number' => $organization->tax_number
        ]);

        $verificationResult = $this->verificationService->verifyOrganization($organization);
        $score = $verificationResult['verification_score'] ?? 0;
        
        $accessLevel = $this->determineAccessLevel($score);
        
        if ($accessLevel['needs_restriction']) {
            $this->applyRestrictions($organization, $accessLevel, $score);
        }

        Log::info('[ContractorAutoVerification] Verification completed', [
            'organization_id' => $organization->id,
            'score' => $score,
            'access_level' => $accessLevel['level']
        ]);

        return [
            'verification_score' => $score,
            'access_level' => $accessLevel['level'],
            'restrictions_applied' => $accessLevel['needs_restriction'],
            'verification_data' => $verificationResult
        ];
    }

    private function determineAccessLevel(int $score): array
    {
        // 🔐 КОНТЕКСТНАЯ БЛОКИРОВКА: 
        // Блокируем доступ только к данным ЧУЖИХ проектов (где организация - подрядчик)
        // Доступ к СВОИМ данным (свои проекты, свой дашборд, свои пользователи) НЕ блокируется
        // Ограничения применяются только при попытке доступа к контрактам/проектам владельца
        
        return [
            'level' => 'contractor_pending_verification', // Подрядчик ожидает подтверждения
            'needs_restriction' => true,
            'allowed_actions' => [], // Список пуст - проверка будет контекстной
            'blocked_actions' => [
                // ❌ Блокируем только действия в ЧУЖИХ проектах (где эта организация - подрядчик)
                'view_contract_in_customer_project',      // Просмотр контракта у заказчика
                'view_project_as_contractor',             // Просмотр проекта заказчика
                'create_acts_in_customer_project',        // Создание актов в проекте заказчика
                'upload_documents_to_customer_project',   // Загрузка документов заказчику
                'request_payments_from_customer',         // Запрос оплаты от заказчика
                'edit_works_in_customer_project',         // Редактирование работ в проекте заказчика
                'view_reports_in_customer_project',       // Просмотр отчетов проекта заказчика
            ],
            'expires_in_hours' => null, // НЕ снимается автоматически
            'reason' => '🔐 Доступ к данным проектов заказчика заблокирован до подтверждения владельцем проекта. Доступ к своим проектам и данным работает нормально. Рейтинг верификации: ' . $score . '/100.',
        ];
    }

    private function applyRestrictions(Organization $organization, array $accessLevel, int $score): void
    {
        $expiresAt = $accessLevel['expires_in_hours'] 
            ? now()->addHours($accessLevel['expires_in_hours'])
            : null;

        OrganizationAccessRestriction::create([
            'organization_id' => $organization->id,
            'restriction_type' => 'new_contractor_verification',
            'access_level' => $accessLevel['level'],
            'allowed_actions' => $accessLevel['allowed_actions'],
            'blocked_actions' => $accessLevel['blocked_actions'],
            'reason' => $accessLevel['reason'],
            'expires_at' => $expiresAt,
            'can_be_lifted_early' => true,
            'lift_conditions' => [
                'customer_confirmation_required' => true, // 🔒 ВСЕГДА требуется подтверждение
                'time_based' => false, // НЕ снимается автоматически по времени
                'reputation_threshold' => null, // Игнорируем порог репутации
            ],
            'metadata' => [
                'verification_score' => $score,
                'applied_at' => now()->toDateTimeString(),
                'strict_mode' => true, // Флаг строгого режима
            ],
        ]);

        Log::info('[ContractorAutoVerification] Restrictions applied', [
            'organization_id' => $organization->id,
            'access_level' => $accessLevel['level'],
            'expires_at' => $expiresAt?->toDateTimeString()
        ]);
    }

    public function canPerformAction(Organization $organization, string $action): bool
    {
        $activeRestriction = OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->first();

        if (!$activeRestriction) {
            return true;
        }

        return $activeRestriction->canPerformAction($action);
    }

    public function getActiveRestrictions(Organization $organization): ?OrganizationAccessRestriction
    {
        return OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->first();
    }
}

