<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use InvalidArgumentException;

final readonly class ReportWorkspaceDisplayPreferences
{
    public function __construct(
        public array $catalogGroupOrder,
        public array $collapsedCatalogGroups,
        public string $landingSection,
    ) {
        $groups = array_map(
            static fn (ReportCatalogGroup $group): string => $group->value,
            ReportCatalogGroup::ordered(),
        );

        if (
            ! array_is_list($catalogGroupOrder)
            || count($catalogGroupOrder) !== count($groups)
            || count(array_unique($catalogGroupOrder, SORT_STRING)) !== count($groups)
            || array_diff($catalogGroupOrder, $groups) !== []
            || ! array_is_list($collapsedCatalogGroups)
            || count(array_unique($collapsedCatalogGroups, SORT_STRING)) !== count($collapsedCatalogGroups)
            || array_diff($collapsedCatalogGroups, $groups) !== []
            || ! in_array($landingSection, ['catalog', 'recent', 'favourites', 'saved_views', 'exports'], true)
        ) {
            throw new InvalidArgumentException('report_workspace_display_preferences_invalid');
        }
    }

    public static function defaults(): self
    {
        return new self(
            array_map(static fn (ReportCatalogGroup $group): string => $group->value, ReportCatalogGroup::ordered()),
            [],
            'catalog',
        );
    }
}
