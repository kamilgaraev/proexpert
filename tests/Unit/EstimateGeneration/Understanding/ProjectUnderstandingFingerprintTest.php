<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectUnderstandingFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectUnderstandingFingerprintTest extends TestCase
{
    public function test_nested_dtos_key_order_and_number_notation_survive_jsonb_round_trip(): void
    {
        $payload = ['links' => [], 'conflicts' => [(object) ['reason' => 'размер', 'facts' => [
            (object) ['value' => 1.0, 'nativeNumericProofs' => (object) ['unit' => 'm2', 'value' => '1.00']],
        ]]], 'questions' => [], 'limitations' => [], 'provider_calls' => 0];
        $encoded = ProjectUnderstandingFingerprint::encode($payload);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $reordered = array_reverse($decoded, true);
        $reordered['conflicts'][0] = array_reverse($reordered['conflicts'][0], true);
        $reordered['conflicts'][0]['facts'][0]['value'] = 1.0;
        $input = str_repeat('a', 64);
        self::assertSame(ProjectUnderstandingFingerprint::hash($input, $decoded), ProjectUnderstandingFingerprint::hash($input, $reordered));
        $reordered['conflicts'][0]['facts'][0]['value'] = 2;
        self::assertNotSame(ProjectUnderstandingFingerprint::hash($input, $decoded), ProjectUnderstandingFingerprint::hash($input, $reordered));
    }

    public function test_list_order_and_decimal_strings_remain_material(): void
    {
        $left = json_decode(ProjectUnderstandingFingerprint::encode(['questions' => ['first', 'second'], 'quantity' => '1.00']), true, 512, JSON_THROW_ON_ERROR);
        $right = $left;
        $right['questions'] = array_reverse($right['questions']);
        self::assertNotSame(ProjectUnderstandingFingerprint::hash('input', $left), ProjectUnderstandingFingerprint::hash('input', $right));
        $right = $left;
        $right['quantity'] = '1.0';
        self::assertNotSame(ProjectUnderstandingFingerprint::hash('input', $left), ProjectUnderstandingFingerprint::hash('input', $right));
    }

    public function test_legacy_contract_is_not_rehashed_as_a_new_signature(): void
    {
        $payload = ['links' => [], 'provider_calls' => 1.0];
        $old = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        self::assertSame(hash('sha256', "input\0".$old), ProjectUnderstandingFingerprint::hash('input', $payload));
        self::assertArrayNotHasKey('result_contract', $payload);
    }

    public function test_an_unknown_signature_contract_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProjectUnderstandingFingerprint::hash('input', ['result_contract' => 'unknown']);
    }
}
