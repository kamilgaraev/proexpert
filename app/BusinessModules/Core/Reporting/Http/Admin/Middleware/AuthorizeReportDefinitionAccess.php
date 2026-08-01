<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\Models\User;
use App\Modules\Services\ModulePermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthorizeReportDefinitionAccess
{
    public const ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE = 'report_accessible_definition_hashes';

    public function __construct(
        private ReportHttpAuthorizationTargetResolver $targets,
        private ModulePermissionService $modules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                $this->deny();
            }

            $routeName = $request->route()?->getName();
            if (! is_string($routeName)) {
                $this->deny();
            }

            if ($routeName === 'admin.reports.catalog') {
                $hashes = $this->catalogHashes($actor);
                if ($hashes === []) {
                    $this->deny();
                }
                $request->attributes->set(self::ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE, $hashes);
            } else {
                $target = $this->target($request, $routeName);
                if (! $this->modules->userHasModuleAccess($actor, $target->definition->sourceModule)) {
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
            'admin.reports.runs.store' => $this->targets->createRun($this->routeId($request, 'reportCode')),
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

    private function catalogHashes(User $actor): array
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
            $moduleAccess[$module] ??= $this->modules->userHasModuleAccess($actor, $module);
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
            $this->deny();
        }

        return $value;
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}
