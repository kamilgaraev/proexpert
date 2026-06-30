<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use PHPUnit\Framework\TestCase;

final class KnowledgeArticleResourceTest extends TestCase
{
    public function test_surfaces_filter_uses_eloquent_builder_type_hint(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Filament/Resources/KnowledgeArticleResource.php'
        );

        self::assertStringContainsString(
            'use Illuminate\Database\Eloquent\Builder as EloquentBuilder;',
            $source
        );
        self::assertStringContainsString(
            '->query(fn (EloquentBuilder $query, array $data): EloquentBuilder =>',
            $source
        );
        self::assertStringNotContainsString(
            '->query(fn (Builder $query, array $data): Builder =>',
            $source
        );
    }
}
