<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class IntercompanyFlowMetricRow
{
    public function __construct(
        public string $flowClass,
        public int $amountMinor,
        public string $currency,
        public ?int $spreadMinor = null,
        public array $sourceRefs = [],
    ) {
        if (!in_array($flowClass, ['internal', 'external', 'unclassified'], true)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || !array_is_list($sourceRefs)) {
            throw new InvalidArgumentException('intercompany_flow_metric_row_invalid');
        }
    }

    public static function internal(int $amountMinor, string $currency, ?int $spreadMinor = null): self
    {
        return new self('internal', $amountMinor, $currency, $spreadMinor);
    }

    public static function external(int $amountMinor, string $currency, ?int $spreadMinor = null): self
    {
        return new self('external', $amountMinor, $currency, $spreadMinor);
    }

    public static function unclassified(int $amountMinor, string $currency, ?int $spreadMinor = null): self
    {
        return new self('unclassified', $amountMinor, $currency, $spreadMinor);
    }
}
