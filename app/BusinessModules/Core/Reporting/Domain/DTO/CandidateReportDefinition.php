<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class CandidateReportDefinition
{
    public string $code;

    public Sha256Hash $definitionHash;

    public function __construct(public ReportDefinition $definition)
    {
        if ($definition->publicationReadiness !== ReportPublicationReadiness::CANDIDATE) {
            throw new InvalidArgumentException('candidate_definition_readiness_invalid');
        }

        $this->code = $definition->code;
        $this->definitionHash = $definition->definitionHash;
    }

    public function payload(): ReportDefinition
    {
        return $this->definition;
    }
}
