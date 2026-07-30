<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Support\Reporting\DeterministicObjectSpool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Waves23ModuleBootstrap;

final class DeterministicObjectSpoolTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/Support/Reporting/Waves23ModuleBootstrap.php';
        Waves23ModuleBootstrap::boot();
    }

    #[Test]
    public function large_cardinality_is_repeatable_and_hashes_the_exact_canonical_array(): void
    {
        $spool = new DeterministicObjectSpool;
        $expected = [];
        for ($id = 1; $id <= 10_000; $id++) {
            $identity = ['id' => $id, 'source_hash' => hash('sha256', (string) $id)];
            $expected[] = $identity;
            $spool->append((object) $identity, $identity);
        }

        self::assertSame(10_000, $spool->count());
        self::assertSame(
            hash('sha256', CanonicalJson::encode($expected)),
            $spool->sha256(),
        );
        self::assertSame(
            range(1, 10_000),
            array_map(
                static fn (object $row): int => (int) $row->id,
                iterator_to_array($spool->items()),
            ),
        );
        self::assertSame(10_000, iterator_count($spool->items()));
    }
}
