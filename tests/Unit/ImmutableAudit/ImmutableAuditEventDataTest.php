<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditEventDataTest extends TestCase
{
    public function test_it_limits_a_long_subject_label_without_losing_the_full_audit_payload(): void
    {
        $label = str_repeat("\u{0414}\u{043e}\u{0433}\u{043e}\u{0432}\u{043e}\u{0440} ", 80);
        $event = $this->eventWithLabel($label);

        self::assertSame($label, $event->toArray()['after_state']['title']);
        self::assertSame("\u{2026}", mb_substr((string) $event->subjectLabelForStorage(), -1));
        self::assertLessThanOrEqual(255, mb_strlen((string) $event->subjectLabelForStorage()));
    }

    public function test_it_keeps_a_255_character_unicode_label_and_limits_only_the_256th_character(): void
    {
        $emoji = "\u{1F4C4}";
        $withinLimit = $this->eventWithLabel(str_repeat($emoji, 255));
        $overLimit = $this->eventWithLabel(str_repeat($emoji, 256));

        self::assertSame(str_repeat($emoji, 255), $withinLimit->subjectLabelForStorage());
        self::assertSame(str_repeat($emoji, 254)."\u{2026}", $overLimit->subjectLabelForStorage());
        self::assertSame(255, mb_strlen((string) $overLimit->subjectLabelForStorage()));
    }

    private function eventWithLabel(string $label): ImmutableAuditEventData
    {
        return new ImmutableAuditEventData(
            organizationId: 1,
            domain: 'legal_archive',
            eventType: 'legal_document.create_pending',
            action: 'create_pending',
            source: 'legal_archive',
            subjectLabel: $label,
            afterState: ['title' => $label],
        );
    }
}
