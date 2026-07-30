<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspaceDisplayPreferences;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use Illuminate\Validation\Rule;

final class UpdateReportWorkspacePreferencesRequest extends ReportFormRequest
{
    public function rules(): array
    {
        $groups = array_map(static fn (ReportCatalogGroup $group): string => $group->value, ReportCatalogGroup::ordered());

        return [
            'display_preferences' => ['required', 'array:catalog_group_order,collapsed_catalog_groups,landing_section'],
            'display_preferences.catalog_group_order' => ['required', 'array', 'size:7'],
            'display_preferences.catalog_group_order.*' => ['required', 'string', 'distinct:strict', Rule::in($groups)],
            'display_preferences.collapsed_catalog_groups' => ['required', 'array'],
            'display_preferences.collapsed_catalog_groups.*' => ['required', 'string', 'distinct:strict', Rule::in($groups)],
            'display_preferences.landing_section' => ['required', 'string', Rule::in(['catalog', 'recent', 'favourites', 'saved_views', 'exports'])],
            'owner_id' => ['prohibited'],
            ...$this->forbiddenClientFieldsRules(),
        ];
    }

    protected function acceptedBodyFields(): array
    {
        return ['display_preferences'];
    }

    public function display(): ReportWorkspaceDisplayPreferences
    {
        $display = (array) $this->validated('display_preferences');

        return new ReportWorkspaceDisplayPreferences(
            (array) $display['catalog_group_order'],
            (array) $display['collapsed_catalog_groups'],
            (string) $display['landing_section'],
        );
    }
}
