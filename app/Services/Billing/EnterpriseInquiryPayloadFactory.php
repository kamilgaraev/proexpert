<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\EnterpriseInquiryData;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\User;

final class EnterpriseInquiryPayloadFactory
{
    private const COMPANY_SIZE_LABELS = [
        'up_to_50' => 'До 50 сотрудников',
        '51_200' => '51–200 сотрудников',
        '201_500' => '201–500 сотрудников',
        '501_1000' => '501–1000 сотрудников',
        '1000_plus' => 'Более 1000 сотрудников',
    ];

    private const CONTACT_LABELS = [
        'phone' => 'Позвонить',
        'email' => 'Написать на электронную почту',
        'messenger' => 'Связаться в мессенджере',
    ];

    private const NEED_LABELS = [
        'multi_organization' => 'Несколько организаций и филиалов',
        'integrations' => 'Интеграции с учётными системами',
        'access_control' => 'Расширенное управление доступами',
        'implementation' => 'Обучение и помощь с запуском',
        'personal_configuration' => 'Персональная настройка возможностей',
        'priority_support' => 'Приоритетная поддержка',
    ];

    /**
     * @return array<string, mixed>
     */
    public function make(User $user, Organization $organization, EnterpriseInquiryData $data): array
    {
        $needLabels = array_map(
            fn (string $need): string => self::NEED_LABELS[$need],
            $data->needs,
        );
        $message = [
            'Размер компании: '.self::COMPANY_SIZE_LABELS[$data->companySize],
            'Предпочтительный способ связи: '.self::CONTACT_LABELS[$data->preferredContact],
            'Что требуется:',
            ...array_map(static fn (string $need): string => '— '.$need, $needLabels),
        ];

        if ($data->comment !== null && $data->comment !== '') {
            $message[] = 'Комментарий:';
            $message[] = $data->comment;
        }

        return [
            'organization_id' => $organization->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $data->contactPhone,
            'company' => $organization->name,
            'company_role' => 'corporate_client',
            'company_size' => self::COMPANY_SIZE_LABELS[$data->companySize],
            'subject' => 'Корпоративное подключение МОСТ',
            'message' => implode("\n", $message),
            'consent_to_personal_data' => true,
            'consent_version' => 'lk-enterprise-v1',
            'page_source' => 'lk-enterprise-inquiry',
            'status' => ContactForm::STATUS_NEW,
            'priority' => ContactForm::PRIORITY_HIGH,
            'channel' => ContactForm::CHANNEL_CUSTOMER_PORTAL,
            'last_activity_at' => now(),
            'telegram_data' => [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'client_request_id' => $data->clientRequestId,
                'company_size' => $data->companySize,
                'preferred_contact' => $data->preferredContact,
                'needs' => $data->needs,
            ],
        ];
    }
}
