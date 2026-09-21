<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use InvalidArgumentException;

final readonly class OfficialDocumentDefinition
{
    private const TITLE_KEYS = [
        'official_material_usage_m29' => 'reports.official.official_material_usage_m29',
        'official_hidden_work_act' => 'reports.official.official_hidden_work_act',
        'official_axis_layout_act' => 'reports.official.official_axis_layout_act',
        'official_geodetic_base_acceptance_act' => 'reports.official.official_geodetic_base_acceptance_act',
        'official_responsible_structure_act' => 'reports.official.official_responsible_structure_act',
        'official_engineering_network_section_act' => 'reports.official.official_engineering_network_section_act',
    ];
    public array $sealRequires;

    public function __construct(
        public string $code,
        public string $titleKey,
        public string $rendererVersion,
        public ReportPublicationReadiness $publicationReadiness,
        public string $legalRetentionPolicy,
        array $sealRequires,
    ) {
        if (! isset(self::TITLE_KEYS[$code]) || self::TITLE_KEYS[$code] !== $titleKey) {
            throw new InvalidArgumentException('official_document_identity_invalid');
        }

        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $rendererVersion) !== 1) {
            throw new InvalidArgumentException('official_document_renderer_version_invalid');
        }

        if ($publicationReadiness === ReportPublicationReadiness::DRAFT) {
            throw new InvalidArgumentException('official_document_readiness_invalid');
        }

        if (mb_strlen($legalRetentionPolicy) < 2) {
            throw new InvalidArgumentException('official_document_retention_policy_invalid');
        }

        if (! array_is_list($sealRequires) || count($sealRequires) < 7) {
            throw new InvalidArgumentException('official_document_seal_contract_invalid');
        }

        $uniqueRequirements = [];
        foreach ($sealRequires as $requirement) {
            if (! is_string($requirement)
                || trim($requirement) === ''
                || isset($uniqueRequirements[$requirement])) {
                throw new InvalidArgumentException('official_document_seal_contract_invalid');
            }

            $uniqueRequirements[$requirement] = true;
        }

        $this->sealRequires = $sealRequires;
    }
}
