<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\QuantityFormulaInputsPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuantityFormulaInputsPresenterTest extends TestCase
{
    #[Test]
    public function it_projects_only_typed_exact_provenance_and_drops_private_or_arbitrary_fields(): void
    {
        $payload = (new QuantityFormulaInputsPresenter)->present(['items' => [[
            'identity' => str_repeat('a', 64),
            'amount' => '12.500000',
            'source' => 'evidenced',
            'evidence_ids' => ['11'],
            'assumptions' => [],
            'contexts' => ['room:1'],
            'provenance_versions' => ['building-model:v1'],
            'locator' => ['s3_key' => 'private'],
            'named_operands' => [
                'area' => [
                    'role' => 'area',
                    'value' => '12.500000',
                    'unit' => 'm2',
                    'source' => 'evidenced',
                    'evidence_ids' => ['11'],
                    'assumptions' => [],
                    'context_id' => 'room:1',
                    'provenance_version' => 'building-model:v1',
                    'source_ref' => 'private',
                    'prompt' => 'private',
                ],
                'arbitrary' => [
                    'role' => 'arbitrary',
                    'value' => '99.000000',
                    'unit' => 'm2',
                    'evidence_ids' => ['11'],
                    'context_id' => 'room:1',
                    'provenance_version' => 'building-model:v1',
                ],
                'prompt' => [
                    'role' => 'prompt',
                    'value' => '88.000000',
                    'unit' => 'm2',
                    'evidence_ids' => ['11'],
                    'context_id' => 'room:1',
                    'provenance_version' => 'building-model:v1',
                ],
            ],
        ]]]);

        self::assertSame([
            'items' => [[
                'identity' => str_repeat('a', 64),
                'amount' => '12.500000',
                'evidence_ids' => [11],
                'provenance_versions' => ['building-model:v1'],
                'operands' => [[
                    'name' => 'area',
                    'value' => '12.500000',
                    'unit' => 'm2',
                    'evidence_ids' => [11],
                    'context_id' => 'room:1',
                    'provenance_version' => 'building-model:v1',
                ]],
            ]],
        ], $payload);
        self::assertStringNotContainsString('private', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('arbitrary', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_omits_malformed_items_and_never_coerces_floats(): void
    {
        $payload = (new QuantityFormulaInputsPresenter)->present(['items' => [
            ['identity' => str_repeat('b', 64), 'amount' => 12.5, 'evidence_ids' => [], 'provenance_versions' => [], 'named_operands' => []],
            ['identity' => 'invalid', 'amount' => '1', 'evidence_ids' => [], 'provenance_versions' => [], 'named_operands' => []],
        ]]);

        self::assertSame(['items' => []], $payload);
    }
}
