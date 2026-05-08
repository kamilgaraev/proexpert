<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\TestCase;

final class EnterpriseConstructorPreviewTest extends TestCase
{
    public function test_preview_endpoint_returns_russian_business_fields(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/v1/landing/billing/enterprise-constructor/preview', [
            'users' => 250,
            'additional_organizations' => 1,
            'priority_support' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan_name', 'Enterprise Конструктор')
            ->assertJsonPath('data.price.total', 189000)
            ->assertJsonPath('data.price_label', '189 000 ₽ в месяц')
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.requires_implementation_project', false)
            ->assertJsonPath('data.primary_cta', 'Рассчитать стоимость');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('Стандартную конфигурацию', $message);
        $this->assertStringNotContainsString('payload', $message);
        $this->assertStringNotContainsString('slug', $message);
    }
}
