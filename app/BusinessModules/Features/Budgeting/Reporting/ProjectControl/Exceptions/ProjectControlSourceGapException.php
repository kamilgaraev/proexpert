<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Exceptions;

use InvalidArgumentException;

final class ProjectControlSourceGapException extends InvalidArgumentException
{
    public function __construct(
        public readonly array $gaps,
        public readonly string $watermark,
    ) {
        parent::__construct('project_control_source_incomplete');
    }
}
