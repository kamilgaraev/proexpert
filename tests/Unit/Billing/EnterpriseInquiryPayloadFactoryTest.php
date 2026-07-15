<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\DTOs\Billing\EnterpriseInquiryData;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\EnterpriseInquiryPayloadFactory;
use PHPUnit\Framework\TestCase;

class EnterpriseInquiryPayloadFactoryTest extends TestCase
{
    public function test_it_builds_a_high_priority_filament_support_request(): void
    {
        $user = new User(['name' => 'Анна Смирнова', 'email' => 'anna@example.test']);
        $user->id = 41;
        $organization = new Organization(['name' => 'Строй Групп']);
        $organization->id = 17;
        $data = new EnterpriseInquiryData(
            clientRequestId: '74a1e8c8-103a-4c1d-a025-0f054fe51be6',
            contactPhone: '+7 999 123-45-67',
            companySize: '201_500',
            preferredContact: 'phone',
            needs: ['multi_organization', 'integrations', 'implementation'],
            comment: 'Нужно запустить пять филиалов до осени.',
        );

        $payload = (new EnterpriseInquiryPayloadFactory)->make($user, $organization, $data);

        $this->assertSame(ContactForm::CHANNEL_CUSTOMER_PORTAL, $payload['channel']);
        $this->assertSame(ContactForm::PRIORITY_HIGH, $payload['priority']);
        $this->assertSame('lk-enterprise-inquiry', $payload['page_source']);
        $this->assertSame('Корпоративное подключение МОСТ', $payload['subject']);
        $this->assertSame('Строй Групп', $payload['company']);
        $this->assertSame('+7 999 123-45-67', $payload['phone']);
        $this->assertStringContainsString('201–500 сотрудников', $payload['message']);
        $this->assertStringContainsString('Несколько организаций и филиалов', $payload['message']);
        $this->assertStringContainsString('Нужно запустить пять филиалов до осени.', $payload['message']);
        $this->assertSame('74a1e8c8-103a-4c1d-a025-0f054fe51be6', $payload['telegram_data']['client_request_id']);
    }
}
