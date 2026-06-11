<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\BusinessModules\Features\Crm\Services\CrmTextNormalizer;
use PHPUnit\Framework\TestCase;

final class CrmTextNormalizerTest extends TestCase
{
    public function test_it_normalizes_contact_values_for_deduplication(): void
    {
        $normalizer = new CrmTextNormalizer();

        $this->assertSame('user@example.com', $normalizer->email(' User@Example.COM '));
        $this->assertSame('79991234567', $normalizer->phone('+7 (999) 123-45-67'));
        $this->assertSame('79991234567', $normalizer->phone('8 999 123 45 67'));
        $this->assertSame('example.ru/path', $normalizer->domain('https://www.example.ru/path/'));
        $this->assertSame('7707083893', $normalizer->inn('7707 083 893'));
    }
}
