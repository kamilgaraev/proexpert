<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use InvalidArgumentException;

final readonly class GetReportCatalogHandler implements GetReportCatalogAction
{
    public function __construct(
        private ReportDefinitionRegistry $registry,
        private ReportCatalogMetadataRegistry $metadata,
        private ReportSchedulingCapabilityRegistry $scheduling,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        ReportCatalogAuthorization $authorization,
    ): ReportCatalogView {
        if ($authorization->context->actor->id !== $context->actor->id
            || $authorization->context->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('report_catalog_authorization_invalid');
        }

        $metadataByCode = [];
        foreach ($this->registry->publishedCodes() as $code) {
            $metadataByCode[$code] = $this->metadata->published($code);
        }

        $groupRanks = array_flip(array_map(
            static fn (ReportCatalogGroup $group): string => $group->value,
            ReportCatalogGroup::ordered(),
        ));
        $codes = array_keys($metadataByCode);
        usort(
            $codes,
            static fn (string $left, string $right): int => [
                $groupRanks[$metadataByCode[$left]->catalogGroup->value],
                $metadataByCode[$left]->manifestOrdinal,
            ] <=> [
                $groupRanks[$metadataByCode[$right]->catalogGroup->value],
                $metadataByCode[$right]->manifestOrdinal,
            ],
        );

        $definitions = [];
        foreach ($codes as $code) {
            $published = $this->registry->published($code);
            $visibility = $this->visibilityFromAuthorization($authorization, $published->definitionHash->value);

            if ($visibility === null) {
                continue;
            }

            $definitions[] = ReportCatalogDefinitionView::from(
                $published,
                $metadataByCode[$code],
                $this->scheduling->published($code),
                $visibility,
            );
        }

        return new ReportCatalogView(
            '1.0.0',
            $this->registry->manifestSha256(),
            $definitions,
        );
    }

    private function visibilityFromAuthorization(
        ReportCatalogAuthorization $authorization,
        string $definitionHash,
    ): ?\App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility {
        $entry = $authorization->authorizations[$definitionHash] ?? null;

        return $entry?->visibility;
    }
}
