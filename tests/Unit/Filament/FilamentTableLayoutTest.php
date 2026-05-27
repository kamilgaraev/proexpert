<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use PHPUnit\Framework\TestCase;

class FilamentTableLayoutTest extends TestCase
{
    public function test_article_table_keeps_primary_cells_readable(): void
    {
        $source = $this->source('app/Filament/Resources/BlogArticleResource/Schemas/BlogArticleTable.php');

        $this->assertMatchesRegularExpression(
            "/TextColumn::make\\('title'\\).*?->width\\('24rem'\\).*?->lineClamp\\(3\\)/s",
            $source,
        );
        $this->assertStringContainsString("->dateTime('d.m.Y H:i')", $source);
        $this->assertMatchesRegularExpression(
            "/TextColumn::make\\('systemAuthor\\.name'\\).*?->toggleable\\(isToggledHiddenByDefault: true\\)/s",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/TextColumn::make\\('last_autosaved_at'\\).*?->toggleable\\(isToggledHiddenByDefault: true\\)/s",
            $source,
        );
    }

    public function test_article_table_uses_compact_record_actions(): void
    {
        $source = $this->source('app/Filament/Resources/BlogArticleResource/Schemas/BlogArticleTable.php');

        $this->assertStringContainsString('use Filament\\Actions\\ActionGroup;', $source);
        $this->assertStringContainsString('->recordActions([', $source);
        $this->assertStringContainsString('->iconButton()', $source);
        $this->assertStringContainsString('ActionGroup::make([', $source);
    }

    public function test_dense_system_admin_tables_group_multiple_record_actions(): void
    {
        foreach ([
            'app/Filament/Resources/BlogCommentResource.php',
            'app/Filament/Resources/BlogMediaAssetResource.php',
            'app/Filament/Resources/Monitoring/ApplicationErrorResource.php',
            'app/Filament/Resources/OrganizationResource.php',
            'app/Filament/Resources/SupportRequestResource.php',
            'app/Filament/Resources/UserResource.php',
        ] as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('use Filament\\Actions\\ActionGroup;', $source, $path);
            $this->assertStringContainsString('ActionGroup::make([', $source, $path);
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(__DIR__.'/../../../'.$path);
    }
}
