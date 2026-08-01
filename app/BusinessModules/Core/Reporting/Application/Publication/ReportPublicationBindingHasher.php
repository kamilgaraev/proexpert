<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use ReflectionClass;

final class ReportPublicationBindingHasher
{
    public function hash(
        ReportDefinitionBinding $binding,
        ReportDefinitionConformanceEvidence $evidence,
    ): Sha256Hash {
        $components = [
            'data_provider' => $binding->dataProvider::class,
            'drill_down_provider' => $binding->drillDownProvider::class,
            'readiness_probe' => $binding->readinessProbe === null
                ? null
                : $binding->readinessProbe::class,
            'row_query' => $binding->rowQuery::class,
        ];
        $descriptor = [];
        foreach ($components as $role => $class) {
            if ($class === null) {
                $descriptor[] = ['role' => $role, 'class' => null, 'sha256' => null];

                continue;
            }
            $expected = $evidence->componentClassHashes[$class] ?? null;
            $file = (new ReflectionClass($class))->getFileName();
            if (! $expected instanceof Sha256Hash
                || ! is_string($file)
                || ! is_file($file)
                || ! hash_equals($expected->value, (string) hash_file('sha256', $file))) {
                throw new InvalidArgumentException('report_publication_binding_component_mismatch');
            }
            $descriptor[] = [
                'role' => $role,
                'class' => $class,
                'sha256' => $expected->value,
            ];
        }

        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'code' => $binding->code,
            'components' => $descriptor,
            'contract_version' => $binding->contractVersion,
        ])));
    }
}
