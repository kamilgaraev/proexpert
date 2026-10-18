<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\User;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class AssistantPublishedReportCatalog
{
    public function __construct(
        private AssistantPublishedReportAccess $access,
        private AssistantDataAccessPolicy $policy,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportHttpAuthorizationTargetResolver $targets,
        private GetReportCatalogAction $catalog,
        private ReportDefinitionRegistry $definitions,
        private AssistantPublishedReportNativeFilters $nativeFilters,
    ) {}

    public function read(User $actor, int $organizationId, ?string $code = null): array
    {
        $this->access->actor((int) $actor->id, $organizationId, true);
        if ($this->policy->trustedSurface() !== KnowledgeSurface::ADMIN) {
            throw new AuthorizationException;
        }
        $authorization = $this->authorizer->authorizeCatalog((int) $actor->id, $organizationId,
            new DateTimeZone((string) config('app.timezone', 'UTC')), $this->targets->catalog());
        $catalog = $this->catalog->handle($authorization->context, $authorization);
        $items = [];
        foreach ($catalog->definitions as $view) {
            if (! $view->visibility->canRun || ! $view->visibility->canExport || ! $view->visibility->canDownload
                || $view->formats === [] || ($code !== null && $code !== $view->code)) {
                continue;
            }
            $item = ['report_code' => $view->code, 'title' => trans_message($view->titleKey), 'formats' => $view->formats,
                'project_required' => $view->code === 'accepted_production_progress'];
            if ($code !== null) {
                $definition = $this->definitions->published($code)->payload();
                $visible = static fn (string $id): bool => ($view->visibility->canViewSensitive || ! $definition->outputClassification->requiresSensitiveForColumns([$id]))
                    && ($view->visibility->canViewAudit || ! $definition->outputClassification->requiresAuditForColumns([$id]));
                $item += ['columns' => array_values(array_filter(AssistantPublishedReportColumns::exportable($code, $view->columns), static fn (array $column): bool => $visible($column['id']))),
                    'sorts' => array_values(array_filter($view->sorts, static fn (array $sort): bool => $visible($sort['id']))),
                    'filters' => $this->nativeFilters->describe($code, $view->filters)];
            }
            $items[] = $item;
        }
        if ($code !== null && $items === []) {
            throw new InvalidArgumentException(trans_message('ai_published_reports.invalid'));
        }

        return ['status' => 'success', 'items' => $items, 'total' => count($items)];
    }
}
