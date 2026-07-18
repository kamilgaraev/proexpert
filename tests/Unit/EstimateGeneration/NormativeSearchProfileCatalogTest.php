<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use PHPUnit\Framework\TestCase;

final class NormativeSearchProfileCatalogTest extends TestCase
{
    public function test_roof_insulation_profile_limits_search_to_roof_and_insulation_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('roof', 'insulation', null);

        $this->assertContains('12', $profile->allowedSectionPrefixes);
        $this->assertContains('26', $profile->allowedSectionPrefixes);
        $this->assertContains('утепл', $profile->requiredTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }

    public function test_heating_pipe_profile_allows_heating_and_plumbing_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('engineering', 'pipe_layout', 'heating');

        $this->assertContains('16', $profile->allowedSectionPrefixes);
        $this->assertContains('18', $profile->allowedSectionPrefixes);
        $this->assertContains('труб', $profile->requiredTerms);
        $this->assertContains('pipe_layout', $profile->allowedAnalogActions);
    }

    public function test_heating_equipment_profile_does_not_require_pipe_terms(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('engineering', 'heating_equipment', 'heating');

        $this->assertContains('18', $profile->allowedSectionPrefixes);
        $this->assertContains('20', $profile->allowedSectionPrefixes);
        $this->assertNotContains('16', $profile->allowedSectionPrefixes);
        $this->assertNotContains('труб', $profile->requiredTerms);
        $this->assertContains('оборуд', $profile->requiredTerms);
    }

    public function test_wall_masonry_profile_blocks_cross_domain_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('walls', 'masonry', null);

        $this->assertSame(['08'], $profile->allowedSectionPrefixes);
        $this->assertContains('кладк', $profile->requiredTerms);
        $this->assertContains('шпунт', $profile->forbiddenDomainTerms);
    }

    public function test_stairs_profile_limits_search_to_building_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('stairs', 'general_work', null);

        $this->assertContains('06', $profile->allowedSectionPrefixes);
        $this->assertContains('07', $profile->allowedSectionPrefixes);
        $this->assertContains('08', $profile->allowedSectionPrefixes);
        $this->assertContains('лестниц', $profile->requiredTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }

    public function test_baseboard_profile_limits_search_to_finishing_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('finishing', 'baseboard_installation', null);

        $this->assertSame(['11'], $profile->allowedSectionPrefixes);
        $this->assertContains('плинтус', $profile->requiredTerms);
        $this->assertContains('устройств', $profile->synonymTerms);
        $this->assertContains('электротехническ', $profile->forbiddenDomainTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }

    public function test_floor_covering_profile_limits_search_to_floor_section(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('finishing', 'floor_covering', null);

        $this->assertSame(['11'], $profile->allowedSectionPrefixes);
        $this->assertContains('покрыт', $profile->requiredTerms);
        $this->assertContains('линолеум', $profile->synonymTerms);
        $this->assertContains('кабел', $profile->forbiddenDomainTerms);
    }

    public function test_floor_preparation_profile_searches_screeds_and_underlayers(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('finishing', 'floor_preparation', null);

        self::assertSame(['11'], $profile->allowedSectionPrefixes);
        self::assertContains('стяжк', $profile->synonymTerms);
        self::assertSame(['floor_preparation'], $profile->allowedAnalogActions);
    }

    public function test_grounding_profile_is_not_treated_as_cable_installation(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('engineering', 'grounding_installation', 'electrical');

        self::assertSame(['08'], $profile->allowedSectionPrefixes);
        self::assertContains('заземл', $profile->requiredTerms);
        self::assertSame(['grounding_installation'], $profile->allowedAnalogActions);
    }

    public function test_soil_haulage_profile_requires_transport_in_earthwork_section(): void
    {
        $profile = (new NormativeSearchProfileCatalog)->forIntent('foundation', 'soil_haulage', null);

        self::assertSame(['01'], $profile->allowedSectionPrefixes);
        self::assertContains('грунт', $profile->requiredTerms);
        self::assertContains('перевоз', $profile->requiredTerms);
        self::assertContains('soil_haulage', $profile->allowedAnalogActions);
        self::assertNotContains('excavation', $profile->allowedAnalogActions);
    }

    public function test_paint_tile_and_ceiling_profiles_keep_finishing_section(): void
    {
        $catalog = new NormativeSearchProfileCatalog;
        $painting = $catalog->forIntent('finishing', 'painting', null);
        $tiling = $catalog->forIntent('finishing', 'tiling', null);
        $ceiling = $catalog->forIntent('finishing', 'ceiling_finishing', null);

        $this->assertSame(['15'], $painting->allowedSectionPrefixes);
        $this->assertContains('окраск', $painting->requiredTerms);
        $this->assertSame(['15'], $tiling->allowedSectionPrefixes);
        $this->assertContains('плитк', $tiling->requiredTerms);
        $this->assertSame(['15'], $ceiling->allowedSectionPrefixes);
        $this->assertContains('потол', $ceiling->requiredTerms);
        $this->assertContains('пароперегрев', $ceiling->forbiddenDomainTerms);
        $this->assertContains('землян', $ceiling->forbiddenDomainTerms);
    }
}
