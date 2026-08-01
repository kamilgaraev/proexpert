<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class R15EvidenceIdentity
{
    /** @param array<string,mixed> $artifacts */
    public static function fromArtifacts(array $artifacts): string
    {
        return hash('sha256', CanonicalJson::encode($artifacts));
    }
}
