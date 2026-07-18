<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

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
            if ($this->containsAny($candidateTitle, ['конопат', 'уплотнен'])
                && ! $this->containsAny($workText, ['конопат', 'уплотнен'])) {
                return false;
            }

            if (! $this->containsAny($candidateTitle, ['установ', 'монтаж', 'заполнение ', 'устройство блок'])) {
                return false;
            }
        }

        if ($action === 'cable_tray_installation') {
            $candidateInstallsTray = $this->containsAny($candidateTitle, ['монтаж', 'установк', 'устройств'])
                && $this->containsAny($candidateTitle, ['лотк']);
            $candidateLaysCable = $this->containsAny($candidateTitle, ['прокладк', 'укладк'])
                && $this->containsAny($candidateTitle, ['кабел']);

            return $candidateInstallsTray
                && ! ($candidateLaysCable && ! $candidateInstallsTray);
        }

        if ($action === 'cable_installation') {
            if ($this->containsAny($workText, ['демонтаж', 'разборк', 'снят'])
                && $this->containsAny($candidateTitle, ['демонтаж', 'разборк', 'снят'])) {
                return true;
            }

            return $this->containsAny($candidateTitle, ['кабел', 'электропровод', 'провод'])
                && $this->containsAny($candidateTitle, ['проклад', 'прокладыв', 'уклад', 'затягив', 'протяж']);
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
        if (($intent['action'] ?? null) === 'floor_covering'
            && $this->hasUnconfirmedSpecialization(
                $candidateTitle,
                $workText,
                ['полиуретан', 'полимер', 'наливн']
            )) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->hasUnconfirmedSpecialization(
                $candidateTitle,
                $workText,
                ['плоск', 'полиуретан', 'полимер', 'антикорроз', 'наливн']
            )) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && str_contains($candidateTitle, 'мастик')
            && ! str_contains($workText, 'мастик')) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->containsAny($candidateTitle, ['козыр', 'навес'])
            && ! $this->containsAny($workText, ['козыр', 'навес'])) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'roof'
            && $this->containsAny($candidateTitle, ['антиобледен', 'снеготаян', 'электронагрев'])
            && ! $this->containsAny($workText, ['антиобледен', 'снеготаян', 'электронагрев'])) {
            return false;
        }

        if (($intent['scope'] ?? null) === 'facade'
            && str_contains($candidateTitle, 'терразит')
            && ! str_contains($workText, 'терразит')) {
            return false;
        }

        return true;
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

        if ($this->containsAny($candidateTitle, ['стеклянн крошк', 'стеклянной крошк'])
            && ! $this->containsAny($workText, ['стеклянн крошк', 'стеклянной крошк'])) {
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

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
