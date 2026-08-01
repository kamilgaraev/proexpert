<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use LogicException;
use ReflectionClass;

final class ReportPublicationDeliveryContractHasher
{
    public function hash(
        string $format,
        string $rendererClass,
        Sha256Hash $rendererHash,
        string $rendererVersion,
        Sha256Hash $schemaHash,
        Sha256Hash $fixtureHash,
        array $assertionCodes,
    ): Sha256Hash {
        if (! is_subclass_of($rendererClass, ReportExportRenderer::class)
            || ! hash_equals($format, $rendererClass::format())) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }
        $interfaceFile = (new ReflectionClass(ReportExportRenderer::class))->getFileName();
        if (! is_string($interfaceFile)) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }

        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'assertion_codes' => $assertionCodes,
            'fixture_sha256' => $fixtureHash->value,
            'format' => $format,
            'interface' => ReportExportRenderer::class,
            'interface_sha256' => (string) hash_file('sha256', $interfaceFile),
            'renderer_class' => $rendererClass,
            'renderer_sha256' => $rendererHash->value,
            'renderer_version' => $rendererVersion,
            'schema_sha256' => $schemaHash->value,
        ])));
    }
}
