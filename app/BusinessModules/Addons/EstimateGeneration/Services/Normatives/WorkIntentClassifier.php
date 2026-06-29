<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;

final class WorkIntentClassifier
{
    public function __construct(
        private readonly NormativeScopeRuleCatalog $scopeRuleCatalog,
    ) {}

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     */
    public function classify(array $workItem, array $context = []): WorkIntentData
    {
        $text = $this->text($workItem, $context);
        $signals = [];
        $scope = $this->scope($text, (string) ($context['scope_type'] ?? $workItem['scope_type'] ?? ''), $signals);
        $system = $this->system($text, $signals);
        $action = $this->action($text, $system, $signals);
        $object = $this->object($text, $scope);
        $material = $this->material($text);
        $expectedDimensions = $this->expectedDimensions((string) ($workItem['unit'] ?? ''), $action);
        $rules = $this->scopeRuleCatalog->rulesFor($scope, $system, $action);

        return new WorkIntentData(
            scope: $scope,
            action: $action,
            object: $object,
            material: $material,
            system: $system,
            expectedDimensions: $expectedDimensions,
            preferredNormTypes: $rules['preferred_norm_types'],
            forbiddenNormTypes: $rules['forbidden_norm_types'],
            preferredSectionPrefixes: $rules['preferred_section_prefixes'],
            forbiddenSectionPrefixes: $rules['forbidden_section_prefixes'],
            confidence: $this->confidence($signals, $scope, $action),
            signals: array_values(array_unique($signals)),
        );
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     */
    private function text(array $workItem, array $context): string
    {
        $parts = [
            $workItem['name'] ?? '',
            $workItem['description'] ?? '',
            $workItem['work_category'] ?? '',
            $workItem['normative_search_text'] ?? '',
            $context['scope_type'] ?? '',
            $context['section_title'] ?? '',
            $context['local_estimate_title'] ?? '',
        ];

        return mb_strtolower(trim(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $parts))));
    }

    /**
     * @param array<int, string> $signals
     */
    private function scope(string $text, string $contextScope, array &$signals): string
    {
        $contextScope = $this->normalizeScope($contextScope);

        foreach ([
            'roof' => ['кровл', 'стропил', 'мауэрлат'],
            'stairs' => ['лестниц', 'лестничн', 'марш'],
            'engineering' => ['отоплен', 'теплов', 'теплоснаб', 'завес', 'водоснаб', 'канализац', 'вентиляц', 'электр', 'кабел', 'труб', 'котел', 'радиатор'],
            'foundation' => ['фундамент', 'ростверк', 'свая', 'основани'],
            'walls' => ['стен', 'перегород', 'кладк'],
            'slabs' => ['перекрыт', 'плит'],
            'facade' => ['фасад'],
            'openings' => ['окн', 'двер', 'ворот'],
            'finishing' => ['отделк', 'штукатур', 'окраск', 'шпатлев', 'плитк'],
            'temporary' => ['временн', 'стройплощад', 'огражден'],
            'site' => ['благоустрой', 'планировк', 'вывоз грунта'],
        ] as $scope => $needles) {
            if ($this->containsAny($text, $needles)) {
                $signals[] = 'scope_' . $scope;

                return $scope;
            }
        }

        if ($contextScope !== '') {
            $signals[] = 'scope_context';

            return $contextScope;
        }

        $signals[] = 'scope_unknown';

        return 'general';
    }

    /**
     * @param array<int, string> $signals
     */
    private function system(string $text, array &$signals): ?string
    {
        foreach ([
            'electrical' => ['электр', 'кабел', 'освещ'],
            'heating' => ['отоплен', 'радиатор', 'теплоснаб', 'теплов', 'завес', 'котел'],
            'water_supply' => ['водоснаб', 'хвс', 'гвс'],
            'sewerage' => ['канализац'],
            'ventilation' => ['вентиляц', 'воздуховод'],
        ] as $system => $needles) {
            if ($this->containsAny($text, $needles)) {
                $signals[] = 'system_' . $system;

                return $system;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $signals
     */
    private function action(string $text, ?string $system, array &$signals): string
    {
        if (in_array($system, ['water_supply', 'sewerage'], true) && $this->containsAny($text, ['арматур', 'сантехническ', 'канализац'])) {
            $signals[] = 'action_pipe_layout';

            return 'pipe_layout';
        }

        foreach ([
            'insulation' => ['утепл', 'теплоизоляц'],
            'plastering' => ['штукатур'],
            'cable_installation' => ['кабел'],
            'pipe_layout' => ['разводк труб', 'прокладк труб', 'труб отоплен'],
            'heating_equipment' => ['тепловой узел', 'теплового узла', 'теплопункт', 'завес', 'радиатор', 'котел', 'конвектор', 'теплогенератор'],
            'window_installation' => ['установк окон', 'монтаж окон', 'окн', 'двер', 'ворот'],
            'masonry' => ['кладк', 'кирпич', 'блок'],
            'ventilation_installation' => ['монтаж вентиляц', 'вентиляц', 'воздуховод'],
            'socket_installation' => ['розет', 'выключател'],
            'fence_installation' => ['огражден', 'забор'],
            'formwork' => ['опалуб'],
            'reinforcement' => ['армирован', 'арматур'],
            'concreting' => ['бетонир', 'бетон b', 'бетон в', 'b22', 'b25'],
            'waterproofing' => ['гидроизоляц'],
            'backfill' => ['обратн засып'],
            'excavation' => ['разработк грунт', 'котлован', 'транше'],
            'planning' => ['планировк'],
        ] as $action => $needles) {
            if ($this->containsAny($text, $needles)) {
                $signals[] = 'action_' . $action;

                return $action;
            }
        }

        if ($system === 'electrical') {
            $signals[] = 'action_cable_installation';

            return 'cable_installation';
        }

        if ($system === 'ventilation') {
            $signals[] = 'action_ventilation_installation';

            return 'ventilation_installation';
        }

        if ($system === 'heating' || $system === 'water_supply' || $system === 'sewerage') {
            $signals[] = 'action_pipe_layout';

            return 'pipe_layout';
        }

        $signals[] = 'action_general';

        return 'general_work';
    }

    private function object(string $text, string $scope): ?string
    {
        if ($this->containsAny($text, ['ленточн фундамент', 'фундаментн лент'])) {
            return 'strip_foundation';
        }

        if ($this->containsAny($text, ['кровл'])) {
            return 'roof';
        }

        if ($this->containsAny($text, ['лестниц', 'лестничн', 'марш'])) {
            return 'stairs';
        }

        if ($this->containsAny($text, ['окн', 'двер', 'ворот'])) {
            return 'opening';
        }

        if ($this->containsAny($text, ['тепловой узел', 'теплового узла', 'теплопункт', 'завес', 'радиатор', 'котел'])) {
            return 'heating_equipment';
        }

        if ($this->containsAny($text, ['кабел'])) {
            return 'cable_line';
        }

        if ($this->containsAny($text, ['труб'])) {
            return 'pipe';
        }

        return $scope !== 'general' ? $scope : null;
    }

    private function material(string $text): ?string
    {
        return match (true) {
            $this->containsAny($text, ['b22', 'b25', 'бетон', 'железобетон']) => 'concrete',
            $this->containsAny($text, ['газобетон', 'газоблок']) => 'aerated_concrete',
            $this->containsAny($text, ['кирпич']) => 'brick',
            $this->containsAny($text, ['арматур']) => 'reinforcement_steel',
            $this->containsAny($text, ['утепл', 'минват', 'пенополистир']) => 'insulation',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function expectedDimensions(string $unit, string $action): array
    {
        $parsed = NormativeUnitNormalizer::parseDetailed($unit);

        if ($parsed->dimension !== '') {
            return [$parsed->dimension];
        }

        return match ($action) {
            'cable_installation', 'pipe_layout' => ['length'],
            'insulation', 'formwork', 'waterproofing' => ['area'],
            'masonry' => ['volume'],
            'plastering', 'ventilation_installation' => ['area'],
            'window_installation', 'heating_equipment' => ['piece'],
            'fence_installation' => ['length'],
            'concreting', 'excavation', 'backfill' => ['volume'],
            'reinforcement' => ['mass'],
            default => ['piece'],
        };
    }

    /**
     * @param array<int, string> $signals
     */
    private function confidence(array $signals, string $scope, string $action): float
    {
        $score = 0.35;

        if ($scope !== 'general') {
            $score += 0.2;
        }

        if ($action !== 'general_work') {
            $score += 0.25;
        }

        $score += min(count($signals), 4) * 0.04;

        return round(min($score, 0.95), 4);
    }

    private function normalizeScope(string $scope): string
    {
        $scope = mb_strtolower(trim($scope));

        return match ($scope) {
            'foundation', 'roof', 'stairs', 'engineering', 'walls', 'slabs', 'facade', 'openings', 'finishing', 'site', 'temporary' => $scope,
            'electrical', 'plumbing', 'heating', 'ventilation' => 'engineering',
            'rough_finishing', 'finish_finishing' => 'finishing',
            default => '',
        };
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
