<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportAuthorizationFence
{
    public string $fingerprint;

    public function __construct(
        public ReportAuthorizationSubject $subject,
        public array $operations,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
    ) {
        if (! array_is_list($operations) || $operations === []) {
            throw new InvalidArgumentException('report_authorization_fence_invalid');
        }

        $seen = [];
        foreach ($operations as $operation) {
            if (! $operation instanceof ReportOperation || isset($seen[$operation->value])) {
                throw new InvalidArgumentException('report_authorization_fence_invalid');
            }
            $seen[$operation->value] = true;
        }

        $this->fingerprint = hash('sha256', CanonicalJson::encode([
            'subject' => $subject->canonicalFingerprint(),
            'operations' => array_keys($seen),
        ]));
    }

    public static function assertExactScope(
        ReportExecutionContext $context,
        ReportAuthorizationSubject $subject,
    ): void {
        if ($context->scope->canonicalIdentity() !== $subject->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
    }

    public function assertCurrent(ReportExecutionContext $context): ReportExecutionContext
    {
        self::assertExactScope($context, $this->subject);
        $currentContext = null;

        foreach ($this->operations as $operation) {
            $target = new CurrentReportAuthorizationTarget(
                $this->subject->definition,
                $operation,
                $operation === ReportOperation::RUN ? null : $this->subject->snapshot,
            );
            $authorization = $this->authorizer->authorizeExact(
                $context->actor->id,
                $context->scope,
                $target,
            );
            if ($authorization->actor->id !== $context->actor->id
                || $authorization->target->canonicalFingerprint() !== $target->canonicalFingerprint()) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }

            $currentContext = $this->contexts->fromCurrentAuthorization($authorization);
            if ($currentContext->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
        }

        if (! $currentContext instanceof ReportExecutionContext) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $currentContext;
    }
}
