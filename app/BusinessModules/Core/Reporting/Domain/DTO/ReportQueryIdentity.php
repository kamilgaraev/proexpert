<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportQueryIdentity
{
    public Sha256Hash $hash;

    public function __construct(public array $projection)
    {
        $keys = array_keys($projection);
        sort($keys, SORT_STRING);
        if ($keys !== ['as_of', 'comparison', 'definition_hash', 'filters', 'locale', 'scope']) {
            throw new InvalidArgumentException('report_query_identity_invalid');
        }

        $this->hash = new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
    }

    public static function fromQuery(ReportQuery $query): self
    {
        return new self([
            'as_of' => $query->asOf->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
            'comparison' => $query->comparison,
            'definition_hash' => $query->definition->definitionHash->value,
            'filters' => $query->filters->values,
            'locale' => $query->locale,
            'scope' => $query->scope->canonicalIdentity(),
        ]);
    }
}
