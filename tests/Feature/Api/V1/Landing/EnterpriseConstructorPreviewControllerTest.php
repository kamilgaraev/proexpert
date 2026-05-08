<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use Tests\TestCase;

class EnterpriseConstructorPreviewControllerTest extends TestCase
{
    public function test_preview_endpoint_returns_russian_fields_and_message(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/v1/landing/billing/enterprise-constructor/preview', [
            'users' => 250,
            'additional_organizations' => 1,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Стандартную конфигурацию можно оплатить с баланса организации.')
            ->assertJsonPath('data.price.total', 164000)
            ->assertJsonPath('data.limits.users', 250)
            ->assertJsonPath('data.limits.foremen', 100)
            ->assertJsonPath('data.limits.contractor_invitations', 500)
            ->assertJsonPath('data.limits.organizations', 2)
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.requires_implementation_project', false)
            ->assertJsonFragment(['name' => 'До 250 пользователей'])
            ->assertJsonFragment(['name' => 'Дополнительная организация']);
    }
}
