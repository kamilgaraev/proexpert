<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Services\NotificationContentFormatter;
use App\BusinessModules\Features\Notifications\Services\NotificationPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class NotificationContentFormatterTest extends TestCase
{
    use UsesNotificationTranslations;

    public function test_new_contract_event_has_readable_title_and_status_transition(): void
    {
        $payload = (new NotificationPayloadNormalizer)->normalize('contract_status_changed', [
            'contract' => ['number' => 'МОСТ-42'],
            'old_status' => 'draft',
            'new_status' => 'active',
        ], 'contracts');

        self::assertSame('Изменён статус договора № МОСТ-42', $payload['title']);
        self::assertSame('Статус изменён с «Черновик» на «Активен».', $payload['message']);
    }

    public function test_saved_technical_title_is_replaced_without_losing_event_data(): void
    {
        $data = [
            'title' => 'contract_status_changed',
            'message' => '',
            'contract' => ['id' => 42, 'number' => '42', 'url' => '/contracts/42'],
            'new_status' => 'completed',
        ];
        $formatter = new NotificationContentFormatter;
        $formatted = $formatter->format('contract_status_changed', $data);

        self::assertSame('Изменён статус договора № 42', $formatted['title']);
        self::assertSame('Новый статус: «Завершен».', $formatted['message']);
        self::assertSame($data['contract'], $formatted['contract']);
        self::assertSame($formatted, $formatter->format('contract_status_changed', $formatted));
        self::assertSame('contract_status_changed', $data['title']);
    }

    public function test_authored_content_and_action_are_preserved(): void
    {
        $data = [
            'title' => 'Договор согласован',
            'message' => 'Можно приступать к работам.',
            'action_url' => '/contracts/42',
            'new_status' => 'active',
        ];

        self::assertSame($data, (new NotificationContentFormatter)->format('contract_status_changed', $data));
    }

    public function test_unknown_event_does_not_expose_technical_keys(): void
    {
        $formatted = (new NotificationContentFormatter)->format('system.notice', [
            'title' => 'system.notice',
            'message' => 'notifications.internal.message',
        ]);

        self::assertSame('Новое уведомление', $formatted['title']);
        self::assertSame('', $formatted['message']);
    }

    public function test_invalid_contract_data_has_safe_readable_content(): void
    {
        $formatted = (new NotificationContentFormatter)->format('contract_status_changed', [
            'contract' => 'invalid',
            'new_status' => ['internal_status'],
        ]);

        self::assertSame('Изменён статус договора', $formatted['title']);
        self::assertSame('Проверьте актуальный статус в карточке договора.', $formatted['message']);
    }
}
