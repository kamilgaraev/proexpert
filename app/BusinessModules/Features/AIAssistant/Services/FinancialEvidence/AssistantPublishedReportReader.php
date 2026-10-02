<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class AssistantPublishedReportReader
{
    public function __construct(private ReportHttpAuthorizationOrchestrator $authorization, private GetReportRunAction $runs, private GetReportRowsAction $rows,
        private ReportRunStore $store, private ReportDefinitionBindingMap $bindings, private AssistantPublishedReportProjection $projection) {}

    public function read(User $actor, Organization $organization, string $runId, ?string $cursor = null, int $limit = 20): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $runId) !== 1 || $limit < 1 || $limit > 20 || ($cursor !== null && strlen($cursor) > 4096)) {
            throw new DomainException('assistant_report_projection_arguments_invalid');
        }
        if (! $actor->is_active || (int) $actor->current_organization_id !== (int) $organization->id) { throw new AccessDeniedHttpException; }
        $request = Request::create('/api/v1/admin/reports/runs/'.$runId.'/rows', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', (int) $organization->id);
        $current = $this->authorization->rows($request, $runId);
        $context = $current['context'];
        if ($context->actor->id !== (int) $actor->id || $context->scope->organizationId !== (int) $organization->id) { throw new AccessDeniedHttpException; }
        $run = $this->runs->handle($context, $runId);
        if ($run->status !== ReportRunStatus::READY || $run->resultMetadata === null || ! $this->projection->supports($run->reportCode)) {
            throw new DomainException('assistant_report_projection_run_unavailable');
        }
        $definition = $current['authorization']->target->definition;
        $binding = $this->bindings->get($run->reportCode);
        $query = $this->store->queryForRun($context, $runId);
        if ($definition->code !== $run->reportCode || ! hash_equals($definition->definitionHash->value, $run->definitionHash->value)
            || ! hash_equals($binding->definitionHash->value, $run->definitionHash->value) || $binding->contractVersion !== $run->contractVersion
            || ! hash_equals($query->queryHash->value, $run->queryHash->value) || $query->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new DomainException('assistant_report_projection_identity_invalid');
        }
        $snapshot = $run->resultMetadata->snapshot;
        $result = $binding->dataProvider->result($context, $snapshot);
        if ($result->metadata->snapshot->id !== $snapshot->id || $result->metadata->snapshot->kind !== $snapshot->kind
            || ! hash_equals($result->metadata->snapshot->sourceHash->value, $snapshot->sourceHash->value)) {
            throw new DomainException('assistant_report_projection_identity_invalid');
        }
        $sort = $definition->sorts[0] ?? null;
        if (! is_array($sort) || ! is_string($sort['id'] ?? null) || ! is_string($sort['direction'] ?? null)) {
            throw new DomainException('assistant_report_projection_sort_unavailable');
        }
        $page = $this->rows->handle($context, $runId, new ReportRowsWindow($cursor, $limit, new ReportWindowSort($sort['id'], ReportSortDirection::from($sort['direction']))));
        $latest = $this->authorization->rows($request, $runId);
        $latestContext = $latest['context'];
        if ($latestContext->actor->id !== (int) $actor->id || $latestContext->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()
            || ! hash_equals($latest['authorization']->target->definition->definitionHash->value, $definition->definitionHash->value)
            || ($definition->outputClassification->requiresSensitiveForRows() && ! $latestContext->visibility->canViewSensitive)
            || ($definition->outputClassification->requiresAuditForRows() && ! $latestContext->visibility->canViewAudit)) {
            throw new AccessDeniedHttpException;
        }
        $latestRun = $this->runs->handle($latestContext, $runId);
        $latestSnapshot = $latestRun->resultMetadata?->snapshot;
        if ($latestRun->status !== ReportRunStatus::READY || $latestRun->reportCode !== $run->reportCode || $latestSnapshot === null
            || $latestSnapshot->id !== $snapshot->id || $latestSnapshot->kind !== $snapshot->kind
            || ! hash_equals($latestSnapshot->sourceHash->value, $snapshot->sourceHash->value)
            || ! hash_equals($latestRun->definitionHash->value, $run->definitionHash->value)) {
            throw new DomainException('assistant_report_projection_identity_invalid');
        }
        $context = $latestContext;
        $run = $latestRun;
        $denied = [...($context->visibility->canViewSensitive ? [] : $definition->outputClassification->sensitiveColumnIds),
            ...($context->visibility->canViewAudit ? [] : $definition->outputClassification->auditColumnIds)];
        $schema = array_values(array_filter($result->rowSchema, static fn (array $column): bool => ! in_array($column['id'], $denied, true)));
        $visible = new ReportResult($result->metadata, $run->totals, $result->freshness, $result->quality, $result->provenance, $schema, $result->capabilities);
        return $this->projection->project($runId, $run->reportCode, (int) $organization->id, $visible, $page, $query->asOf, new DateTimeImmutable, $cursor);
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== 'published_report_financial_projection' || (int) ($reference['organization_id'] ?? 0) !== $organizationId
            || ! is_string($reference['entity_id'] ?? null) || ! is_array($reference['composite_key'] ?? null)
            || ! is_string($reference['source_version'] ?? null) || ! is_int($reference['limit'] ?? null)
            || (! is_string($reference['cursor'] ?? null) && ($reference['cursor'] ?? null) !== null)) {
            return false;
        }
        try {
            $organization = new Organization;
            $organization->id = $organizationId;
            $result = $this->read($actor, $organization, $reference['entity_id'], $reference['cursor'] ?? null, $reference['limit']);
            foreach ($result['source_refs'] as $current) {
                if (AssistantSourceReferenceIdentity::key($current['composite_key']) !== AssistantSourceReferenceIdentity::key($reference['composite_key'])) { continue; }
                foreach (['report_code', 'snapshot_kind', 'snapshot_id', 'definition_hash', 'source_hash', 'source_version', 'formula_version', 'as_of', 'generated_at', 'evidence_time_basis'] as $field) {
                    if (($current[$field] ?? null) !== ($reference[$field] ?? null)) { continue 2; }
                }
                return true;
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }
}
