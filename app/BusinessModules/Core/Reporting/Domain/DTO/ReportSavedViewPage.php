<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class ReportSavedViewPage
{
    public function __construct(public array $items, public ?string $nextCursor, public int $limit, public bool $hasMore) {}
}
