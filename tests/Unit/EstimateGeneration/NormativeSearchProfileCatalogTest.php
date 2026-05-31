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

    public function test_wall_masonry_profile_blocks_cross_domain_sections(): void
    {
        $profile = (new NormativeSearchProfileCatalog())->forIntent('walls', 'masonry', null);

        $this->assertSame(['08'], $profile->allowedSectionPrefixes);
        $this->assertContains('кладк', $profile->requiredTerms);
        $this->assertContains('шпунт', $profile->forbiddenDomainTerms);
    }
}
