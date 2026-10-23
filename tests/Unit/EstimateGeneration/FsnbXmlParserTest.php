<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsnbNormResourceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormResource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FsnbXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FsnbXmlParserTest extends TestCase
{
    public function test_parse_preserves_schema_valid_resource_quantity_through_decimal_cast(): void
    {
        $resource = $this->parseResource('123456789.123456');
        $model = new EstimateNormResource(['quantity' => $resource->quantity]);

        self::assertSame('123456789.123456', $model->quantity);
        self::assertSame('123456789.123456', $resource->quantity);
        self::assertSame('123456789.123456', $resource->toArray()['quantity']);
    }

    #[DataProvider('exactQuantities')]
    public function test_parse_keeps_exact_decimal_input_and_existing_model_scale(
        string $input,
        string $expectedQuantity,
        string $expectedStorageQuantity,
    ): void {
        $resource = $this->parseResource($input);

        self::assertSame($expectedQuantity, $resource->quantity);
        self::assertSame($expectedStorageQuantity, (new EstimateNormResource(['quantity' => $resource->quantity]))->quantity);
        self::assertSame($expectedQuantity, $resource->toArray()['quantity']);
        self::assertSame($input, $resource->rawData['attributes']['Quantity']);
    }

    public static function exactQuantities(): array
    {
        return [
            'historical integer control' => ['118', '118', '118.000000'],
            'smallest stored fraction' => ['0.000001', '0.000001', '0.000001'],
            'fourteen significant digits control' => ['12345678.123456', '12345678.123456', '12345678.123456'],
            'large schema valid coefficient' => ['9007199254.740993', '9007199254.740993', '9007199254.740993'],
            'schema maximum' => ['99999999999999.999999', '99999999999999.999999', '99999999999999.999999'],
            'negative schema maximum' => ['-99999999999999.999999', '-99999999999999.999999', '-99999999999999.999999'],
            'comma and grouped spaces' => ['1 234 567,123456', '1234567.123456', '1234567.123456'],
            'scientific notation' => ['1.234567e2', '1.234567e2', '123.456700'],
            'small scientific notation' => ['1e-6', '1e-6', '0.000001'],
            'explicit plus' => ['+1.25', '+1.25', '1.250000'],
            'zero' => ['0', '0', '0.000000'],
            'negative zero' => ['-0.000000', '-0.000000', '0.000000'],
            'negative value' => ['-1.234567', '-1.234567', '-1.234567'],
            'more than six digits round down at model boundary' => ['0.1234564', '0.1234564', '0.123456'],
            'more than six digits round up at model boundary' => ['0.1234565', '0.1234565', '0.123457'],
            'negative half rounds away from zero at model boundary' => ['-0.1234565', '-0.1234565', '-0.123457'],
        ];
    }

    #[DataProvider('unavailableQuantities')]
    public function test_parse_preserves_null_for_missing_or_malformed_quantity(?string $input): void
    {
        $resource = $this->parseResource($input);

        self::assertNull($resource->quantity);
        self::assertNull($resource->toArray()['quantity']);
        self::assertNull((new EstimateNormResource(['quantity' => $resource->quantity]))->quantity);
    }

    public static function unavailableQuantities(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'malformed' => ['not-a-number'],
            'multiple decimal separators' => ['1,2,3'],
            'nan' => ['NaN'],
            'infinity' => ['INF'],
        ];
    }

    public function test_internal_array_keeps_resource_metadata_and_exposes_exact_string_quantity(): void
    {
        $resource = $this->parseResource('0.000001');

        self::assertSame([
            'code' => '1-100-20',
            'name' => 'Synthetic labor coefficient',
            'unit' => 'чел.-ч',
            'quantity' => '0.000001',
            'resource_type' => 'labor',
            'raw_data' => [
                'source_tag' => 'Worker',
                'attributes' => [
                    'Code' => '1-100-20',
                    'Name' => 'Synthetic labor coefficient',
                    'MeasureUnit' => 'чел.-ч',
                    'Quantity' => '0.000001',
                    'Type' => 'labor',
                ],
            ],
        ], $resource->toArray());
        self::assertSame('0.000001', json_decode(json_encode($resource->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['quantity']);
    }

    public function test_quantity_from_child_element_is_preserved_before_model_rounding(): void
    {
        $resource = $this->parseResource('123456789.123456', true);

        self::assertSame('123456789.123456', $resource->quantity);
        self::assertSame('123456789.123456', (new EstimateNormResource(['quantity' => $resource->quantity]))->quantity);
    }

    private function parseResource(?string $quantity, bool $childElement = false): FsnbNormResourceDTO
    {
        $path = tempnam(sys_get_temp_dir(), 'fsnb-decimal-');
        self::assertNotFalse($path);
        $value = $quantity !== null ? htmlspecialchars($quantity, ENT_XML1 | ENT_QUOTES, 'UTF-8') : null;
        $attribute = $value !== null && !$childElement ? ' Quantity="'.$value.'"' : '';
        $child = $value !== null && $childElement ? '<Quantity>'.$value.'</Quantity>' : '';
        $xml = '<?xml version="1.0" encoding="utf-8"?><base>'
            .'<Work Code="01-02-057-01" Name="Synthetic decimal boundary" MeasureUnit="100 м3">'
            .'<Worker Code="1-100-20" Name="Synthetic labor coefficient" MeasureUnit="чел.-ч"'.$attribute.' Type="labor">'
            .$child.'</Worker></Work></base>';

        try {
            self::assertNotFalse(file_put_contents($path, $xml));
            $norms = iterator_to_array((new FsnbXmlParser)->parse($path), false);
        } finally {
            unlink($path);
        }

        self::assertCount(1, $norms);
        self::assertCount(1, $norms[0]->resources);

        return $norms[0]->resources[0];
    }
}
