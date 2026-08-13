<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions;

final class VisionResponseTruncatedException extends VisionContractException
{
    public function __construct(public readonly string $finishReason)
    {
        parent::__construct('vision_response_truncated');
    }
}
