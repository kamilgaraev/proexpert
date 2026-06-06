<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignFileFormatEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;

final class DesignNormativeCatalog
{
    public const PROFILE_PD_NON_LINEAR = 'rf_pd_pp_87_non_linear_2026';
    public const PROFILE_PD_LINEAR = 'rf_pd_pp_87_linear_2026';
    public const PROFILE_RD = 'rf_rd_gost_21_101_2026';

    public static function sources(): array
    {
        return [
            [
                'code' => 'rf_pp_87_2008_rev_2025',
                'title' => 'Постановление Правительства РФ от 16.02.2008 N 87',
                'version' => 'ред. 21.10.2025',
                'effective_from' => '2008-02-16',
                'source_url' => 'https://government.ru/docs/all/63014/',
                'metadata' => [
                    'scope' => 'Состав разделов проектной документации и требования к содержанию.',
                ],
            ],
            [
                'code' => 'gost_r_21_101_2026',
                'title' => 'ГОСТ Р 21.101-2026 СПДС. Основные требования к проектной и рабочей документации',
                'version' => '2026',
                'effective_from' => '2026-04-01',
                'source_url' => 'https://protect.gost.ru/gost/details/17bc12e8-6579-4145-b141-56855e772e7f',
                'metadata' => [
                    'replaces' => 'ГОСТ Р 21.101-2020',
                ],
            ],
            [
                'code' => 'rf_pp_614_2024',
                'title' => 'Постановление Правительства РФ от 17.05.2024 N 614',
                'version' => '2024',
                'effective_from' => '2024-05-17',
                'source_url' => 'https://www.minstroyrf.gov.ru/docs/417181/',
                'metadata' => [
                    'scope' => 'Правила формирования и ведения информационной модели объекта капитального строительства.',
                ],
            ],
        ];
    }

    public static function templates(): array
    {
        return array_merge(
            self::pdNonLinearTemplates(DesignObjectTypeEnum::NON_LINEAR_PRODUCTION->value),
            self::pdNonLinearTemplates(DesignObjectTypeEnum::NON_LINEAR_NON_PRODUCTION->value),
            self::pdLinearTemplates(),
            self::rdTemplates(DesignObjectTypeEnum::NON_LINEAR_PRODUCTION->value),
            self::rdTemplates(DesignObjectTypeEnum::NON_LINEAR_NON_PRODUCTION->value),
            self::rdTemplates(DesignObjectTypeEnum::LINEAR->value)
        );
    }

    public static function profileFor(string $projectStage, string $objectType): string
    {
        if ($projectStage === DesignProjectStageEnum::PD->value && $objectType === DesignObjectTypeEnum::LINEAR->value) {
            return self::PROFILE_PD_LINEAR;
        }

        if ($projectStage === DesignProjectStageEnum::PD->value) {
            return self::PROFILE_PD_NON_LINEAR;
        }

        return self::PROFILE_RD;
    }

    private static function pdNonLinearTemplates(string $objectType): array
    {
        $sections = [
            ['PZ', 'Раздел 1. Пояснительная записка', 'PZ', 'Пояснительная записка', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX]],
            ['PZU', 'Раздел 2. Схема планировочной организации земельного участка', 'PZU', 'Схема планировочной организации земельного участка', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['AR', 'Раздел 3. Архитектурные решения', 'AR', 'Архитектурные решения', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['KR', 'Раздел 4. Конструктивные и объемно-планировочные решения', 'KR', 'Конструктивные решения', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['IOS', 'Раздел 5. Сведения об инженерном оборудовании и сетях', 'IOS', 'Инженерное обеспечение', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['POS', 'Раздел 6. Проект организации строительства', 'POS', 'Проект организации строительства', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::DWG]],
            ['POD', 'Раздел 7. Проект организации работ по сносу или демонтажу', 'POD', 'Проект организации работ по сносу или демонтажу', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX], false, false],
            ['OOS', 'Раздел 8. Перечень мероприятий по охране окружающей среды', 'OOS', 'Мероприятия по охране окружающей среды', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX]],
            ['PB', 'Раздел 9. Мероприятия по обеспечению пожарной безопасности', 'PB', 'Мероприятия по пожарной безопасности', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::DWG]],
            ['ODI', 'Раздел 10. Доступ инвалидов к объекту капитального строительства', 'ODI', 'Мероприятия по обеспечению доступа инвалидов', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::DWG]],
            ['SM', 'Раздел 11. Смета на строительство', 'SM', 'Сметная документация', DesignArtifactTypeEnum::ESTIMATE, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::XML]],
            ['OTHER', 'Раздел 12. Иная документация', 'OTHER', 'Иная документация', DesignArtifactTypeEnum::OTHER, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::ZIP], false, false],
        ];

        return self::templatesFromSections(
            self::PROFILE_PD_NON_LINEAR,
            DesignProjectStageEnum::PD->value,
            $objectType,
            'rf_pp_87_2008_rev_2025',
            'ПП РФ N 87',
            $sections
        );
    }

    private static function pdLinearTemplates(): array
    {
        $sections = [
            ['PZ', 'Раздел 1. Пояснительная записка', 'PZ', 'Пояснительная записка', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX]],
            ['POL', 'Раздел 2. Проект полосы отвода', 'POL', 'Проект полосы отвода', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['TKR', 'Раздел 3. Технологические и конструктивные решения линейного объекта', 'TKR', 'Технологические и конструктивные решения', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['ILO', 'Раздел 4. Искусственные сооружения', 'ILO', 'Искусственные сооружения', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true, false],
            ['POS', 'Раздел 5. Проект организации строительства', 'POS', 'Проект организации строительства', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::DWG]],
            ['POD', 'Раздел 6. Проект организации работ по сносу или демонтажу', 'POD', 'Проект организации работ по сносу или демонтажу', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX], false, false],
            ['OOS', 'Раздел 7. Мероприятия по охране окружающей среды', 'OOS', 'Мероприятия по охране окружающей среды', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX]],
            ['PB', 'Раздел 8. Мероприятия по обеспечению пожарной безопасности', 'PB', 'Мероприятия по пожарной безопасности', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::DWG]],
            ['SM', 'Раздел 9. Смета на строительство', 'SM', 'Сметная документация', DesignArtifactTypeEnum::ESTIMATE, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::XML]],
            ['OTHER', 'Раздел 10. Иная документация', 'OTHER', 'Иная документация', DesignArtifactTypeEnum::OTHER, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DOCX, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::ZIP], false, false],
        ];

        return self::templatesFromSections(
            self::PROFILE_PD_LINEAR,
            DesignProjectStageEnum::PD->value,
            DesignObjectTypeEnum::LINEAR->value,
            'rf_pp_87_2008_rev_2025',
            'ПП РФ N 87',
            $sections
        );
    }

    private static function rdTemplates(string $objectType): array
    {
        $sections = [
            ['GD', 'Общие данные', 'GD', 'Общие данные по рабочим чертежам', DesignArtifactTypeEnum::TEXT_DOCUMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DOCX], true],
            ['DRAWINGS', 'Основные комплекты рабочих чертежей', 'DRAWINGS', 'Основной комплект рабочих чертежей', DesignArtifactTypeEnum::DRAWING_SET, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::DWG, DesignFileFormatEnum::DXF], true],
            ['SPEC', 'Спецификации оборудования, изделий и материалов', 'SPEC', 'Спецификация оборудования, изделий и материалов', DesignArtifactTypeEnum::SPECIFICATION, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::XML]],
            ['VOLUME_LIST', 'Ведомости и перечни документов', 'VOLUME_LIST', 'Ведомость ссылочных и прилагаемых документов', DesignArtifactTypeEnum::STATEMENT, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::DOCX]],
            ['CHANGES', 'Ведомость изменений', 'CHANGES', 'Ведомость изменений рабочей документации', DesignArtifactTypeEnum::REGISTER, [DesignFileFormatEnum::PDF, DesignFileFormatEnum::XLSX, DesignFileFormatEnum::DOCX], false, false],
            ['BIM', 'Информационная модель', 'IFC', 'Информационная модель в формате IFC', DesignArtifactTypeEnum::MODEL, [DesignFileFormatEnum::IFC], false, false],
        ];

        return self::templatesFromSections(
            self::PROFILE_RD,
            DesignProjectStageEnum::RD->value,
            $objectType,
            'gost_r_21_101_2026',
            'ГОСТ Р 21.101-2026',
            $sections
        );
    }

    private static function templatesFromSections(
        string $profileCode,
        string $projectStage,
        string $objectType,
        string $sourceCode,
        string $normativeReference,
        array $sections
    ): array {
        return array_values(array_map(
            static function (array $section, int $index) use ($profileCode, $projectStage, $objectType, $sourceCode, $normativeReference): array {
                $allowedFormats = array_map(
                    static fn (DesignFileFormatEnum $format): string => $format->value,
                    $section[5]
                );

                return [
                    'source_code' => $sourceCode,
                    'profile_code' => $profileCode,
                    'project_stage' => $projectStage,
                    'object_type' => $objectType,
                    'section_code' => $section[0],
                    'section_title' => $section[1],
                    'document_code' => $section[2],
                    'document_title' => $section[3],
                    'artifact_type' => $section[4]->value,
                    'required' => $section[7] ?? true,
                    'sort_order' => ($index + 1) * 10,
                    'allowed_formats' => $allowedFormats,
                    'sheet_registry_required' => $section[6] ?? false,
                    'normative_reference' => $normativeReference,
                    'metadata' => [
                        'catalog_version' => '2026-06-06',
                    ],
                ];
            },
            $sections,
            array_keys($sections)
        ));
    }
}
