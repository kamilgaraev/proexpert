<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeSearchProfileData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;

final class NormativeSearchProfileCatalog
{
    public function forIntentData(WorkIntentData $intent): NormativeSearchProfileData
    {
        return $this->forIntent($intent->scope, $intent->action, $intent->system);
    }

    public function forIntent(string $scope, ?string $action = null, ?string $system = null): NormativeSearchProfileData
    {
        $scope = $this->normalize($scope);
        $action = $action !== null ? $this->normalize($action) : null;
        $system = $system !== null ? $this->normalize($system) : null;
        $base = $this->baseProfile($scope, $action, $system);
        $specific = $this->specificProfile($scope, $action, $system);

        return $this->profile(
            $scope,
            $action,
            $system,
            array_values(array_unique([...$base->requiredTerms, ...$specific->requiredTerms])),
            array_values(array_unique([...$base->synonymTerms, ...$specific->synonymTerms])),
            $specific->allowedSectionPrefixes !== [] ? $specific->allowedSectionPrefixes : $base->allowedSectionPrefixes,
            array_values(array_unique([...$base->forbiddenSectionPrefixes, ...$specific->forbiddenSectionPrefixes])),
            array_values(array_unique([...$base->forbiddenDomainTerms, ...$specific->forbiddenDomainTerms])),
            $specific->allowedAnalogActions !== []
                ? $specific->allowedAnalogActions
                : $base->allowedAnalogActions,
        );
    }

    private function baseProfile(string $scope, ?string $action, ?string $system): NormativeSearchProfileData
    {
        if ($scope === 'engineering') {
            return match ($system) {
                'electrical' => $this->profile($scope, $action, $system, ['кабел', 'электр'], ['проклад', 'щит', 'розет', 'освещ'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '27', '28'], ['землян', 'шпунт', 'железнодорож', 'кран портальн'], ['cable_installation', 'socket_installation', 'electrical_panel_installation', 'lighting_fixture_installation']),
                'heating' => $action === 'heating_emitter_installation'
                    ? $this->profile($scope, $action, $system, ['радиатор'], ['отопительн', 'прибор', 'конвектор'], ['18'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '16', '20', '27', '28'], ['котел', 'котл', 'тепловой узел', 'завес'], ['heating_emitter_installation'])
                    : ($action === 'heating_equipment'
                    ? $this->profile($scope, $action, $system, ['отопл'], ['радиатор', 'завес', 'котел', 'тепл', 'узел', 'оборуд'], ['18', '20'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '27', '28'], ['землян', 'шпунт', 'железнодорож', 'кран портальн'], ['heating_equipment'])
                    : $this->profile($scope, $action, $system, ['отопл', 'труб'], ['радиатор', 'завес', 'котел', 'тепл'], ['16', '18', '20'], ['01', '03', '05', '07', '09', '10', '12', '15', '27', '28'], ['землян', 'шпунт', 'железнодорож', 'кран портальн'], ['pipe_layout', 'heating_equipment'])),
                'ventilation' => $this->profile($scope, $action, $system, ['вентиляц', 'воздуховод'], ['канал', 'решетк', 'установк'], ['20'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '27', '28'], ['землян', 'шпунт', 'железнодорож', 'кран портальн'], ['ventilation_installation']),
                'water_supply', 'sewerage' => $this->profile($scope, $action, $system, ['труб'], ['водоснаб', 'канализац', 'арматур'], ['16'], ['01', '03', '05', '07', '09', '10', '12', '15', '18', '20', '27', '28'], ['землян', 'шпунт', 'железнодорож', 'кран портальн'], ['pipe_layout']),
                default => $this->profile($scope, $action, $system, ['монтаж'], ['труб', 'кабел', 'оборуд'], ['08', '16', '18', '20'], ['01', '03', '05', '07', '09', '10', '12', '15', '27', '28'], ['землян', 'шпунт', 'железнодорож'], ['pipe_layout', 'cable_installation', 'ventilation_installation']),
            };
        }

        return match ($scope) {
            'foundation' => $this->profile($scope, $action, $system, ['фундамент'], ['бетон', 'арматур', 'опалуб', 'грунт'], ['01', '06'], [], ['железнодорож', 'кран портальн'], ['excavation', 'backfill', 'soil_haulage', 'formwork', 'reinforcement', 'concreting', 'waterproofing']),
            'walls' => $this->profile($scope, $action, $system, ['стен', 'кладк'], ['блок', 'кирпич', 'перегород'], ['08'], ['01', '03', '05', '09', '27', '28'], ['землян', 'шпунт', 'взрыв', 'бурен'], ['masonry']),
            'slabs' => $this->profile($scope, $action, $system, ['перекрыт', 'бетон'], ['опалуб', 'арматур', 'плит'], ['06', '07'], ['01', '03', '05', '09', '27', '28'], ['землян', 'шпунт', 'взрыв'], ['formwork', 'reinforcement', 'concreting']),
            'stairs' => $this->profile($scope, $action, $system, ['лестниц'], ['марш', 'площадк', 'огражд', 'бетон'], ['06', '07', '08', '10'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'шпунт', 'взрыв', 'бурен', 'кабел', 'труб', 'кран портальн'], ['general_work']),
            'roof' => $this->profile($scope, $action, $system, ['кровл'], ['утепл', 'изоляц', 'покрыт', 'водосток'], ['10', '12', '26'], ['01', '02', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'водопроводн арматур', 'шпунт', 'взрыв', 'бурен'], ['insulation', 'waterproofing']),
            'facade' => $this->profile($scope, $action, $system, ['фасад'], ['штукатур', 'утепл', 'облицов', 'окраск'], ['15', '26'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'шпунт', 'взрыв', 'водопроводн арматур'], ['plastering', 'insulation']),
            'openings' => $this->profile($scope, $action, $system, ['окн', 'двер'], ['проем', 'блок', 'ворот'], ['10', '15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'шпунт', 'взрыв'], ['window_installation']),
            'finishing' => $this->profile($scope, $action, $system, ['отделк'], ['штукатур', 'окраск', 'плитк', 'облицов', 'стяжк', 'покрыт', 'плинтус', 'потол'], ['15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'шпунт', 'взрыв'], ['plastering', 'painting', 'tiling', 'floor_covering', 'ceiling_finishing', 'baseboard_installation']),
            'temporary' => $this->profile($scope, $action, $system, ['временн', 'огражд'], ['забор', 'стройплощад'], ['09'], ['01', '03', '05', '27', '28'], ['железнодорож', 'земляное полотн', 'взрыв', 'бурен'], ['fence_installation']),
            'site' => $this->profile($scope, $action, $system, ['площадк'], ['благоустрой', 'планиров', 'дорог', 'грунт'], ['01', '27'], [], ['железнодорож'], ['planning', 'excavation', 'backfill', 'soil_haulage']),
            default => $this->profile($scope, $action, $system, [], [], [], [], [], []),
        };
    }

    private function specificProfile(string $scope, ?string $action, ?string $system): NormativeSearchProfileData
    {
        return match ($action) {
            'excavation' => $this->profile($scope, $action, $system, ['грунт', 'котлован', 'транше'], ['разработк', 'выемк'], ['01'], [], ['железнодорож'], ['excavation']),
            'backfill' => $this->profile($scope, $action, $system, ['засып', 'грунт'], ['уплотн'], ['01'], [], ['железнодорож'], ['backfill']),
            'soil_haulage' => $this->profile($scope, $action, $system, ['грунт', 'перевоз'], ['вывоз', 'транспортир'], ['01'], [], ['железнодорож'], ['soil_haulage']),
            'concreting' => $this->profile($scope, $action, $system, ['бетон', 'монолит'], ['укладк', 'фундамент'], ['01', '06'], [], [], ['concreting']),
            'reinforcement' => $this->profile($scope, $action, $system, ['арматур', 'армиров'], ['каркас', 'сетк'], ['01', '06'], [], [], ['reinforcement']),
            'formwork' => $this->profile($scope, $action, $system, ['опалуб'], ['устройств', 'демонтаж'], ['01', '06'], [], [], ['formwork']),
            'masonry' => $this->profile($scope, $action, $system, ['кладк', 'стен'], ['блок', 'газобетон', 'кирпич'], ['08'], ['01', '03', '05', '09', '27', '28'], ['шпунт', 'землян'], ['masonry']),
            'insulation' => $this->profile($scope, $action, $system, ['утепл', 'изоляц'], ['минераловат', 'теплоизоляц'], ['12', '26'], ['01', '02', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'водопроводн арматур'], ['insulation']),
            'waterproofing' => $this->profile($scope, $action, $system, ['гидроизоляц', 'изоляц'], ['рулон', 'мембран', 'мастик'], $scope === 'roof' ? ['12', '26'] : ($scope === 'finishing' ? ['11', '15'] : ['08', '11', '12']), ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян'], ['waterproofing']),
            'plastering' => $this->profile($scope, $action, $system, ['штукатур'], ['фасад', 'отделк'], ['15', '26'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'взрыв'], ['plastering']),
            'painting' => $this->profile($scope, $action, $system, ['окраск'], ['покраск', 'стен', 'поверхност', 'отделк'], ['15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'взрыв', 'шпунт'], ['painting']),
            'tiling' => $this->profile($scope, $action, $system, ['плитк'], ['облицов', 'керамическ', 'стен', 'мокр'], ['15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян', 'взрыв', 'шпунт'], ['tiling']),
            'floor_covering' => $this->profile($scope, $action, $system, ['покрыт'], ['пол', 'линолеум', 'ламинат', 'паркет', 'устройство'], ['11'], ['01', '03', '05', '08', '09', '15', '16', '18', '20', '27', '28'], ['землян', 'взрыв', 'шпунт', 'кабел', 'электр'], ['floor_covering']),
            'floor_preparation' => $this->profile($scope, $action, $system, ['пол', 'основан'], ['стяжк', 'подстилающ', 'подготов'], ['11'], ['01', '03', '05', '08', '09', '15', '16', '18', '20', '27', '28'], ['землян', 'взрыв', 'шпунт', 'кабел', 'электр'], ['floor_preparation']),
            'ceiling_finishing' => $this->profile($scope, $action, $system, ['потол'], ['подвесн', 'облицов', 'отделк', 'плит'], ['15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['котел', 'пароперегрев', 'землян', 'взрыв', 'шпунт'], ['ceiling_finishing']),
            'baseboard_installation' => $this->profile($scope, $action, $system, ['плинтус'], ['устройств', 'монтаж', 'галтел', 'пол'], ['11'], ['01', '03', '05', '08', '09', '15', '16', '18', '20', '27', '28'], ['землян', 'взрыв', 'шпунт', 'электротехническ', 'провод'], ['baseboard_installation']),
            'cable_installation' => $this->profile($scope, $action, $system, ['кабел', 'проклад'], ['линий', 'лотк', 'канал'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян', 'кран портальн'], ['cable_installation']),
            'cable_tray_installation' => $this->profile($scope, $action, $system, ['лотк'], ['монтаж', 'установк', 'конструкц'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян', 'кран портальн'], ['cable_tray_installation']),
            'grounding_installation' => $this->profile($scope, $action, $system, ['заземл'], ['электрод', 'контур', 'проводник'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян', 'кран портальн'], ['grounding_installation']),
            'socket_installation' => $this->profile($scope, $action, $system, ['розет', 'выключател'], ['точк', 'установк'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян'], ['socket_installation']),
            'electrical_panel_installation' => $this->profile($scope, $action, $system, ['щит'], ['щиток', 'распределительн', 'квартирн'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян'], ['electrical_panel_installation']),
            'lighting_fixture_installation' => $this->profile($scope, $action, $system, ['светильн'], ['освещ', 'люстр', 'потолочн'], ['08'], ['01', '03', '05', '07', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['землян', 'кабельн муфт'], ['lighting_fixture_installation']),
            'pipe_layout' => $this->profile($scope, $action, $system, ['труб', 'проклад'], ['разводк', 'магистрал'], in_array($system, ['water_supply', 'sewerage'], true) ? ['16'] : ['16', '18'], ['01', '03', '05', '07', '09', '10', '12', '15', '20', '27', '28'], ['землян', 'шпунт'], ['pipe_layout']),
            'sanitary_fixture_installation' => $this->profile($scope, $action, $system, ['санитарно-техническ', 'прибор'], ['сантехническ', 'унитаз', 'раковин', 'ванн', 'душев'], ['17'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '16', '18', '20', '27', '28'], ['манометр', 'датчик давлен'], ['sanitary_fixture_installation']),
            'sewer_revision_installation' => $this->profile($scope, $action, $system, ['ревиз'], ['прочистк', 'канализац'], ['16'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '17', '18', '20', '27', '28'], ['врезк'], ['sewer_revision_installation']),
            'sewer_riser_installation' => $this->profile($scope, $action, $system, ['стояк'], ['канализац', 'трубопровод'], ['16'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '17', '18', '20', '27', '28'], ['врезк'], ['sewer_riser_installation']),
            'sewer_outlet_installation' => $this->profile($scope, $action, $system, ['выпуск'], ['канализац', 'трубопровод'], ['16'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '17', '18', '20', '27', '28'], ['врезк'], ['sewer_outlet_installation']),
            'heating_equipment' => $this->profile($scope, $action, $system, ['отопл', 'оборуд'], ['теплов', 'узел', 'завес', 'радиатор', 'котел'], ['18', '20'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '16', '27', '28'], ['кран портальн', 'землян'], ['heating_equipment']),
            'electric_boiler_installation_analog' => $this->profile($scope, $action, $system, ['оборуд'], ['монтаж', 'установк', 'масса'], ['37'], ['18', '20'], ['водонагревател', 'твердотопливн', 'воздушно-теплов'], ['electric_boiler_installation_analog']),
            'heating_emitter_installation' => $this->profile($scope, $action, $system, ['радиатор'], ['отопительн', 'прибор', 'конвектор'], ['18'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '16', '20', '27', '28'], ['котел', 'котл', 'тепловой узел', 'завес'], ['heating_emitter_installation']),
            'ventilation_installation' => $this->profile($scope, $action, $system, ['вентиляц', 'воздуховод'], ['канал', 'решетк', 'установк'], ['20'], ['01', '03', '05', '07', '08', '09', '10', '12', '15', '16', '18', '27', '28'], ['землян'], ['ventilation_installation']),
            'window_installation' => $this->profile($scope, $action, $system, ['окн', 'двер', 'ворот'], ['блок', 'проем', 'установк'], ['10', '15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян'], ['window_installation']),
            'door_installation' => $this->profile($scope, $action, $system, ['двер'], ['блок', 'проем', 'установк'], ['10', '15'], ['01', '03', '05', '09', '16', '18', '20', '27', '28'], ['землян'], ['door_installation']),
            'fence_installation' => $this->profile($scope, $action, $system, ['огражд', 'забор'], ['временн', 'площадк'], ['09'], ['01', '03', '05', '27', '28'], ['железнодорож', 'земляное полотн'], ['fence_installation']),
            default => $this->profile($scope, $action, $system, [], [], [], [], [], []),
        };
    }

    /**
     * @param  array<int, string>  $requiredTerms
     * @param  array<int, string>  $synonymTerms
     * @param  array<int, string>  $allowedSectionPrefixes
     * @param  array<int, string>  $forbiddenSectionPrefixes
     * @param  array<int, string>  $forbiddenDomainTerms
     * @param  array<int, string>  $allowedAnalogActions
     */
    private function profile(
        string $scope,
        ?string $action,
        ?string $system,
        array $requiredTerms,
        array $synonymTerms,
        array $allowedSectionPrefixes,
        array $forbiddenSectionPrefixes,
        array $forbiddenDomainTerms,
        array $allowedAnalogActions
    ): NormativeSearchProfileData {
        return new NormativeSearchProfileData(
            scope: $scope,
            action: $action,
            system: $system,
            requiredTerms: $this->normalizeList($requiredTerms),
            synonymTerms: $this->normalizeList($synonymTerms),
            allowedSectionPrefixes: $this->normalizeList($allowedSectionPrefixes),
            forbiddenSectionPrefixes: $this->normalizeList($forbiddenSectionPrefixes),
            forbiddenDomainTerms: $this->normalizeList($forbiddenDomainTerms),
            allowedAnalogActions: $this->normalizeList($allowedAnalogActions),
        );
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalizeList(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $value): string => $this->normalize($value), $values),
            static fn (string $value): bool => $value !== ''
        )));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
