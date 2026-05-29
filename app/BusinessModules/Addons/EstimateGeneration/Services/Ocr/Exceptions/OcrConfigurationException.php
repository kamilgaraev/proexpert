<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions;

use RuntimeException;

class OcrConfigurationException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey = 'estimate_generation.ocr_not_configured',
    ) {
        parent::__construct($messageKey);
    }
}
