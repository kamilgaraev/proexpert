<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final class NormativeSemanticCompatibilityService
{
    public function __construct(private ?ResidentialMaterialScenarioCatalog $materialScenarioCatalog = null) {}

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
            || ! $this->openingObjectCompatible($candidateTitle, $workText)
            || ! $this->targetCompatible($candidateTitle, $workText)
            || ! $this->specializationCompatible($candidateTitle, $workText, $intent)
            || ! $this->separateWorkCompatible($candidateText, $workText, $intent)
            || ! $this->objectTypeCompatible($candidateTitle, $workText, $intent)
            || ! $this->residentialEngineeringCompatible($candidateTitle, $workText, $intent)) {
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
            'горн выработ',
            'горным выработ',
            'шахтн',
            'рудник',
            'метрополитен',
            'тоннел',
            'железнодорож',
            'электростанц',
            'дизельн',
            'радиоактивн',
            'ядерн',
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

        if (! $this->actionCompatible($action, (string) ($intent['system'] ?? ''), $candidateTitle, $workText)) {
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
            'grounding_installation',
            'pipe_layout',
            'window_installation',
            'door_installation',
            'cable_tray_installation',
            'sanitary_fixture_installation',
            'sewer_revision_installation',
            'sewer_riser_installation',
            'sewer_outlet_installation',
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
            'пол' => ['пол', 'покрыт', 'стяжк', 'основани под покрыт'],
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
            'floor_covering' => ['покрыт', 'пол', 'линолеум', 'ламинат', 'ламинированн', 'паркет'],
            'floor_preparation' => ['подготов', 'стяжк', 'подстилающ', 'основани пола'],
            'ceiling_finishing' => ['потол', 'подвесн'],
            'baseboard_installation' => ['плинтус', 'галтел'],
            'cable_installation' => ['кабел', 'электропровод', 'проводк', 'лотк'],
            'cable_tray_installation' => ['лотк'],
            'grounding_installation' => ['заземл', 'заземляющ', 'электрод'],
            'socket_installation' => ['розет', 'выключател'],
            'pipe_layout' => ['труб', 'трубопровод'],
            'heating_equipment' => ['отопл', 'радиатор', 'котел', 'конвектор', 'теплов'],
            'ventilation_installation' => ['вентиляц', 'воздуховод'],
            'window_installation' => ['окон', 'окн', 'двер', 'ворот'],
            'door_installation' => ['двер'],
            'sanitary_fixture_installation' => ['санитарно-техническ', 'сантехническ', 'сантехприбор', 'умывальник', 'раковин', 'мойк', 'унитаз', 'ванн', 'душев', 'смесител'],
            'sewer_revision_installation' => ['ревиз'],
            'sewer_riser_installation' => ['стояк'],
            'sewer_outlet_installation' => ['выпуск'],
        ][$action] ?? [];
    }

    private function actionCompatible(string $action, string $system, string $candidateTitle, string $workText): bool
    {
        if ($action === 'window_installation') {
            if ($this->containsAny($candidateTitle, ['откос']) && ! $this->containsAny($workText, ['откос'])) {
                return false;
            }

            return $this->containsAny($candidateTitle, ['установ', 'монтаж', 'заполнение ', 'устройство блок']);
        }

        if ($action === 'door_installation') {
            if ($this->containsAny($candidateTitle, ['балконн'])
                && ! $this->containsAny($workText, ['балконн'])) {
                return false;
            }

            if ($this->containsAny($candidateTitle, ['конопат', 'уплотнен'])
                && ! $this->containsAny($workText, ['конопат', 'уплотнен'])) {
                return false;
            }

            if (! $this->containsAny($candidateTitle, ['установ', 'монтаж', 'заполнение ', 'устройство блок'])) {
                return false;
            }
        }

        if ($action === 'cable_tray_installation') {
            $candidateInstallsTray = preg_match(
                '/(?:монтаж|установк\p{L}*|устройств\p{L}*)\s+(?:(?!кабел(?:ь|я|ю|ем|е)(?:\s|$))\S+\s+){0,4}лотк\p{L}*/u',
                $candidateTitle
            ) === 1;
            $candidateLaysCable = $this->containsAny($candidateTitle, ['прокладк', 'укладк', 'установк кабел'])
                && $this->containsAny($candidateTitle, ['кабел']);

            return $candidateInstallsTray
                && ! ($candidateLaysCable && ! $candidateInstallsTray);
        }

        if ($action === 'cable_installation') {
            if ($this->containsAny($workText, ['демонтаж', 'разборк', 'снят'])
                && $this->containsAny($candidateTitle, ['демонтаж', 'разборк', 'снят'])) {
                return true;
            }

            $explicitInstallation = $this->containsAny($candidateTitle, ['проклад', 'прокладыв', 'уклад', 'затягив', 'протяж']);
            $catalogInstallationForm = $this->containsAny($candidateTitle, ['кабел'])
                && $this->containsAny($candidateTitle, ['по установленн конструкц', 'по установленн лотк', 'по установленным конструкциям', 'по установленным лоткам']);

            if ($this->containsAny($candidateTitle, ['транше'])
                && ! $this->containsAny($workText, ['транше', 'наружн'])) {
                return false;
            }

            return $this->containsAny($candidateTitle, ['кабел', 'электропровод', 'провод'])
                && ($explicitInstallation || $catalogInstallationForm);
        }

        if ($action === 'waterproofing'
            && $this->containsAny($candidateTitle, ['выравниван', 'подготовк поверхност', 'оштукатур'])
            && ! $this->containsAny($workText, ['выравниван', 'подготовк поверхност', 'оштукатур'])) {
            return false;
        }

        if ($action === 'sanitary_fixture_installation') {
            if ($this->containsAny($candidateTitle, ['манометр', 'термометр', 'датчик давлен', 'люк', 'ревизи'])) {
                return false;
            }

            if ($this->containsAny($workText, ['прибор', 'точк'])) {
                return $this->containsAny($candidateTitle, [
                    'прибор', 'умывальник', 'раковин', 'мойк', 'унитаз', 'ванн', 'душ', 'смесител',
                ]);
            }
        }

        if ($action === 'door_installation') {
            return ! ($this->containsAny($candidateTitle, ['шкафн', 'шкафов', 'шкафа'])
                && ! $this->containsAny($workText, ['шкафн', 'шкафов', 'шкафа']));
        }

        if (in_array($action, [
            'sewer_revision_installation',
            'sewer_riser_installation',
            'sewer_outlet_installation',
        ], true) && $this->containsAny($candidateTitle, ['врезк', 'подключен к действующ'])) {
            return false;
        }

        if ($action === 'pipe_layout'
            && trim($system) === 'sewerage'
            && $this->containsAny($candidateTitle, ['транше'])
            && ! $this->containsAny($workText, ['транше', 'наружн'])) {
            return false;
        }

        if ($action === 'pipe_layout'
            && in_array(trim($system), ['water_supply', 'heating'], true)
            && ($this->containsAny($candidateTitle, ['канализац'])
                || ($this->containsAny($candidateTitle, ['транше'])
                    && ! $this->containsAny($workText, ['транше', 'наружн'])))) {
            return false;
        }

        if ($action === 'pipe_layout'
            && $this->containsAny($candidateTitle, ['испытан', 'изготовлен', 'сборка узлов'])
            && ! $this->containsAny($workText, ['испытан', 'изготовлен', 'сборка узлов'])) {
            return false;
        }

        if ($action === 'pipe_layout'
            && ! $this->containsAny($candidateTitle, ['прокладк', 'монтаж', 'установк', 'устройств трубопровод', 'укладк труб'])) {
            return false;
        }

        if ($action === 'grounding_installation'
            && $this->containsAny($candidateTitle, ['шина заземлен'])
            && ! $this->containsAny($workText, ['шина заземлен'])) {
            return false;
        }

        if ($action === 'grounding_installation'
            && $this->containsAny($candidateTitle, ['шпал', 'железнодорож'])
            && ! $this->containsAny($workText, ['шпал', 'железнодорож'])) {
            return false;
        }

        if ($action === 'floor_preparation'
            && $this->containsAny($candidateTitle, ['покрыт'])
            && ! $this->containsAny($candidateTitle, ['стяжк', 'подстилающ', 'подготов', 'основани пола'])) {
            return false;
        }

        if ($action === 'reinforcement') {
            if ($this->containsAny($candidateTitle, ['композитн'])
                && ! $this->containsAny($workText, ['композитн'])) {
                return false;
            }

            if ($this->containsAny($candidateTitle, ['муфтов', 'муфт соединен'])
                && ! $this->containsAny($workText, ['муфтов', 'муфт соединен'])) {
                return false;
            }
        }

        if ($action === 'floor_covering'
            && $this->containsAny($candidateTitle, ['полимерцемент', 'поливинилацетат', 'цементобетон'])
            && ! $this->containsAny($workText, ['полимерцемент', 'поливинилацетат', 'цементобетон'])) {
            return false;
        }

        if ($action === 'baseboard_installation'
            && $this->containsAny($candidateTitle, ['цементн'])
            && ! $this->containsAny($workText, ['цементн'])) {
            return false;
        }

        if ($action === 'painting'
            && $this->containsAny($candidateTitle, ['под окраск'])
            && ! $this->containsAny($candidateTitle, ['окраска', 'окрашиван', 'нанесение краск'])) {
            return false;
        }

        if ($action === 'painting'
            && $this->containsAny($candidateTitle, ['шпатлев', 'шпаклев'])
            && ! $this->containsAny($workText, ['шпатлев', 'шпаклев'])) {
            return false;
        }

        if ($action === 'soil_haulage') {
            return $this->containsAny($candidateTitle, ['вывоз', 'перевоз', 'транспортир'])
                && ! ($this->containsAny($candidateTitle, ['землесосн', 'плавуч', 'станци перекач'])
                    && ! $this->containsAny($workText, ['землесосн', 'плавуч', 'станци перекач']))
                && ! ($this->containsAny($candidateTitle, ['разработк', 'выемк'])
                    && ! $this->containsAny($workText, ['разработк', 'выемк']));
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
            if ($this->containsAny($candidateTitle, ['вручную', 'ручн'])
                && ! $this->containsAny($workText, ['вручную', 'ручн'])) {
                return false;
            }

            if ($this->containsAny($candidateTitle, ['вечномерзл', 'мерзл', 'отбойными молот'])
                && ! $this->containsAny($workText, ['вечномерзл', 'мерзл', 'отбойными молот'])) {
                return false;
            }

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

    /** @param array<string, mixed> $intent */
    private function specializationCompatible(string $candidateTitle, string $workText, array $intent): bool
    {
        $specializationEvidenceText = $this->specializationEvidenceText($intent);

        if (! $this->finishingPhaseCompatible($candidateTitle, $workText, $intent)
            || ! $this->finishingMaterialCompatible($candidateTitle, $specializationEvidenceText, $intent)
            || ! $this->materialScenarioCandidateCompatible($candidateTitle, $intent)) {
            return false;
        }

        if (($intent['action'] ?? null) === 'floor_covering'
            && $this->hasUnconfirmedSpecialization(
                $candidateTitle,
                $specializationEvidenceText,
                ['полиуретан', 'полимер', 'наливн']
            )) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->hasUnconfirmedSpecialization(
                $candidateTitle,
                $specializationEvidenceText,
                ['плоск', 'полиуретан', 'полимер', 'антикорроз', 'наливн']
            )) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && str_contains($candidateTitle, 'мастик')
            && ! str_contains($specializationEvidenceText, 'мастик')) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->containsAny($candidateTitle, ['козыр', 'навес'])
            && ! $this->containsAny($specializationEvidenceText, ['козыр', 'навес'])) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->containsAny($candidateTitle, ['антиобледен', 'снеготаян', 'электронагрев'])
            && ! $this->containsAny($specializationEvidenceText, ['антиобледен', 'снеготаян', 'электронагрев'])) {
            return false;
        }

        if (! $this->catalogSpecializationCompatible($candidateTitle, $specializationEvidenceText, $intent)) {
            return false;
        }

        if (! $this->facadeMaterialCompatible($candidateTitle, $specializationEvidenceText, $intent)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function materialScenarioCandidateCompatible(string $candidateTitle, array $intent): bool
    {
        $scenario = is_array($intent['specialization_scenario'] ?? null) ? $intent['specialization_scenario'] : [];
        $this->materialScenarioCatalog ??= new ResidentialMaterialScenarioCatalog;
        $resolvedScenario = $this->materialScenarioCatalog->resolve(
            $scenario,
            (string) ($scenario['work_item_key'] ?? ''),
            ObjectTypeSignalClassifier::canonical((string) ($intent['object_type'] ?? '')),
        );
        if ($resolvedScenario === null) {
            return true;
        }

        $markers = array_values(array_filter(
            $resolvedScenario['material_markers'] ?? [],
            static fn (mixed $marker): bool => is_string($marker) && trim($marker) !== '',
        ));

        return $markers === [] || $this->containsAny($candidateTitle, $markers);
    }

    /** @param array<string, mixed> $intent */
    private function catalogSpecializationCompatible(string $candidateTitle, string $evidenceText, array $intent): bool
    {
        $action = (string) ($intent['action'] ?? '');
        $scope = (string) ($intent['scope'] ?? '');

        if ($scope === 'roof' && $action === 'insulation') {
            foreach ([
                ['ячеист'],
                ['фибролит'],
                ['минераловат', 'минеральн ват'],
            ] as $markers) {
                if ($this->hasUnconfirmedMarkerGroup($candidateTitle, $evidenceText, $markers)) {
                    return false;
                }
            }

            if ($this->containsAll($candidateTitle, ['легк', 'бетон'])
                && ! $this->containsAll($evidenceText, ['легк', 'бетон'])) {
                return false;
            }
        }

        if ($scope === 'roof'
            && $this->hasUnconfirmedMarkerGroup(
                $candidateTitle,
                $evidenceText,
                ['цементно-песчан', 'цементно песчан']
            )) {
            return false;
        }

        if ($scope === 'walls' && $action === 'masonry') {
            foreach ([['кирпич'], ['газобетон', 'ячеист']] as $markers) {
                if ($this->hasUnconfirmedMarkerGroup($candidateTitle, $evidenceText, $markers)) {
                    return false;
                }
            }
        }

        if ($scope === 'foundation' && $action === 'waterproofing') {
            foreach ([
                ['цементн'],
                ['рулонн', 'оклеечн'],
                ['мастич', 'мастик', 'обмазочн'],
            ] as $markers) {
                if ($this->hasUnconfirmedMarkerGroup($candidateTitle, $evidenceText, $markers)) {
                    return false;
                }
            }

            if ($this->containsAll($candidateTitle, ['жидк', 'стекл'])
                && ! $this->containsAll($evidenceText, ['жидк', 'стекл'])) {
                return false;
            }
        }

        if ($scope === 'stairs'
            && $this->containsAll($candidateTitle, ['древес', 'тверд'])
            && ! $this->containsAll($evidenceText, ['древес', 'тверд'])) {
            return false;
        }

        if ($scope === 'openings'
            && ! $this->openingSpecializationCompatible($candidateTitle, $evidenceText)) {
            return false;
        }

        if ($scope === 'slabs' && $action === 'concreting') {
            if ($this->containsAll($candidateTitle, ['кран', 'бадь'])
                && ! $this->containsAll($evidenceText, ['кран', 'бадь'])) {
                return false;
            }

            if ($this->containsAll($candidateTitle, ['ячейк', 'до 10'])
                && ! $this->containsAll($evidenceText, ['ячейк', 'до 10'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $markers */
    private function hasUnconfirmedMarkerGroup(string $candidateTitle, string $evidenceText, array $markers): bool
    {
        return $this->containsAny($candidateTitle, $markers)
            && ! $this->containsAny($evidenceText, $markers);
    }

    private function openingSpecializationCompatible(string $candidateTitle, string $evidenceText): bool
    {
        foreach ([
            ['кирпич'],
            ['ячеист', 'газобетон'],
            ['деревянн стен', 'рубленн стен'],
            ['одностворч'],
            ['двухстворч', 'двустворч'],
            ['трехстворч', 'трёхстворч'],
            ['глух створ'],
            ['поворотно-откид', 'поворотно откид'],
        ] as $markers) {
            if ($this->hasUnconfirmedMarkerGroup($candidateTitle, $evidenceText, $markers)) {
                return false;
            }
        }

        if (preg_match_all('/(?:до|более|свыше)\s*\d+(?:[.,]\d+)?\s*м(?:2|²)/u', $candidateTitle, $matches) !== false) {
            foreach ($matches[0] as $openingSize) {
                if (! str_contains($evidenceText, $openingSize)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function facadeMaterialCompatible(string $candidateTitle, string $evidenceText, array $intent): bool
    {
        $objectType = trim((string) ($intent['object_type'] ?? ''));
        if (($intent['scope'] ?? null) !== 'facade'
            || ($objectType !== '' && ! ObjectTypeSignalClassifier::isResidential($objectType))) {
            return true;
        }

        $materialMarkerGroups = [
            ['фиброцемент', 'fiber_cement'],
            ['хризотилцемент', 'chrysotile_cement', 'asbestos_cement'],
            ['керамогранит', 'porcelain_stoneware', 'porcelain_tile'],
            ['сайдинг'],
            ['металлокассет', 'металлическими кассет', 'metal_cassette'],
            ['композитн', 'composite_panel'],
            ['стеклянной крош', 'стеклянная крош', 'стеклянную крош', 'glass_crumb'],
            ['мраморной крош', 'мраморная крош', 'мраморную крош', 'marble_crumb'],
            ['терразит', 'terrazite', 'terrazzo'],
            ['природным кам', 'природного кам', 'каменн облицов', 'natural_stone'],
        ];

        foreach ($materialMarkerGroups as $markers) {
            if ($this->containsAny($candidateTitle, $markers)
                && ! $this->containsAny($evidenceText, $markers)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function finishingPhaseCompatible(string $candidateTitle, string $workText, array $intent): bool
    {
        if (($intent['scope'] ?? null) !== 'finishing') {
            return true;
        }

        $roughWallPreparation = $this->containsAny($workText, ['чернов', 'подготов'])
            && $this->containsAny($workText, ['стен', 'поверхност']);
        $candidateIsFinish = $this->containsAny($candidateTitle, [
            'окраск',
            'окрашив',
            'покраск',
            'побелк',
            'нанесение краск',
            'декоративн',
            'финишн',
            'мелкозернист',
            'облицов',
            'плитк',
        ]);

        return ! ($roughWallPreparation && $candidateIsFinish);
    }

    /** @param array<string, mixed> $intent */
    private function finishingMaterialCompatible(string $candidateTitle, string $evidenceText, array $intent): bool
    {
        if (($intent['scope'] ?? null) !== 'finishing') {
            return true;
        }

        $action = (string) ($intent['action'] ?? '');
        $markerGroups = match ($action) {
            'floor_covering' => [
                ['линолеум'],
                ['поливинилхлорид', 'пвх'],
                ['ламинат', 'ламинированн'],
                ['паркет'],
                ['дощат', 'доск', 'деревян', 'древес'],
                ['керамическ', 'керамогранит', 'плитк'],
                ['ковролин', 'ковров'],
                ['резинов'],
                ['пробков'],
            ],
            'baseboard_installation' => [
                ['деревян', 'древес'],
                ['поливинилхлорид', 'пвх', 'пластмасс', 'пластик'],
                ['алюминиев'],
                ['керамическ'],
                ['каменн', 'мрамор', 'гранит'],
                ['цементн'],
            ],
            default => [],
        };

        foreach ($markerGroups as $markers) {
            if ($this->hasUnconfirmedMarkerGroup($candidateTitle, $evidenceText, $markers)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function specializationEvidenceText(array $intent): string
    {
        $parts = [];
        $evidence = is_array($intent['specialization_evidence'] ?? null)
            ? $intent['specialization_evidence']
            : [];

        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }

            $evidenceRefs = is_array($item['evidence_refs'] ?? null)
                ? array_values(array_filter(
                    $item['evidence_refs'],
                    static fn (mixed $ref): bool => is_string($ref) && trim($ref) !== '',
                ))
                : [];
            if (! in_array($item['source'] ?? null, ['document', 'building_model', 'user_confirmation'], true)
                || $evidenceRefs === []) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $scenario = is_array($intent['specialization_scenario'] ?? null) ? $intent['specialization_scenario'] : [];
        $this->materialScenarioCatalog ??= new ResidentialMaterialScenarioCatalog;
        $resolvedScenario = $this->materialScenarioCatalog->resolve(
            $scenario,
            (string) ($scenario['work_item_key'] ?? ''),
            ObjectTypeSignalClassifier::canonical((string) ($intent['object_type'] ?? '')),
        );
        if ($resolvedScenario !== null) {
            $parts = [
                ...$parts,
                ...array_values(array_filter(
                    $resolvedScenario['material_markers'] ?? [],
                    static fn (mixed $marker): bool => is_string($marker) && trim($marker) !== '',
                )),
            ];
        }

        return $this->normalize(implode(' ', $parts));
    }

    /** @param array<int, string> $markers */
    private function hasUnconfirmedSpecialization(string $candidateTitle, string $workText, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($candidateTitle, $marker) && ! str_contains($workText, $marker)) {
                return true;
            }
        }

        return false;
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
            'добавлять при',
            'добавлять или исключать',
            'на изменение толщины',
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
            'изменен толщин',
            'увеличен количеств',
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

    private function targetCompatible(string $candidateTitle, string $workText): bool
    {
        $candidateHasGlassBlocks = $this->containsAny($candidateTitle, ['стеклоблок'])
            || ($this->containsAny($candidateTitle, ['стеклянн']) && $this->containsAny($candidateTitle, ['блок']));
        $workHasGlassBlocks = $this->containsAny($workText, ['стеклоблок'])
            || ($this->containsAny($workText, ['стеклянн']) && $this->containsAny($workText, ['блок']));
        if ($candidateHasGlassBlocks && ! $workHasGlassBlocks) {
            return false;
        }

        $candidateHasFacadeAccessories = ($this->containsAny($candidateTitle, ['стальн', 'металлическ'])
                && $this->containsAny($candidateTitle, ['обдел']))
            || ($this->containsAny($candidateTitle, ['водосточн']) && $this->containsAny($candidateTitle, ['труб']));
        $workHasFacadeAccessories = ($this->containsAny($workText, ['стальн', 'металлическ'])
                && $this->containsAny($workText, ['обдел']))
            || $this->containsAny($workText, ['водосточн', 'водосток']);
        if ($candidateHasFacadeAccessories && ! $workHasFacadeAccessories) {
            return false;
        }

        $workHasStairPlatform = $this->containsAny($workText, ['лестничн']) && $this->containsAny($workText, ['площад']);
        $candidateHasStairPlatform = ($this->containsAny($candidateTitle, ['лестничн'])
                && $this->containsAny($candidateTitle, ['площад']))
            || ($this->containsAny($candidateTitle, ['площад']) && $this->containsAny($candidateTitle, ['лестниц']));
        if ($workHasStairPlatform && ! $candidateHasStairPlatform) {
            return false;
        }

        $workHasStairFlight = $this->containsAny($workText, ['лестничн'])
            && $this->containsAny($workText, ['марш']);
        if ($workHasStairFlight
            && $this->containsAny($candidateTitle, ['опалуб'])
            && ! $this->containsAny($workText, ['опалуб'])) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function objectTypeCompatible(string $candidateTitle, string $workText, array $intent): bool
    {
        if (! ObjectTypeSignalClassifier::isResidential((string) ($intent['object_type'] ?? ''))) {
            return true;
        }

        $nonResidentialMarkers = [
            'промышленн', 'производственн', 'сельскохозяйственн', 'складск', 'складов',
        ];

        return ! ($this->containsAny($candidateTitle, $nonResidentialMarkers)
            && ! $this->containsAny($workText, $nonResidentialMarkers));
    }

    /** @param array<string, mixed> $intent */
    private function separateWorkCompatible(string $candidateText, string $workText, array $intent): bool
    {
        $workIsRafterInstallation = $this->containsAny($workText, ['стропил']);
        $workMentionsRoof = $this->containsAny($workText, ['кровл', 'кровел']);
        $workIsExplicitRoofSubsystem = $this->containsAny($workText, [
            'утепл', 'теплоизоляц', 'стяжк', 'основан', 'пароизоляц', 'гидроизоляц',
            'водосточ', 'водосток', 'снегозадерж', 'антиобледен', 'огражден',
        ]);
        $workIsRoofCovering = $workMentionsRoof
            && ! $workIsRafterInstallation
            && ! $workIsExplicitRoofSubsystem
            && ($this->containsAny($workText, ['покрыт', 'кровельн ков'])
                || $this->containsAny($workText, ['монтаж кров', 'устройство кров']));
        $candidateMentionsRoof = $this->containsAny($candidateText, ['кровл', 'кровел']);
        $candidateHasRoofCovering = $candidateMentionsRoof
            && ($this->containsAny($candidateText, ['покрыт'])
                || ($this->containsAny($candidateText, ['устройство кров', 'монтаж кров'])
                    && $this->containsAny($candidateText, [
                        'металлочереп', 'черепиц', 'профнаст', 'профилированн', 'кровельн сталь',
                        'листов', 'рулонн', 'мембран', 'шифер',
                    ])));
        $candidateHasRafters = $this->containsAny($candidateText, ['стропил']);

        if ($workIsRoofCovering && ! $candidateHasRoofCovering) {
            return false;
        }

        if ($workIsRoofCovering
            && $this->containsAny($candidateText, ['огнезащит'])
            && ! $this->containsAny($workText, ['огнезащит'])) {
            return false;
        }

        if (($workIsRoofCovering && ! $workIsRafterInstallation && $candidateHasRafters)
            || ($workIsRafterInstallation && ! $workIsRoofCovering && $candidateHasRoofCovering)) {
            return false;
        }

        $concepts = $intent['separate_work_concepts'] ?? [];
        if (! is_array($concepts)) {
            return true;
        }

        foreach ($concepts as $concept) {
            $concept = $this->normalize((string) $concept);
            if ($concept !== '' && str_contains($candidateText, $concept)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $intent */
    private function residentialEngineeringCompatible(string $candidateTitle, string $workText, array $intent): bool
    {
        if (! ObjectTypeSignalClassifier::isResidential((string) ($intent['object_type'] ?? ''))
            || (string) ($intent['action'] ?? '') !== 'pipe_layout') {
            return true;
        }

        $candidateDiameter = $this->diameterMillimeters($candidateTitle);
        if ($candidateDiameter === null) {
            return true;
        }

        $workDiameter = $this->diameterMillimeters($workText);
        if ($workDiameter !== null) {
            return abs($candidateDiameter - $workDiameter) < 0.001;
        }

        $system = (string) ($intent['system'] ?? '');
        if ($system === '') {
            $system = match (true) {
                $this->containsAny($workText, ['водоснаб', 'хвс', 'гвс']) => 'water_supply',
                $this->containsAny($workText, ['отоплен', 'теплоснаб']) => 'heating',
                $this->containsAny($workText, ['канализац']) => 'sewerage',
                default => '',
            };
        }

        return match ($system) {
            'water_supply', 'heating' => $candidateDiameter <= 50,
            'sewerage' => $candidateDiameter <= 110,
            default => true,
        };
    }

    private function diameterMillimeters(string $text): ?float
    {
        if (preg_match('/диаметр\p{L}*\s*:?\s*(\d+(?:[.,]\d+)?)\s*мм/u', $text, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
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

    /** @param array<int, string> $needles */
    private function containsAll(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '' || ! str_contains($text, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
