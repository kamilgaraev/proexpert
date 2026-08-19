<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

enum VisualArbitrationStatus: string
{
    case Accepted = 'accepted';
    case Candidate = 'candidate';
    case Conditional = 'conditional';
    case Unresolved = 'unresolved';
    case Ambiguous = 'ambiguous';
    case Rejected = 'rejected';

    public function precedence(): int
    {
        return match ($this) {
            self::Accepted => 0,
            self::Candidate => 1,
            self::Conditional => 2,
            self::Unresolved => 3,
            self::Ambiguous => 4,
            self::Rejected => 5,
        };
    }
}
