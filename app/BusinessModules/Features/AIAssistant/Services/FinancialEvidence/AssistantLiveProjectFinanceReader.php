<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\Budgeting\Contracts\ExactProjectFinanceSourceRead;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\CurrencyCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class AssistantLiveProjectFinanceReader
{
    private const TYPES = ['budget_amount' => 'budget_amount', 'contract_performance_act' => 'performance_act', 'completed_work' => 'completed_work',
        'payment_document' => 'payment_document', 'warehouse_movement' => 'warehouse_movement', 'time_entry' => 'time_entry',
        'machinery_shift' => 'machinery_shift_report', 'machinery_maintenance' => 'machinery_maintenance_order'];

    public function __construct(private ReportHttpAuthorizationOrchestrator $nativeAuthorization, private ExactProjectFinanceSourceRead $reports,
        private AssistantLiveProjectFinanceProjection $projection, private AssistantDataAccessPolicy $access, private AuthorizationService $authorization) {}

    public function read(User $actor, Organization $organization, array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['project_id', 'period_start', 'period_end', 'currency']) !== [] || ! is_int($arguments['project_id'] ?? null) || $arguments['project_id'] < 1
            || ! $this->validDate($arguments['period_start'] ?? null) || ! $this->validDate($arguments['period_end'] ?? null)
            || $arguments['period_end'] < $arguments['period_start']
            || (new DateTimeImmutable($arguments['period_start']))->diff(new DateTimeImmutable($arguments['period_end']))->days > 366
            || (! is_string($arguments['currency'] ?? null) && ($arguments['currency'] ?? null) !== null)
            || (is_string($arguments['currency'] ?? null) && CurrencyCode::tryFrom($arguments['currency']) === null)) {
            throw new DomainException('live_project_finance_arguments_invalid');
        }
        $organizationId = (int) $organization->id;
        $projectId = $arguments['project_id'];
        $this->assertCurrentAccess($actor, $organizationId, $projectId);
        $request = Request::create('/api/v1/admin/reports/project-financial-evidence', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', $organizationId);
        $request->attributes->set('report_project_scope_id', $projectId);
        $current = $this->nativeAuthorization->createRun($request, 'project_margin');
        $context = $current['context'];
        if ($context->actor->id !== (int) $actor->id || $context->scope->organizationId !== $organizationId || ! in_array($projectId, $context->scope->projectIds, true)) { throw new AccessDeniedHttpException; }
        $native = $this->reports->exactFinancialSourceRows([...$arguments, 'organization_id' => $organizationId], $context->scope->projectIds, $actor);
        if (($native['status'] ?? null) !== 'success') { return $this->projection->insufficient((string) ($native['reason'] ?? 'source_unavailable')); }
        if (($native['budget_version'] ?? null) !== null && ! $this->authorization->canCurrent($actor, 'finance.view_project_budget', ['organization_id' => $organizationId])) { throw new AccessDeniedHttpException; }
        foreach ($native['sources'] as $source) {
            $type = self::TYPES[$source['source_type']] ?? null;
            if ($type === null) { return $this->projection->insufficient('source_identity_unavailable'); }
            if (! $this->access->canReadEntity($actor, $organizationId, $type, (string) $source['source_id'])) { throw new AccessDeniedHttpException; }
        }
        $latest = $this->nativeAuthorization->createRun($request, 'project_margin');
        if ($latest['context']->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()
            || ! hash_equals($latest['authorization']->target->definition->definitionHash->value, $current['authorization']->target->definition->definitionHash->value)) { throw new AccessDeniedHttpException; }
        $this->assertCurrentAccess($actor, $organizationId, $projectId);
        foreach ($native['sources'] as $source) {
            if (! $this->access->canReadEntity($actor, $organizationId, self::TYPES[$source['source_type']], (string) $source['source_id'])) { throw new AccessDeniedHttpException; }
        }
        if (($native['budget_version'] ?? null) !== null && ! $this->authorization->canCurrent($actor, 'finance.view_project_budget', ['organization_id' => $organizationId])) { throw new AccessDeniedHttpException; }
        try { return $this->projection->project($native, $organizationId, $projectId); }
        catch (DomainException) { return $this->projection->insufficient('source_schema_unavailable'); }
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== 'live_project_financial_projection' || (int) ($reference['organization_id'] ?? 0) !== $organizationId
            || ! is_array($reference['composite_key'] ?? null) || ! is_string($reference['source_version'] ?? null)) { return false; }
        $key = $reference['composite_key'];
        if (($key['project_id'] ?? null) !== ($reference['entity_id'] ?? null)) { return false; }
        try {
            $organization = new Organization;
            $organization->id = $organizationId;
            $response = $this->read($actor, $organization, ['project_id' => $key['project_id'], 'period_start' => $key['period_start'] ?? null,
                'period_end' => $key['period_end'] ?? null, 'currency' => $reference['requested_currency'] ?? null]);
            foreach ($response['source_refs'] as $current) {
                if (AssistantSourceReferenceIdentity::key($current['composite_key']) === AssistantSourceReferenceIdentity::key($key)
                    && ($current['source_hash'] ?? null) === ($reference['source_hash'] ?? null) && $current['source_version'] === $reference['source_version']
                    && $current['formula_version'] === ($reference['formula_version'] ?? null) && $current['evidence_time_basis'] === ($reference['evidence_time_basis'] ?? null)) { return true; }
            }
        } catch (Throwable) { return false; }
        return false;
    }

    private function assertCurrentAccess(User $actor, int $organizationId, int $projectId): void
    {
        if (! $actor->is_active || (int) $actor->current_organization_id !== $organizationId
            || ! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'finance')
            || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)
            || ! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->where('status', 'active')->where('is_archived', false)->exists()
            || ! $this->authorization->canCurrent($actor, 'finance.view', ['organization_id' => $organizationId])) { throw new AccessDeniedHttpException; }
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) { return false; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
