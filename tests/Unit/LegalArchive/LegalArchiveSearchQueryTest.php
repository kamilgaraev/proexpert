<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\LegalArchiveSearchQuery;
use PHPUnit\Framework\TestCase;

final class LegalArchiveSearchQueryTest extends TestCase
{
    public function test_search_query_sanitizes_human_input(): void
    {
        $this->assertSame('договор акт подрядчик', LegalArchiveSearchQuery::sanitize("  договор   акт\nподрядчик  "));
        $this->assertNull(LegalArchiveSearchQuery::sanitize('   '));
    }

    public function test_postgres_expression_uses_russian_full_text_search_columns(): void
    {
        $expression = LegalArchiveSearchQuery::postgresExpression();

        $this->assertStringContainsString("to_tsvector('russian'", $expression);
        $this->assertStringContainsString("plainto_tsquery('russian'", $expression);
        $this->assertStringContainsString('title', $expression);
        $this->assertStringContainsString('document_number', $expression);
        $this->assertStringContainsString('counterparty_name', $expression);
        $this->assertStringContainsString('description', $expression);
    }
}
