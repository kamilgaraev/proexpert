<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Services\Activity\ActivityEventRedactor;
use PHPUnit\Framework\TestCase;

class ActivityEventRedactorTest extends TestCase
{
    public function test_it_hides_sensitive_fields_recursively(): void
    {
        $redacted = (new ActivityEventRedactor())->redact([
            'status' => 'approved',
            'password' => 'secret',
            'meta' => [
                'access_token' => 'token-value',
                'comment' => 'visible',
            ],
        ]);

        $this->assertSame('approved', $redacted['status']);
        $this->assertSame('[скрыто]', $redacted['password']);
        $this->assertSame('[скрыто]', $redacted['meta']['access_token']);
        $this->assertSame('visible', $redacted['meta']['comment']);
    }

    public function test_it_hides_token_like_values(): void
    {
        $redacted = (new ActivityEventRedactor())->redact([
            'header' => 'Bearer ' . str_repeat('a', 64),
            'document' => 'PO-15',
        ]);

        $this->assertSame('[скрыто]', $redacted['header']);
        $this->assertSame('PO-15', $redacted['document']);
    }
}
