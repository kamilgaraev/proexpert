<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\PublishedReportDefinitionCatalog;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use LogicException;

final readonly class CompositePublishedReportDefinitionRegistry implements PublishedReportDefinitionCatalog
{
    public function __construct(private ReportDefinitionRegistry $builtins, private ReportDefinitionRegistry $database) {}

    public function published(string $code): PublishedReportDefinition
    {
        try {
            $builtin = $this->builtins->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_NOT_FOUND) {
                throw $exception;
            }

            return $this->database->published($code);
        }

        try {
            $this->database->published($code);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode === ReportErrorCode::REPORT_NOT_FOUND) {
                return $builtin;
            }

            throw $exception;
        }

        throw new LogicException('report_published_definition_conflict');
    }

    public function publishedCodes(): array
    {
        $builtinCodes = $this->builtins->publishedCodes();
        $databaseCodes = $this->database->publishedCodes();
        if (array_intersect($builtinCodes, $databaseCodes) !== []) {
            throw new LogicException('report_published_definition_conflict');
        }

        return [...$builtinCodes, ...$databaseCodes];
    }

    public function publishedDefinitions(): array
    {
        $builtinCodes = $this->builtins->publishedCodes();
        $definitions = [];
        foreach ([$this->builtins, $this->database] as $registry) {
            if ($registry === $this->builtins && ! $this->database instanceof PublishedReportDefinitionCatalog) {
                $published = [];
                foreach ($builtinCodes as $code) { $published[$code] = $this->published($code); }
            } else {
                $published = $registry instanceof PublishedReportDefinitionCatalog ? $registry->publishedDefinitions() : [];
                if (! $registry instanceof PublishedReportDefinitionCatalog) {
                    foreach ($registry === $this->builtins ? $builtinCodes : $registry->publishedCodes() as $code) { $published[$code] = $registry->published($code); }
                }
            }
            if ($registry === $this->database && array_intersect($builtinCodes, array_keys($published)) !== []) {
                throw new LogicException('report_published_definition_conflict');
            }
            $definitions = [...$definitions, ...$published];
        }

        return $definitions;
    }

    public function manifestSha256(): Sha256Hash
    {
        $codes = $this->publishedCodes();
        $definitions = $codes !== [] && $this->database instanceof PublishedReportDefinitionCatalog
            ? $this->publishedDefinitions() : null;
        $entries = [];
        foreach ($codes as $code) {
            $published = $definitions === null ? $this->published($code)
                : ($definitions[$code] ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND));
            $entries[] = ['code' => $code, 'definition_sha256' => $published->definitionHash->value];
        }

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($entries)));
    }
}
