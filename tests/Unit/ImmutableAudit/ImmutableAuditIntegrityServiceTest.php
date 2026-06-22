<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditIntegrityServiceTest extends TestCase
{
    public function test_canonical_json_is_stable_for_associative_key_order(): void
    {
        $service = new ImmutableAuditIntegrityService();

        $left = $service->canonicalJson([
            'b' => 2,
            'a' => [
                'd' => 4,
                'c' => 3,
            ],
        ]);
        $right = $service->canonicalJson([
            'a' => [
                'c' => 3,
                'd' => 4,
            ],
            'b' => 2,
        ]);

        $this->assertSame($left, $right);
    }

    public function test_record_hash_depends_on_previous_hash(): void
    {
        $service = new ImmutableAuditIntegrityService();
        $attributes = [
            'sequence_id' => 10,
            'chain_scope' => 'organization:1',
            'chain_version' => 1,
        ];

        $payloadHash = hash('sha256', 'payload');

        $first = $service->recordHash($attributes, $payloadHash, null);
        $second = $service->recordHash($attributes, $payloadHash, str_repeat('a', 64));

        $this->assertNotSame($first, $second);
    }
}
