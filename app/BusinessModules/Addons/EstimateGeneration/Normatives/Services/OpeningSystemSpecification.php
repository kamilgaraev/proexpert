<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class OpeningSystemSpecification
{
    public static function searchText(?string $system): ?string
    {
        return match ($system) {
            'steel_glazed_window' => 'Установка стальных остеклённых оконных блоков',
            'pvc_glazed_window' => 'Установка оконных блоков из поливинилхлоридных профилей',
            'timber_hollow_core_door' => 'Установка внутренних деревянных дверных блоков с пустотелыми полотнами',
            'meranti_external_door' => 'Установка наружных деревянных дверных блоков из меранти',
            default => null,
        };
    }

    public static function compatible(string $system, string $candidate): bool
    {
        $candidate = mb_strtolower(str_replace('ё', 'е', $candidate));
        $has = static fn (string $pattern): bool => preg_match($pattern.'u', $candidate) === 1;
        $window = $has('/окон|окна|окно/');
        $door = $has('/двер/');
        $pvc = $has('/пвх|поливинилхлорид|пластиков/');
        $steel = $has('/сталь|стальн|сталью|металлическ/');
        $wood = $has('/деревян|древесин|меранти/');
        $aluminium = $has('/алюмини/');

        return match ($system) {
            'steel_glazed_window' => $window && ! $door && $steel && ! $pvc && ! $wood && ! $aluminium,
            'pvc_glazed_window' => $window && ! $door && $pvc && ! $wood && ! $aluminium && ! $steel,
            'timber_hollow_core_door' => $door && ! $window && $wood && ! $pvc && ! $steel && ! $aluminium
                && $has('/пустотел|сотов/')
                && ! $has('/меранти|наружн|массив|дубов/'),
            'meranti_external_door' => $door && ! $window && $wood && ! $pvc && ! $steel && ! $aluminium
                && $has('/меранти/') && $has('/наружн/')
                && ! $has('/внутренн|пустотел|сотов|дубов|соснов/'),
            default => false,
        };
    }
}
