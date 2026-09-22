<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractDocumentRenderer;
use PHPUnit\Framework\TestCase;

final class ContractNumberRenderingTest extends TestCase
{
    public function test_numeric_fields_are_readable_without_rounding_or_changing_literal_text(): void
    {
        $fixtures = [
            ['money', ['amount' => '1500000.50', 'currency' => 'RUB'], "1\u{00A0}500\u{00A0}000,50\u{00A0}₽"],
            ['money', ['amount' => '1234567890123456789.99', 'currency' => 'RUB'], "1\u{00A0}234\u{00A0}567\u{00A0}890\u{00A0}123\u{00A0}456\u{00A0}789,99\u{00A0}₽"],
            ['number', '-1234.567800', "-1\u{00A0}234,5678"],
            ['number', '0', '0'],
            ['percentage', '12.50', "12,5\u{00A0}%"],
            ['text', '500 RUB, номер 234234324', '500 RUB, номер 234234324'],
        ];
        foreach ($fixtures as [$type, $value, $expected]) {
            $id = '11111111-1111-4111-8111-111111111111';
            $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'variable', 'attrs' => ['variableId' => $id]]]]]];
            $definitions = [$id => ['id' => $id, 'version' => 1, 'definition' => ['type' => $type]]];
            $html = (new ContractDocumentRenderer)->render($document, $definitions, [$id => $value]);
            self::assertStringContainsString('<p>'.$expected.'</p>', $html);
        }
    }
}
