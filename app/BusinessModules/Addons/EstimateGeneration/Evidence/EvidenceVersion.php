<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceVersion
{
    public function __construct(public string $value)
    {
        if (preg_match('/^(?:sha256:[a-f0-9]{64}|manifest:[a-f0-9]{64}|(?:extractor|model|pipeline|semver):v[0-9]+(?:\.[0-9]+){0,3}|(?:catalog|price):(?:[1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|(?:contract|test):[a-f0-9]{6,32}|fsnb:[0-9]{4}(?:\.[0-9]+)?|fgiscs:[0-9]{4}-(?:0[1-9]|1[0-2]))$/D', $value) !== 1 || strlen($value) > 80) {
            throw new InvalidArgumentException('Evidence version is invalid.');
        }
    }
}
