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

        if ($candidateText === '') {
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
        ]));

        foreach ($specializedDomains as $term) {
            $term = $this->normalize($term);
            if ($term !== '' && str_contains($candidateText, $term) && ! str_contains($workText, $term)) {
                return false;
            }
        }

        foreach ($this->workSpecificConcepts() as $workMarkers => $candidateMarkers) {
            $markers = explode('|', $workMarkers);
            if ($this->containsAny($workText, $markers) && ! $this->containsAny($candidateText, $candidateMarkers)) {
                return false;
            }
        }

        $action = trim((string) ($intent['action'] ?? ''));
        $actionMarkers = $this->actionMarkers()[$action] ?? [];

        return $actionMarkers === [] || $this->containsAny($candidateText, $actionMarkers);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function workSpecificConcepts(): array
    {
        return [
            'перегород' => ['перегород', 'кладк'],
            'стропил' => ['стропил'],
            'водосток' => ['водосток'],
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
    private function actionMarkers(): array
    {
        return [
            'fence_installation' => ['огражд', 'забор'],
            'backfill' => ['засып', 'уплотнен'],
            'excavation' => ['разработк', 'выемк', 'котлован', 'транше'],
            'planning' => ['планиров'],
            'concreting' => ['бетонир', 'бетонн', 'укладк бетон'],
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
            'cable_installation' => ['кабел', 'провод', 'лотк'],
            'socket_installation' => ['розет', 'выключател'],
            'pipe_layout' => ['труб', 'трубопровод'],
            'heating_equipment' => ['отопл', 'радиатор', 'котел', 'конвектор', 'теплов'],
            'ventilation_installation' => ['вентиляц', 'воздуховод'],
            'window_installation' => ['окн', 'двер', 'ворот'],
        ];
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
