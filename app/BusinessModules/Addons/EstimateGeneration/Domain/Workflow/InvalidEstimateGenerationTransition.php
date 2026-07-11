<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use DomainException;

final class InvalidEstimateGenerationTransition extends DomainException
{
    public function __construct(EstimateGenerationStatus $status, EstimateGenerationEvent $event)
    {
        parent::__construct(sprintf(
            'Event "%s" is not allowed for estimate generation status "%s".',
            $event->value,
            $status->value,
        ));
    }
}
