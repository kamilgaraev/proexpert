<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditRedactorTest extends TestCase
{
    public function test_it_redacts_sensitive_fields_and_returns_paths(): void
    {
        $result = (new ImmutableAuditRedactor())->redactWithPaths([
            'status' => 'approved',
            'card_number' => '4111111111111111',
            'nested' => [
                'access_token' => 'secret-token',
                'comment' => 'visible',
            ],
        ]);

        $this->assertSame('approved', $result['payload']['status']);
        $this->assertSame(ImmutableAuditRedactor::REDACTED, $result['payload']['card_number']);
        $this->assertSame(ImmutableAuditRedactor::REDACTED, $result['payload']['nested']['access_token']);
        $this->assertSame('visible', $result['payload']['nested']['comment']);
        $this->assertSame(['card_number', 'nested.access_token'], $result['sensitive_fields']);
    }

    public function test_it_redacts_sensitive_values_without_sensitive_keys(): void
    {
        $redacted = (new ImmutableAuditRedactor())->redact([
            'header' => 'Bearer '.str_repeat('a', 64),
            'contact' => 'security@example.test',
            'document' => 'PO-15',
        ]);

        $this->assertSame(ImmutableAuditRedactor::REDACTED, $redacted['header']);
        $this->assertSame(ImmutableAuditRedactor::REDACTED, $redacted['contact']);
        $this->assertSame('PO-15', $redacted['document']);
    }
}
