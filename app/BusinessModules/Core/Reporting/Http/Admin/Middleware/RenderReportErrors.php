<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
use App\Services\Logging\Context\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RenderReportErrors
{
    public function __construct(
        private ReportErrorResponseFactory $responses,
        private RequestContext $requestContext,
        private LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ReportContractException $exception) {
            return $this->responses->make(
                $exception,
                $this->requestContext->getCorrelationId(),
            );
        } catch (Throwable $exception) {
            $correlationId = $this->requestContext->getCorrelationId();
            $this->logger->error('report_request_failed', [
                'code' => ReportErrorCode::REPORT_INTERNAL_ERROR->value,
                'exception_class' => $exception::class,
                'organization_id' => $this->organizationId($request),
                'actor_id' => $this->actorId($request),
                'correlation_id' => $correlationId,
            ]);

            return $this->responses->make(
                ReportContractException::fromCode(
                    ReportErrorCode::REPORT_INTERNAL_ERROR,
                ),
                $correlationId,
            );
        }
    }

    private function organizationId(Request $request): ?int
    {
        return $this->positiveInteger(
            $request->attributes->get('current_organization_id')
                ?? $request->attributes->get('organization_id'),
        );
    }

    private function actorId(Request $request): ?int
    {
        try {
            return $this->positiveInteger(
                $request->user()?->getAuthIdentifier()
                    ?? $request->attributes->get('actor_id'),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
