<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSourceWatermarkTest extends TestCase
{
    public function test_prefixed_sha256_checkpoint_is_preserved_in_full(): void
    {
        $watermark = 'checkpoint_'.str_repeat('a', 64);
        $reference = $this->reference($watermark);

        self::assertSame($watermark, $reference->watermark);
        self::assertSame(str_repeat('a', 64), $reference->hash->value);
    }

    public function test_watermark_accepts_at_most_128_safe_identifier_characters(): void
    {
        self::assertSame(128, strlen($this->reference('w_'.str_repeat('a', 126))->watermark));
        $this->expectException(InvalidArgumentException::class);
        $this->reference('w_'.str_repeat('a', 127));
    }

    #[DataProvider('unsafeWatermarks')]
    public function test_rejects_private_or_non_identifier_watermarks(string $watermark): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reference($watermark);
    }

    public static function unsafeWatermarks(): array
    {
        return [['https://example.com/private'], ['a@b'], ['sql_select'], ['query_customer'], ['secret_key'], ['token_abc'], ['with space'], ['a/b'], ['a-b']];
    }

    public function test_other_source_identifier_bounds_are_unchanged(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportSourceRef(str_repeat('a', 65), 'snapshot', 'snapshot_1', 'v1_0_0', 'event_0', 0, new Sha256Hash(str_repeat('a', 64)));
    }

    private function reference(string $watermark): ReportSourceRef
    {
        return new ReportSourceRef(
            'production_acceptance_history_boundary', 'immutable_checkpoint', 'organization_38',
            'production_acceptance_history_boundary_v1', $watermark, 0, new Sha256Hash(str_repeat('a', 64)),
        );
    }
}
