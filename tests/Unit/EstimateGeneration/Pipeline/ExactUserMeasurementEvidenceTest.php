<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExactUserMeasurementEvidenceTest extends TestCase
{
    public function test_exact_measurements_keep_distinct_values_and_fingerprints(): void
    {
        $first = $this->measured('999999999999.0002');
        $second = $this->measured('999999999999.0003');
        self::assertSame('999999999999.0002', $first->value['quantity']);
        self::assertSame('999999999999.0003', $second->value['quantity']);
        self::assertNotSame($first->fingerprint(), $second->fingerprint());
        foreach ([0, 1, 40.1234, '0', '40.1234', '1000000000000'] as $number) {
            self::assertSame($number, $this->measured($number)->value['quantity']);
        }
    }

    public function test_decimal_contract_does_not_weaken_document_trust_prices_or_bounds(): void
    {
        foreach (['-1', '-0', '1e2', '01', '1.00001', '1000000000000.0001', INF, NAN, true, []] as $number) {
            try {
                $this->measured($number);
                self::fail('Invalid measurement accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([[EvidenceSourceType::Document, EvidenceProducer::UserInputNormalizer],
            [EvidenceSourceType::UserInput, EvidenceProducer::PdfGeometry]] as [$source, $producer]) {
            try {
                $this->measured('40.1234', $source, $producer);
                self::fail('Exact user measurement was relabelled as trusted document geometry.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(40.1234, $this->measured(40.1234, EvidenceSourceType::Document, EvidenceProducer::PdfGeometry)->value['quantity']);
        $this->expectException(InvalidArgumentException::class);
        new EvidenceData(10, 20, 30, EvidenceType::Price, EvidenceSourceType::PriceSnapshot, 'price:regional:1',
            'sha256:'.str_repeat('a', 64), ['item_key' => 'item:1'], ['amount' => '40.1234', 'currency' => 'RUB', 'price_version' => 'price:1'],
            0.0, EvidenceProducer::PriceResolver->value, 'sha256:'.str_repeat('b', 64));
    }

    private function measured(mixed $value, EvidenceSourceType $source = EvidenceSourceType::UserInput,
        EvidenceProducer $producer = EvidenceProducer::UserInputNormalizer): EvidenceData
    {
        return new EvidenceData(10, 20, 30, EvidenceType::Measured, $source,
            $source === EvidenceSourceType::Document ? 'document:1' : 'input:30', 'sha256:'.str_repeat('a', 64),
            ['source_key' => 'source:1'], ['quantity' => $value, 'unit' => 'm2', 'method' => 'user_confirmed'],
            0.0, $producer->value, 'sha256:'.str_repeat('b', 64));
    }
}
