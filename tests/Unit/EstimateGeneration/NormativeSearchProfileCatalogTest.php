<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use PHPUnit\Framework\TestCase;

final class NormativeSearchProfileCatalogTest extends TestCase
{
    public function test_roof_insulation_profile_limits_search_to_roof_and_insulation_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('roof', 'insulation', null);

        $this->assertContains('12', $profile->allowedSectionPrefixes);
        $this->assertContains('26', $profile->allowedSectionPrefixes);
        $this->assertContains('утепл', $profile->requiredTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }

    public function test_heating_pipe_profile_allows_heating_and_plumbing_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('engineering', 'pipe_layout', 'heating');

        $this->assertContains('16', $profile->allowedSectionPrefixes);
        $this->assertContains('18', $profile->allowedSectionPrefixes);
        $this->assertContains('труб', $profile->requiredTerms);
        $this->assertContains('pipe_layout', $profile->allowedAnalogActions);
    }

    public function test_heating_equipment_profile_does_not_require_pipe_terms(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('engineering', 'heating_equipment', 'heating');

        $this->assertContains('18', $profile->allowedSectionPrefixes);
        $this->assertContains('20', $profile->allowedSectionPrefixes);
        $this->assertNotContains('16', $profile->allowedSectionPrefixes);
        $this->assertNotContains('труб', $profile->requiredTerms);
        $this->assertContains('оборуд', $profile->requiredTerms);
    }

    public function test_wall_masonry_profile_blocks_cross_domain_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('walls', 'masonry', null);

        $this->assertSame(['08'], $profile->allowedSectionPrefixes);
        $this->assertContains('кладк', $profile->requiredTerms);
        $this->assertContains('шпунт', $profile->forbiddenDomainTerms);
    }

    public function test_stairs_profile_limits_search_to_building_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('stairs', 'general_work', null);

        $this->assertContains('06', $profile->allowedSectionPrefixes);
        $this->assertContains('07', $profile->allowedSectionPrefixes);
        $this->assertContains('08', $profile->allowedSectionPrefixes);
        $this->assertContains('лестниц', $profile->requiredTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }

    public function test_baseboard_profile_limits_search_to_finishing_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('finishing', 'baseboard_installation', null);

        $this->assertSame(['15'], $profile->allowedSectionPrefixes);
        $this->assertContains('плинтус', $profile->requiredTerms);
        $this->assertContains('монтаж', $profile->synonymTerms);
        $this->assertContains('землян', $profile->forbiddenDomainTerms);
    }
}
