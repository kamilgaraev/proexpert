<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

final class NormativeSemanticCompatibilityService
{
    /**
     * @param  array<string, mixed>  $intent
     * @param  array<int, string>  $forbiddenDomainTerms
     */
    public function isCompatible(
        string $candidateText,
        string $workText,
        array $intent,
        array $forbiddenDomainTerms = [],
    ): bool {
        $candidateText = $this->normalize($candidateText);
        $workText = $this->normalize($workText);
        $candidateTitle = $this->normalize((string) ($intent['candidate_title'] ?? $candidateText));

        if ($candidateText === '') {
            return false;
        }

        if (! $this->operationCompatible($candidateTitle, $workText)) {
            return false;
        }

        if (! $this->additiveCompatible($candidateTitle, $workText)
            || ! $this->openingObjectCompatible($candidateTitle, $workText)) {
            return false;
        }

        $scope = trim((string) ($intent['scope'] ?? ''));
        if ($scope !== '' && $scope !== 'facade'
            && $this->containsAny($candidateTitle, ['фасад'])
            && ! $this->containsAny($workText, ['фасад'])) {
            return false;
        }

        $specializedDomains = array_values(array_unique([
            ...$forbiddenDomainTerms,
            'реактор',
            'атомн',
            'гидроэнергет',
            'гидротехническ',
            'карьерн',
            'горнопроход',
            'метрополитен',
            'тоннел',
            'железнодорож',
            'электростанц',
            'дизельн',
        ]));

        foreach ($specializedDomains as $term) {
            $term = $this->normalize($term);
            if ($term !== '' && str_contains($candidateText, $term) && ! str_contains($workText, $term)) {
                return false;
            }
        }

        foreach ($this->workSpecificConcepts() as $workMarkers => $candidateMarkers) {
            $markers = explode('|', $workMarkers);
            if ($this->containsAny($workText, $markers) && ! $this->containsAny($candidateTitle, $candidateMarkers)) {
                return false;
            }
        }

        $action = trim((string) ($intent['action'] ?? ''));
        $actionMarkers = $this->markersForAction($action);

        if (! $this->actionCompatible($action, $candidateTitle, $workText)) {
            return false;
        }

        $actionEvidenceText = $this->actionRequiresTitleEvidence($action) ? $candidateTitle : $candidateText;

        return $actionMarkers === [] || $this->containsAny($actionEvidenceText, $actionMarkers);
    }

    private function actionRequiresTitleEvidence(string $action): bool
    {
        return in_array($action, [
            'backfill',
            'soil_haulage',
            'planning',
            'concreting',
            'cable_installation',
            'pipe_layout',
            'window_installation',
        ], true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function workSpecificConcepts(): array
    {
        return [
            'перегород' => ['перегород', 'кладк'],
            'стропил' => ['стропил'],
            'водосток|водосточ' => ['водосток', 'водосточ'],
            'фасад' => ['фасад'],
            'пол' => ['пол', 'стяжк', 'основани под покрыт'],
            'лотк' => ['лотк'],
            'заземл' => ['заземл'],
            'розет|выключател' => ['розет', 'выключател'],
            'плинтус|галтел' => ['плинтус', 'галтел'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function markersForAction(string $action): array
    {
        return [
            'fence_installation' => ['огражд', 'забор'],
            'backfill' => ['засып', 'уплотнен'],
            'excavation' => ['разработк', 'выемк', 'котлован', 'транше'],
            'soil_haulage' => ['вывоз', 'перевоз', 'транспортир'],
            'planning' => ['планиров'],
            'concreting' => ['бетонир', 'бетонная смес', 'бетонной смес', 'бетонную смес', 'устройство бетонн', 'укладк бетонн'],
            'reinforcement' => ['арматур', 'армирован'],
            'formwork' => ['опалуб'],
            'masonry' => ['кладк', 'кирпич', 'блок', 'перегород'],
            'insulation' => ['утепл', 'теплоизоляц'],
            'waterproofing' => ['гидроизоляц', 'изоляц'],
            'plastering' => ['штукатур'],
            'painting' => ['окраск', 'покраск'],
            'tiling' => ['плитк', 'облицов'],
            'floor_covering' => ['покрыт', 'пол', 'линолеум', 'ламинат', 'паркет'],
            'ceiling_finishing' => ['потол', 'подвесн'],
            'baseboard_installation' => ['плинтус', 'галтел'],
            'cable_installation' => ['кабел', 'электропровод', 'проводк', 'лотк'],
            'socket_installation' => ['розет', 'выключател'],
            'pipe_layout' => ['труб', 'трубопровод'],
            'heating_equipment' => ['отопл', 'радиатор', 'котел', 'конвектор', 'теплов'],
            'ventilation_installation' => ['вентиляц', 'воздуховод'],
            'window_installation' => ['окон', 'окн', 'двер', 'ворот'],
        ][$action] ?? [];
    }

    private function actionCompatible(string $action, string $candidateTitle, string $workText): bool
    {
        if ($action === 'soil_haulage') {
            return $this->containsAny($candidateTitle, ['вывоз', 'перевоз', 'транспортир']);
        }

        if ($action === 'backfill') {
            $backfillPosition = $this->firstMarkerPosition($candidateTitle, ['засып', 'уплотнен']);
            $excavationPosition = $this->firstMarkerPosition($candidateTitle, ['разработк', 'выемк', 'котлован', 'транше']);
            if ($excavationPosition !== null
                && ($backfillPosition === null || $excavationPosition < $backfillPosition)) {
                return false;
            }
        }

        if ($action === 'excavation') {
            $excavationPosition = $this->firstMarkerPosition($candidateTitle, ['разработк', 'выемк', 'котлован', 'транше']);
            $backfillPosition = $this->firstMarkerPosition($candidateTitle, ['засып', 'уплотнен']);
            if ($backfillPosition !== null
                && ($excavationPosition === null || $backfillPosition < $excavationPosition)) {
                return false;
            }
        }

        if ($action === 'planning') {
            if ($this->containsAny($candidateTitle, ['откос']) && ! $this->containsAny($workText, ['откос'])) {
                return false;
            }

            if ($this->containsAny($workText, ['основан'])
                && ! $this->containsAny($candidateTitle, ['основан', 'площад', 'дно котлован', 'дно транше'])) {
                return false;
            }
        }

        return true;
    }

    private function operationCompatible(string $candidateTitle, string $workText): bool
    {
        $removalMarkers = ['демонтаж', 'разборк', 'снят'];
        $constructionMarkers = ['монтаж', 'установ', 'устройств', 'укладк', 'прокладк', 'кладк', 'нанесен'];
        $workRequiresRemoval = $this->containsAny($workText, $removalMarkers);
        $workRequiresConstruction = $this->containsConstructionOperation($workText, $constructionMarkers);
        $candidateHasRemoval = $this->containsAny($candidateTitle, $removalMarkers);
        $candidateHasConstruction = $this->containsConstructionOperation($candidateTitle, $constructionMarkers);

        if ($workRequiresRemoval && ! $candidateHasRemoval) {
            return false;
        }

        return ! ($workRequiresConstruction && $candidateHasRemoval && ! $candidateHasConstruction);
    }

    private function additiveCompatible(string $candidateTitle, string $workText): bool
    {
        $additiveMarkers = [
            'добавлять к норм',
            'добавлять или исключать',
            'на каждый последующ',
            'засыпка пустот',
        ];
        if (! $this->containsAny($candidateTitle, $additiveMarkers)) {
            return true;
        }

        return $this->containsAny($workText, [
            'добавочн',
            'дополнительн',
            'корректир',
            'последующ',
            'заполнен пустот',
            'засыпк пустот',
            'пустот',
            'исключать',
        ]);
    }

    private function openingObjectCompatible(string $candidateTitle, string $workText): bool
    {
        $workHasWindow = $this->containsAny($workText, ['окон', 'окн']);
        $workHasDoor = $this->containsAny($workText, ['двер']);
        $candidateHasWindow = $this->containsAny($candidateTitle, ['окон', 'окн']);
        $candidateHasDoor = $this->containsAny($candidateTitle, ['двер']);

        if ($workHasWindow && ! $workHasDoor && $candidateHasDoor && ! $candidateHasWindow) {
            return false;
        }

        return ! ($workHasDoor && ! $workHasWindow && $candidateHasWindow && ! $candidateHasDoor);
    }

    /** @param array<int, string> $constructionMarkers */
    private function containsConstructionOperation(string $text, array $constructionMarkers): bool
    {
        $withoutRemoval = preg_replace('/(?:демонтаж|разборк|снят)\p{L}*/u', ' ', $text) ?? $text;

        return $this->containsAny($withoutRemoval, $constructionMarkers);
    }

    /** @param array<int, string> $markers */
    private function firstMarkerPosition(string $text, array $markers): ?int
    {
        $positions = [];
        foreach ($markers as $marker) {
            $position = mb_strpos($text, $marker);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? null : min($positions);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
