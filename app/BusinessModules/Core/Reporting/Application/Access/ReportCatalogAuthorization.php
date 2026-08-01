<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use InvalidArgumentException;

final readonly class ReportCatalogAuthorization
{
    /** @var array<string, CurrentReportAuthorization> */
    public array $authorizations;

    /** @param array<string, CurrentReportAuthorization> $authorizations */
    public function __construct(
        public ReportExecutionContext $context,
        array $authorizations,
    ) {
        $ordered = [];
        $entries = [];

        foreach ($authorizations as $hash => $authorization) {
            if (
                ! is_string($hash)
                || ! $authorization instanceof CurrentReportAuthorization
                || $authorization->target->definition->definitionHash->value !== $hash
                || $authorization->target->operation !== ReportOperation::VIEW
                || $authorization->target->snapshot !== null
                || ! $authorization->visibility->canView
                || $authorization->actor->id !== $context->actor->id
                || (new ReportScope(
                    $authorization->decision->organizationId,
                    $authorization->decision->holdingOrganizationIds,
                    $authorization->decision->projectIds,
                    $authorization->decision->resources,
                    $authorization->decision->timezone,
                ))->canonicalIdentity() !== $context->scope->canonicalIdentity()
            ) {
                throw new InvalidArgumentException('report_catalog_authorization_invalid');
            }

            $entries[] = [
                'hash' => $hash,
                'code' => $authorization->target->definition->code,
                'authorization' => $authorization,
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => [$left['code'], $left['hash']]
                <=> [$right['code'], $right['hash']],
        );
        foreach ($entries as $entry) {
            if (isset($ordered[$entry['hash']])) {
                throw new InvalidArgumentException('report_catalog_authorization_invalid');
            }
            $ordered[$entry['hash']] = $entry['authorization'];
        }

        $this->authorizations = $ordered;
    }
}
