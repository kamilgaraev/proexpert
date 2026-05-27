<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use PHPUnit\Framework\TestCase;

class FilamentThemeAssetTest extends TestCase
{
    public function test_compiled_theme_keeps_filament_layout_component_rules(): void
    {
        $manifestPath = __DIR__ . '/../../../public/build/manifest.json';

        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $themeFile = $manifest['resources/css/filament/admin/theme.css']['file'] ?? null;

        $this->assertIsString($themeFile);

        $cssPath = __DIR__ . '/../../../public/build/' . $themeFile;

        $this->assertFileExists($cssPath);

        $css = (string) file_get_contents($cssPath);

        $this->assertStringContainsString('.fi-grid:not(.fi-grid-direction-col)', $css);
        $this->assertStringContainsString('grid-template-columns:var(--cols-default)', $css);
        $this->assertStringContainsString('.fi-sc.fi-sc-has-gap', $css);
        $this->assertStringContainsString('.fi-simple-page', $css);
        $this->assertStringContainsString('.fi-sidebar-close-sidebar-btn', $css);
        $this->assertStringContainsString('.fi-color-primary', $css);
        $this->assertStringContainsString('.fi-bg-color-400', $css);
        $this->assertStringContainsString('.hover\:fi-bg-color-300', $css);
        $this->assertStringContainsString('.dark\:fi-bg-color-600', $css);
        $this->assertStringContainsString('.fi-text-color-900', $css);
        $this->assertStringContainsString('.dark\:fi-text-color-950', $css);
    }
}
