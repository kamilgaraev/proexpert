<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RagLexicalQueryTermsTest extends TestCase
{
    public function test_short_identifiers_preserve_the_full_literal_code_and_number(): void
    {
        $terms = $this->terms('НДС КС-2 №3 AB_1% AB\\2%');

        foreach (['ндс', 'кс-2', '№3', 'ab_1%', 'ab\\2%'] as $literal) {
            self::assertContains($literal, $terms);
        }
        self::assertNotContains('ab', $terms);
        self::assertNotContains('кс', $terms);
    }

    public function test_short_terms_keep_existing_stop_words_excluded(): void
    {
        foreach (['по', 'из', 'для', 'или', 'ПО'] as $query) {
            self::assertSame([], $this->terms($query));
        }
        self::assertNotContains('по', $this->terms('ПО договору'));
    }

    public function test_query_term_count_and_single_term_length_are_bounded(): void
    {
        $query = implode(' ', array_map(static fn (int $index): string => 'ID-'.$index, range(1, 100)));
        self::assertCount(24, $this->terms($query));
        self::assertSame([], $this->terms(str_repeat('A', 201).'1'));
    }

    private function terms(string $query): array
    {
        $class = new ReflectionClass(RagRetriever::class);

        return $class->getMethod('lexicalTerms')->invoke($class->newInstanceWithoutConstructor(), $query);
    }
}
