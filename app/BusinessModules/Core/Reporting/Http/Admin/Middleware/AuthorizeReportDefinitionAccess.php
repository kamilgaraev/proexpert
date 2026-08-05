<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthorizeReportDefinitionAccess
{
    public const ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE = 'report_accessible_definition_hashes';

    public function __construct(
        private ReportHttpAuthorizationTargetResolver $targets,
        private ReportDefinitionModuleAuthorizer $modules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                $this->deny();
            }
            $organizationId = $request->attributes->get('current_organization_id');
            if (! is_int($organizationId) || $organizationId <= 0) {
                $this->deny();
            }

            $routeName = $request->route()?->getName();
            if (! is_string($routeName)) {
                $this->deny();
            }

            if ($routeName === 'admin.reports.catalog') {
                $hashes = $this->catalogHashes($organizationId);
                if ($hashes === []) {
                    $this->deny();
                }
                $request->attributes->set(self::ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE, $hashes);
            } else {
                $target = $this->target($request, $routeName);
                if (! $this->modules->allows($organizationId, $target->definition)) {
                    $this->deny();
                }
            }
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }

        return $next($request);
    }

    private function target(Request $request, string $routeName): CurrentReportAuthorizationTarget
    {
        return match ($routeName) {
            'admin.reports.runs.store' => $this->genericCreateRun($request),
            'admin.reports.project-budget-plan-fact.runs.store',
            'admin.reports.project-budget-plan-fact.options',
            'admin.reports.project-margin.runs.store',
            'admin.reports.project-margin.options',
            'admin.reports.project-evm-control.runs.store',
            'admin.reports.project-evm-control.options',
            'admin.reports.wip-completion-forecast.runs.store',
            'admin.reports.wip-completion-forecast.options',
            'admin.reports.project-labor-cost.runs.store',
            'admin.reports.payroll-readiness.runs.store',
            'admin.reports.project-labor-cost.options',
            'admin.reports.payroll-readiness.options',
            'admin.reports.workforce-capacity.runs.store',
            'admin.reports.workforce-capacity.options',
            'admin.reports.portfolio-liquidity.options' => $this->targets->createRun(
                $this->routeId($request, 'reportCode'),
            ),
            'admin.reports.runs.show', 'admin.reports.runs.rows' => $this->targets->run(
                $this->routeId($request, 'runId'),
                ReportOperation::VIEW,
            ),
            'admin.reports.runs.drill-down' => $this->targets->run(
                $this->routeId($request, 'runId'),
                ReportOperation::DRILL_DOWN,
            ),
            'admin.reports.runs.retry', 'admin.reports.runs.cancel' => $this->targets->run(
                $this->routeId($request, 'runId'),
                ReportOperation::RUN,
            ),
            'admin.reports.exports.store' => $this->targets->createExport(
                $this->routeId($request, 'runId'),
                null,
            ),
            'admin.reports.exports.show' => $this->targets->export(
                $this->routeId($request, 'exportId'),
                ReportOperation::VIEW,
            ),
            'admin.reports.exports.retry', 'admin.reports.exports.cancel' => $this->targets->export(
                $this->routeId($request, 'exportId'),
                ReportOperation::EXPORT,
            ),
            'admin.reports.exports.download-link' => $this->targets->export(
                $this->routeId($request, 'exportId'),
                ReportOperation::DOWNLOAD,
            ),
            default => $this->deny(),
        };
    }

    private function genericCreateRun(Request $request): CurrentReportAuthorizationTarget
    {
        $reportCode = $this->routeId($request, 'reportCode');
        if (in_array($reportCode, [
            BudgetPlanFactCandidateContract::CODE,
            ProjectEvmControlCandidateContract::CODE,
            ProjectMarginCandidateContract::CODE,
            WipCompletionForecastCandidateContract::CODE,
            ProjectLaborCostCandidateContract::CODE,
            PayrollReadinessCandidateContract::CODE,
            WorkforceCapacityCandidateContract::CODE,
        ], true)) {
            $this->deny();
        }

        return $this->targets->createRun($reportCode);
    }

    private function catalogHashes(int $organizationId): array
    {
        $hashes = [];
        $moduleAccess = [];
        foreach ($this->targets->catalog() as $target) {
            if (! $target instanceof CurrentReportAuthorizationTarget
                || $target->operation !== ReportOperation::VIEW
                || $target->snapshot !== null) {
                $this->deny();
            }

            $module = $target->definition->sourceModule;
            $moduleAccess[$module] ??= $this->modules->allows($organizationId, $target->definition);
            if ($moduleAccess[$module]) {
                $hashes[$target->definition->definitionHash->value] = true;
            }
        }

        $result = array_keys($hashes);
        sort($result, SORT_STRING);

        return $result;
    }

    private function routeId(Request $request, string $key): string
    {
        $value = $request->route($key);
        if (! is_string($value) || trim($value) === '') {
            $this->invalidRouteId($key);
        }
        if ($key === 'reportCode') {
            if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $value) !== 1) {
                $this->invalidRouteId($key);
            }
        } elseif (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) !== 1) {
            $this->invalidRouteId($key);
        }

        return $value;
    }

    private function invalidRouteId(string $key): never
    {
        $field = match ($key) {
            'reportCode' => 'report_code',
            'runId' => 'run_id',
            'exportId' => 'export_id',
            default => 'route_id',
        };

        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            ['fields' => [$field]],
        );
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}
