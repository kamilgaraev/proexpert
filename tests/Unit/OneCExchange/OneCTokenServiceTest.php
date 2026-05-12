<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Models\Organization;
use App\Services\OneCExchange\OneCTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OneCTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_token_returns_plaintext_once_and_stores_hash(): void
    {
        $organization = Organization::factory()->create();
        $service = app(OneCTokenService::class);

        $result = $service->createToken((int) $organization->id, 'Основная база');

        self::assertStringStartsWith('ph_1c_', $result['plain_token']);
        self::assertSame('Основная база', $result['token']->label);
        self::assertNotSame($result['plain_token'], $result['token']->token_hash);
        self::assertNotNull($service->validateToken($result['plain_token']));
    }
}
