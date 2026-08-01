<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportCatalogActivationTest extends TestCase
{
    public function test_rejects_activation_with_a_binding_set_different_from_the_published_set(): void
    {
        $published = $this->codes('report');
        $bindings = $published;
        $bindings[27] = 'other_report';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_catalog_activation_invalid');

        new ReportCatalogActivation(
            'catalog_activated',
            str_repeat('1', 40),
            new Sha256Hash(str_repeat('2', 64)),
            new Sha256Hash(str_repeat('3', 64)),
            $published,
            $bindings,
            $this->hashes('4'),
            $this->hashes('5'),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    private function codes(string $prefix): array
    {
        return array_map(
            static fn (int $number): string => sprintf('%s_%02d', $prefix, $number),
            range(1, 28),
        );
    }

    private function hashes(string $prefix): array
    {
        return array_map(
            static fn (int $number): string => hash('sha256', $prefix.$number),
            range(1, 28),
        );
    }
}
