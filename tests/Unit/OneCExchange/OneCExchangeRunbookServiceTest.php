<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\OneCExchangeRunbookService;
use Tests\TestCase;

final class OneCExchangeRunbookServiceTest extends TestCase
{
    public function test_runbook_contains_required_operational_scenarios(): void
    {
        $items = app(OneCExchangeRunbookService::class)->items();
        $keys = array_column($items, 'key');

        self::assertSame([
            'transport_unavailable',
            'dead_letter',
            'requires_mapping',
            'stale_processing',
            'overdue_retry',
            'business_validation_rejected',
            'delivery_unconfigured',
        ], $keys);

        foreach ($items as $item) {
            self::assertNotEmpty($item['title']);
            self::assertContains($item['severity'], ['critical', 'warning']);
            self::assertNotEmpty($item['signals']);
            self::assertNotEmpty($item['prohelper_checks']);
            self::assertNotEmpty($item['handoff_to_1c']);
            self::assertIsString($item['retry_allowed']);
            self::assertIsString($item['manual_review']);
            self::assertIsString($item['escalate_when']);

            $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString("\u{0420}\u{045F}", $encoded);
            self::assertStringNotContainsString("\u{0420}\u{045C}", $encoded);
        }
    }
}
