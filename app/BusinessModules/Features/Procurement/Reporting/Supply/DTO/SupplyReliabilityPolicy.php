<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\DTO;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class SupplyReliabilityPolicy
{
    /** @param list<string> $postSendCancellationExclusionReasons */
    public function __construct(
        public string $quantityTolerance = '0.000',
        public int $onTimeCutoffSeconds = 0,
        public bool $excludeCancellationBeforeSend = true,
        public array $postSendCancellationExclusionReasons = [],
        public int $maturitySeconds = 0,
    ) {
        if (BigDecimal::of($quantityTolerance)->isNegative()
            || $onTimeCutoffSeconds < 0
            || $maturitySeconds < 0
            || ! array_is_list($postSendCancellationExclusionReasons)) {
            throw new InvalidArgumentException('Supply reliability policy is invalid.');
        }
        $seen = [];
        foreach ($postSendCancellationExclusionReasons as $reason) {
            if (! is_string($reason) || trim($reason) === '' || isset($seen[$reason])) {
                throw new InvalidArgumentException('Supply reliability exclusion reasons are invalid.');
            }
            $seen[$reason] = true;
        }
    }
}
