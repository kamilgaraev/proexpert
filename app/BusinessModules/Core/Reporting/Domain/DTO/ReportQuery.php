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

    public function __construct(
        public ReportDefinition $definition,
        public ReportScope $scope,
        public ReportFilterSet $filters,
        public array $comparison,
        public DateTimeImmutable $asOf,
        public string $locale,
    ) {
        $this->canonicalJson = CanonicalJson::encode([
            'as_of' => $asOf->format(DATE_ATOM),
            'comparison' => $comparison,
            'definition_hash' => $definition->definitionHash->value,
            'filters' => $filters->values,
            'locale' => $locale,
            'scope' => $scope->canonicalIdentity(),
        ]);
        $this->queryHash = new Sha256Hash(hash('sha256', $this->canonicalJson));
    }
}
