<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;

final readonly class ReportQuery
{
    public string $canonicalJson;

    public Sha256Hash $queryHash;

    public ReportQueryIdentity $identity;

    public function __construct(
        public ReportDefinition $definition,
        public ReportScope $scope,
        public ReportFilterSet $filters,
        public array $comparison,
        public DateTimeImmutable $asOf,
        public string $locale,
    ) {
        $this->identity = ReportQueryIdentity::fromQuery($this);
        $this->canonicalJson = CanonicalJson::encode($this->identity->projection);
        $this->queryHash = $this->identity->hash;
    }
}
