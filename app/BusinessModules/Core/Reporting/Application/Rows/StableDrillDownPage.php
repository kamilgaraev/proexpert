<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Rows;

use InvalidArgumentException;

final readonly class StableDrillDownPage
{
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
    ) {}

    public static function fromRows(array $rows, ?string $afterRowKey, int $limit): self
    {
        if ($limit < 1 || $limit > 100 || ! array_is_list($rows)) {
            throw new InvalidArgumentException('report_drill_down_page_invalid');
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp(
                (string) ($left['row_key'] ?? ''),
                (string) ($right['row_key'] ?? ''),
            ),
        );
        if ($afterRowKey !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => is_string($row['row_key'] ?? null)
                    && strcmp($row['row_key'], $afterRowKey) > 0,
            ));
        }

        $page = array_slice($rows, 0, $limit);
        $hasMore = count($rows) > $limit;
        $last = $page === [] ? null : $page[array_key_last($page)]['row_key'];

        return new self(
            $page,
            $hasMore && is_string($last) ? $last : null,
        );
    }
}
