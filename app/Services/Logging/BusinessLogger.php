<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BusinessLogger
{
    protected RequestContext $requestContext;
    protected UserContext $userContext;
    protected PerformanceContext $performanceContext;

    public function __construct(
        RequestContext $requestContext,
        UserContext $userContext,
        PerformanceContext $performanceContext
    ) {
        $this->requestContext = $requestContext;
        $this->userContext = $userContext;
        $this->performanceContext = $performanceContext;
    }

    /**
     * Логировать бизнес-событие
     */
    public function log(string $event, array $context = []): void
    {
        $businessEntry = $this->createBusinessEntry($event, $context);
        
        Log::info("[BUSINESS] {$event}", $businessEntry);

        // Отправить метрику в Prometheus, если доступно
        $this->sendBusinessMetric($event, $context);
    }

    /**
     * Создать запись бизнес-события
     */
    protected function createBusinessEntry(string $event, array $context): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'level' => 'INFO',
            'category' => 'BUSINESS',
            'event' => $event,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'context' => $this->enrichBusinessContext($context),
            'metadata' => [
                'interface' => $this->requestContext->getClientInterface(),
                'api_version' => $this->requestContext->getMetadata()['api_version'] ?? null,
                'user_type' => $this->userContext->getUserType()
            ],
            'performance' => $this->performanceContext->getBasicMetrics(),
            'environment' => config('app.env'),
            'application' => 'prohelper'
        ];
    }

    /**
     * Обогатить бизнес-контекст дополнительной информацией
     */
    protected function enrichBusinessContext(array $context): array
    {
        // Добавить временные метки
        $context['business_timestamp'] = now()->toISOString();
        $context['business_date'] = now()->toDateString();
        $context['business_hour'] = now()->hour;
        
        // Добавить информацию об организации для бизнес-анализа
        if ($orgId = $this->userContext->getOrganizationId()) {
            $context['organization_context'] = [
                'organization_id' => $orgId,
                'user_count_in_org' => $this->getUserCountInOrganization($orgId)
            ];
        }

        return $context;
    }

    /**
     * Получить количество пользователей в организации (кэшированно)
     */
    protected function getUserCountInOrganization(int $orgId): ?int
    {
        try {
            return Cache::remember("org_user_count_{$orgId}", 300, function () use ($orgId) {
                return \App\Models\User::whereHas('organizations', function ($query) use ($orgId) {
                    $query->where('organizations.id', $orgId);
                })->count();
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Отправить бизнес-метрику в Prometheus
     */
    protected function sendBusinessMetric(string $event, array $context): void
    {
        try {
            if (app()->bound(\App\Services\Monitoring\PrometheusService::class)) {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                // Используем существующий метод incrementExceptions для business событий
                $prometheus->incrementExceptions('BusinessEvent', $event);
            }
        } catch (\Exception $e) {
            // Не прерываем выполнение, если мониторинг недоступен
        }
    }

    /**
     * Специальные методы для ключевых бизнес-событий ProHelper
     */

    public function userRegistration(int $userId, string $registrationType = 'standard'): void
    {
        $this->log('user.registration.completed', [
            'user_id' => $userId,
            'registration_type' => $registrationType,
            'organization_id' => $this->userContext->getOrganizationId(),
            'interface' => $this->requestContext->getClientInterface()
        ]);
    }

    public function organizationCreated(int $orgId, int $ownerId, array $orgData = []): void
    {
        $this->log('organization.created', [
            'organization_id' => $orgId,
            'owner_id' => $ownerId,
            'organization_name' => $orgData['name'] ?? null,
            'organization_type' => $orgData['type'] ?? 'standard',
            'has_parent' => !empty($orgData['parent_organization_id'])
        ]);
    }

    public function projectCreated(int $projectId, array $projectData = []): void
    {
        $this->log('project.created', [
            'project_id' => $projectId,
            'project_name' => $projectData['name'] ?? null,
            'project_status' => $projectData['status'] ?? null,
            'has_contractors' => !empty($projectData['contractors_count']),
            'estimated_duration_days' => $this->calculateProjectDuration($projectData)
        ]);
    }

    public function contractSigned(int $contractId, array $contractData = []): void
    {
        $this->log('contract.signed', [
            'contract_id' => $contractId,
            'contract_number' => $contractData['number'] ?? null,
            'contractor_id' => $contractData['contractor_id'] ?? null,
            'project_id' => $contractData['project_id'] ?? null,
            'has_parent_contract' => !empty($contractData['parent_contract_id']),
            'contract_value' => isset($contractData['value']) ? 'SET' : 'NOT_SET' // Не логируем точную сумму
        ]);
    }

    public function materialImported(int $materialsCount, string $importType = 'file'): void
    {
        $this->log('material.import.completed', [
            'materials_count' => $materialsCount,
            'import_type' => $importType,
            'organization_id' => $this->userContext->getOrganizationId(),
            'user_id' => $this->userContext->getUserId()
        ]);
    }

    public function reportGenerated(string $reportType, array $reportData = []): void
    {
        $this->log('report.generated', [
            'report_type' => $reportType,
            'report_format' => $reportData['format'] ?? 'unknown',
            'records_count' => $reportData['records_count'] ?? 0,
            'date_range_days' => $this->calculateDateRange($reportData),
            'has_filters' => !empty($reportData['filters'])
        ]);
    }

    public function paymentProcessed(string $paymentType, string $status, array $paymentData = []): void
    {
        $this->log('payment.processed', [
            'payment_type' => $paymentType, // subscription, one-time, etc.
            'payment_status' => $status,
            'organization_id' => $this->userContext->getOrganizationId(),
            'payment_method' => $paymentData['method'] ?? 'unknown',
            'subscription_plan' => $paymentData['subscription_plan'] ?? null,
            'has_addons' => !empty($paymentData['addons'])
        ]);
    }

    public function moduleActivated(string $moduleSlug, array $moduleData = []): void
    {
        $this->log('module.activated', [
            'module_slug' => $moduleSlug,
            'module_type' => $moduleData['type'] ?? null,
            'billing_model' => $moduleData['billing_model'] ?? null,
            'organization_id' => $this->userContext->getOrganizationId(),
            'activated_by' => $this->userContext->getUserId()
        ]);
    }

    public function moduleDeactivated(string $moduleSlug, ?string $reason = null): void
    {
        $this->log('module.deactivated', [
            'module_slug' => $moduleSlug,
            'deactivation_reason' => $reason,
            'organization_id' => $this->userContext->getOrganizationId(),
            'deactivated_by' => $this->userContext->getUserId()
        ]);
    }

    public function userInvited(string $invitationType, array $invitationData = []): void
    {
        $this->log('user.invitation.sent', [
            'invitation_type' => $invitationType, // admin, foreman, contractor
            'target_email' => isset($invitationData['email']) ? 'SET' : 'NOT_SET',
            'organization_id' => $this->userContext->getOrganizationId(),
            'invited_by' => $this->userContext->getUserId(),
            'roles_assigned' => $invitationData['roles'] ?? []
        ]);
    }

    /**
     * Вспомогательные методы для расчета метрик
     */

    protected function calculateProjectDuration(array $projectData): ?int
    {
        if (empty($projectData['start_date']) || empty($projectData['end_date'])) {
            return null;
        }

        try {
            $start = \Carbon\Carbon::parse($projectData['start_date']);
            $end = \Carbon\Carbon::parse($projectData['end_date']);
            return $start->diffInDays($end);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function calculateDateRange(array $reportData): ?int
    {
        if (empty($reportData['date_from']) || empty($reportData['date_to'])) {
            return null;
        }

        try {
            $from = \Carbon\Carbon::parse($reportData['date_from']);
            $to = \Carbon\Carbon::parse($reportData['date_to']);
            return $from->diffInDays($to);
        } catch (\Exception $e) {
            return null;
        }
    }
}
