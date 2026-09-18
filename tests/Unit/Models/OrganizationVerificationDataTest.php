<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Organization;
use PHPUnit\Framework\TestCase;

final class OrganizationVerificationDataTest extends TestCase
{
    public function test_reads_canonical_verification_results(): void
    {
        $data = ['score' => 100, 'inn_verification' => ['success' => true]];
        $organization = new Organization();
        $organization->setRawAttributes(['verification_data' => json_encode($data, JSON_THROW_ON_ERROR)]);

        self::assertSame($data, $organization->verification_data);
        self::assertSame(100, $organization->verification_score);
    }

    public function test_reads_historical_double_encoded_results(): void
    {
        $data = ['score' => 70, 'inn_verification' => ['success' => true], 'address_verification' => ['success' => false]];
        $organization = new Organization();
        $organization->setRawAttributes(['verification_data' => json_encode(json_encode($data, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)]);

        self::assertSame($data, $organization->verification_data);
        self::assertSame(70, $organization->verification_score);
    }

    public function test_writes_results_as_a_json_object_without_double_encoding(): void
    {
        $data = ['score' => 100, 'errors' => [], 'warnings' => []];
        $organization = new Organization();
        $organization->verification_data = $data;

        self::assertSame($data, json_decode($organization->getAttributes()['verification_data'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($data, $organization->verification_data);
    }

    public function test_invalid_or_scalar_results_are_not_treated_as_verification_evidence(): void
    {
        foreach ([null, '', '{broken', 'true', '100', '"verified"'] as $raw) {
            $organization = new Organization();
            $organization->setRawAttributes(['verification_data' => $raw]);

            self::assertNull($organization->verification_data);
        }
    }

    public function test_does_not_recursively_decode_arbitrarily_nested_strings(): void
    {
        $raw = json_encode(json_encode(json_encode(['score' => 100], JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
        $organization = new Organization();
        $organization->setRawAttributes(['verification_data' => $raw]);

        self::assertNull($organization->verification_data);
    }
}
