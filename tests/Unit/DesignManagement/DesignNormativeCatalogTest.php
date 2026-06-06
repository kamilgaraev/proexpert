<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignFileFormatEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\BusinessModules\Features\DesignManagement\Support\DesignNormativeCatalog;
use Tests\TestCase;

final class DesignNormativeCatalogTest extends TestCase
{
    public function test_domain_enums_cover_rf_project_documentation_scope(): void
    {
        self::assertSame('pd', DesignProjectStageEnum::PD->value);
        self::assertSame('rd', DesignProjectStageEnum::RD->value);
        self::assertSame('linear', DesignObjectTypeEnum::LINEAR->value);
        self::assertSame('drawing_set', DesignArtifactTypeEnum::DRAWING_SET->value);
        self::assertSame('text_document', DesignArtifactTypeEnum::TEXT_DOCUMENT->value);
        self::assertSame('specification', DesignArtifactTypeEnum::SPECIFICATION->value);
        self::assertSame('pdf', DesignFileFormatEnum::PDF->value);
        self::assertSame('dwg', DesignFileFormatEnum::DWG->value);
        self::assertSame('ifc', DesignFileFormatEnum::IFC->value);
    }

    public function test_catalog_uses_current_rf_normative_profiles(): void
    {
        $sources = collect(DesignNormativeCatalog::sources())->keyBy('code');
        $templates = collect(DesignNormativeCatalog::templates());

        self::assertArrayHasKey('gost_r_21_101_2026', $sources);
        self::assertSame('2026-04-01', $sources['gost_r_21_101_2026']['effective_from']);
        self::assertTrue($templates->contains(
            static fn (array $template): bool => $template['profile_code'] === DesignNormativeCatalog::PROFILE_RD
                && $template['section_code'] === 'DRAWINGS'
                && $template['sheet_registry_required'] === true
        ));
        self::assertTrue($templates->contains(
            static fn (array $template): bool => $template['profile_code'] === DesignNormativeCatalog::PROFILE_PD_LINEAR
                && $template['section_code'] === 'POL'
        ));
    }
}
