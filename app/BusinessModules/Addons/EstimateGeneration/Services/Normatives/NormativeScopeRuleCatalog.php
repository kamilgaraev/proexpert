<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

final class NormativeScopeRuleCatalog
{
    /**
     * @return array{
     *     preferred_norm_types: array<int, string>,
     *     forbidden_norm_types: array<int, string>,
     *     preferred_section_prefixes: array<int, string>,
     *     forbidden_section_prefixes: array<int, string>
     * }
     */
    public function rulesFor(string $scope, ?string $system = null, ?string $action = null): array
    {
        $rules = $this->scopeRules($scope);

        if ($scope === 'engineering') {
            $rules = $this->mergeRules($rules, $this->engineeringRules($system, $action));
        }

        if ($scope === 'foundation') {
            $rules = $this->mergeRules($rules, $this->foundationActionRules($action));
        }

        return $rules;
    }

    /**
     * @return array{
     *     preferred_norm_types: array<int, string>,
     *     forbidden_norm_types: array<int, string>,
     *     preferred_section_prefixes: array<int, string>,
     *     forbidden_section_prefixes: array<int, string>
     * }
     */
    private function scopeRules(string $scope): array
    {
        return match ($scope) {
            'foundation' => $this->rule(['gesn_building', 'gesn_concrete'], [], ['01', '06', '07', '08'], []),
            'roof' => $this->rule(['gesn_building', 'gesnr_roof'], ['gesn_earthwork'], ['10', '12', '26'], ['01']),
            'engineering' => $this->rule(['gesnm', 'gesnp'], ['gesn_earthwork'], ['08', '16', '18', '20'], ['01']),
            'walls' => $this->rule(['gesn_building'], ['gesn_earthwork'], ['07', '08', '10'], ['01']),
            'slabs' => $this->rule(['gesn_building', 'gesn_concrete'], ['gesn_earthwork'], ['06', '07'], ['01']),
            'facade' => $this->rule(['gesn_building', 'gesnr_finishing'], ['gesn_earthwork'], ['15', '26'], ['01']),
            'finishing' => $this->rule(['gesnr_finishing', 'gesn_building'], ['gesn_earthwork'], ['15'], ['01']),
            'site' => $this->rule(['gesn_earthwork', 'gesn_building'], [], ['01', '27'], []),
            'temporary' => $this->rule(['gesn_building'], [], ['01', '08', '09'], []),
            default => $this->rule([], [], [], []),
        };
    }

    private function engineeringRules(?string $system, ?string $action): array
    {
        if ($system === 'electrical' || $action === 'cable_installation') {
            return $this->rule(['gesnm_electrical', 'gesnp_electrical'], ['gesn_earthwork'], ['08'], ['01']);
        }

        if ($system === 'heating' || $action === 'pipe_layout') {
            return $this->rule(['gesnm_heating', 'gesnp_plumbing'], ['gesn_earthwork'], ['16', '18'], ['01']);
        }

        if ($system === 'ventilation') {
            return $this->rule(['gesnm_ventilation'], ['gesn_earthwork'], ['20'], ['01']);
        }

        return $this->rule([], ['gesn_earthwork'], [], ['01']);
    }

    private function foundationActionRules(?string $action): array
    {
        return match ($action) {
            'excavation', 'backfill' => $this->rule(['gesn_earthwork'], [], ['01'], []),
            'concreting', 'reinforcement', 'formwork' => $this->rule(['gesn_concrete'], [], ['06'], []),
            'waterproofing' => $this->rule(['gesn_building'], [], ['08', '12'], []),
            default => $this->rule([], [], [], []),
        };
    }

    /**
     * @param array<int, string> $preferredNormTypes
     * @param array<int, string> $forbiddenNormTypes
     * @param array<int, string> $preferredSectionPrefixes
     * @param array<int, string> $forbiddenSectionPrefixes
     * @return array{
     *     preferred_norm_types: array<int, string>,
     *     forbidden_norm_types: array<int, string>,
     *     preferred_section_prefixes: array<int, string>,
     *     forbidden_section_prefixes: array<int, string>
     * }
     */
    private function rule(
        array $preferredNormTypes,
        array $forbiddenNormTypes,
        array $preferredSectionPrefixes,
        array $forbiddenSectionPrefixes
    ): array {
        return [
            'preferred_norm_types' => $preferredNormTypes,
            'forbidden_norm_types' => $forbiddenNormTypes,
            'preferred_section_prefixes' => $preferredSectionPrefixes,
            'forbidden_section_prefixes' => $forbiddenSectionPrefixes,
        ];
    }

    /**
     * @param array<string, array<int, string>> $base
     * @param array<string, array<int, string>> $extra
     * @return array<string, array<int, string>>
     */
    private function mergeRules(array $base, array $extra): array
    {
        foreach ($extra as $key => $values) {
            $base[$key] = array_values(array_unique([
                ...($base[$key] ?? []),
                ...$values,
            ]));
        }

        return $base;
    }
}
