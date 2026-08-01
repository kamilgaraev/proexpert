<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
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
        public ?string $exportFormat,
        private CurrentReportExactManyAuthorizer $authorizer,
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
        $requiresExportFormat = isset($seen[ReportOperation::EXPORT->value])
            || isset($seen[ReportOperation::DOWNLOAD->value]);
        if (($exportFormat !== null && ! in_array($exportFormat, $subject->definition->formats, true))
            || ($requiresExportFormat && $exportFormat === null)
            || ($subject->aggregateKind->value === 'export'
                && ($subject->exportFormat === null || $subject->exportFormat !== $exportFormat))) {
            throw new InvalidArgumentException('report_authorization_fence_invalid');
        }

        $this->fingerprint = hash('sha256', CanonicalJson::encode([
            'subject' => $subject->canonicalFingerprint(),
            'operations' => array_keys($seen),
            'export_format' => $exportFormat,
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

    /**
     * @param  list<ReportOperation>  $expected
     */
    public function assertOperations(array $expected): void
    {
        $actualValues = array_map(
            static fn (ReportOperation $operation): string => $operation->value,
            $this->operations,
        );
        $expectedValues = array_map(
            static fn (ReportOperation $operation): string => $operation->value,
            $expected,
        );
        sort($actualValues, SORT_STRING);
        sort($expectedValues, SORT_STRING);

        if ($actualValues !== $expectedValues) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    public function assertCurrent(ReportExecutionContext $context): ReportExecutionContext
    {
        self::assertExactScope($context, $this->subject);
        $targets = [];
        foreach ($this->operations as $operation) {
            $targets[] = new CurrentReportAuthorizationTarget(
                $this->subject->definition,
                $operation,
                $operation === ReportOperation::RUN ? null : $this->subject->snapshot,
                $this->exportFormat,
            );
        }
        $authorizations = $this->authorizer->authorizeExactMany(
            $context->actor->id,
            $context->scope,
            $targets,
        );
        if (count($authorizations) !== count($targets)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $currentContext = null;
        foreach ($authorizations as $index => $authorization) {
            $target = $targets[$index];
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
