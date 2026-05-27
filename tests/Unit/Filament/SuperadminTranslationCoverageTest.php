<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class SuperadminTranslationCoverageTest extends TestCase
{
    public function test_filament_views_do_not_expose_editor_english_labels(): void
    {
        $sources = $this->readFiles([
            resource_path('views/filament'),
            app_path('Filament'),
        ]);

        $rawLabelPattern = '/->(?:label|heading|modalHeading|modalDescription|description)\(\s*[\'"](?:Preview|Autosave|Callout|Embed|CTA|System|Content)[\'"]\s*\)/';
        $visibleFragments = [
            '>Preview<',
            '>Autosave<',
            '>Callout<',
            '>Embed<',
            '>CTA<',
            '>Workspace<',
            '>Reading time<',
            'Shortcut:',
            'Открыть preview',
        ];

        foreach ($sources as $path => $source) {
            foreach ($visibleFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $source, $path);
            }

            $this->assertDoesNotMatchRegularExpression($rawLabelPattern, $source, $path);
        }
    }

    public function test_filament_sources_do_not_contain_known_mojibake_markers(): void
    {
        foreach ($this->readFiles([app_path('Filament'), resource_path('views/filament'), lang_path('ru')]) as $path => $source) {
            foreach (['Р С’', 'Р Сџ', 'Р С›', 'РІвЂљР…'] as $marker) {
                $this->assertStringNotContainsString($marker, $source, $path);
            }
        }
    }

    public function test_filament_widgets_use_project_translation_helper(): void
    {
        foreach ($this->readFiles([app_path('Filament/Widgets')]) as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/__\(\s*[\'"]widgets\./', $source, $path);
        }
    }

    /**
     * @param list<string> $directories
     *
     * @return array<string, string>
     */
    private function readFiles(array $directories): array
    {
        $sources = [];

        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! in_array($file->getExtension(), ['php', 'blade'], true)) {
                    continue;
                }

                $path = $file->getPathname();
                $source = file_get_contents($path);

                $this->assertIsString($source, $path);

                $sources[$path] = $source;
            }
        }

        return $sources;
    }
}
